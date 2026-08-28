<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

final class DocumentService
{
    private const LIFECYCLE_SCOPES = ['active', 'archived', 'trashed'];

    /** @var array<string, mixed> */
    private const EMPTY_DOCUMENT = [
        'type' => 'doc',
        'content' => [['type' => 'paragraph']],
    ];

    public function __construct(
        private readonly DocumentRepository $repository,
        private readonly DocumentValidator $validator,
        private readonly PlainTextExtractor $plainTextExtractor,
        private readonly KnowledgeOccurrenceExtractor $occurrenceExtractor,
        private readonly KnowledgeRepository $knowledgeRepository,
    ) {
    }

    /**
     * Documents of one lifecycle scope. `archived` and `trashed` are never included implicitly.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $scope = 'active', ?array $contextIds = null): array
    {
        if (!in_array($scope, self::LIFECYCLE_SCOPES, true)) {
            throw new ApiException(422, 'invalid_request', 'Scope del lifecycle non supportato.');
        }
        return $this->repository->list($scope, $contextIds);
    }

    /** @return array<string, mixed> */
    public function archive(string $id): array
    {
        return $this->changeStatus($id, 'archived', ['active']);
    }

    /** @return array<string, mixed> */
    public function trash(string $id): array
    {
        return $this->changeStatus($id, 'trashed', ['active', 'archived']);
    }

    /** @return array<string, mixed> */
    public function restore(string $id): array
    {
        return $this->changeStatus($id, 'active', ['archived', 'trashed']);
    }

    /**
     * Lifecycle transitions are explicit and reversible: nothing is deleted and the state of the
     * KnowledgeOccurrence of the Document does not change.
     *
     * @param list<string> $allowedFrom
     * @return array<string, mixed>
     */
    private function changeStatus(string $id, string $status, array $allowedFrom): array
    {
        $this->validateId($id);
        $current = $this->repository->get($id);
        if (!in_array($current['status'], $allowedFrom, true)) {
            throw new ApiException(
                409,
                'invalid_document_transition',
                "Un Document {$current['status']} non può passare a {$status}.",
                ['status' => $current['status']],
            );
        }
        return $this->repository->setStatus($id, $status);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $this->validateId($id);
        return $this->repository->get($id);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input): array
    {
        $this->assertOnlyKeys($input, ['title', 'documentJson']);
        $title = $this->title($input['title'] ?? 'Documento senza titolo');
        $document = $this->document($input['documentJson'] ?? self::EMPTY_DOCUMENT);
        return $this->repository->create($title, $document, $this->plainTextExtractor->extract($document));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(string $id, array $input): array
    {
        $this->validateId($id);
        $this->assertOnlyKeys($input, ['baseRevision', 'title', 'documentJson', 'occurrenceCreates']);
        foreach (['baseRevision', 'title', 'documentJson'] as $required) {
            if (!array_key_exists($required, $input)) {
                throw new ApiException(422, 'invalid_request', "Campo obbligatorio mancante: {$required}.");
            }
        }
        if (!is_int($input['baseRevision']) || $input['baseRevision'] < 0) {
            throw new ApiException(422, 'invalid_request', 'baseRevision deve essere un intero non negativo.');
        }

        $document = $this->document($input['documentJson']);
        $creates = $input['occurrenceCreates'] ?? [];
        if (!is_array($creates) || !array_is_list($creates)) throw new ApiException(422, 'invalid_request', 'occurrenceCreates deve essere una lista.');
        $marks = $this->occurrenceExtractor->extract($document);
        return $this->repository->update(
            $id,
            $input['baseRevision'],
            $this->title($input['title']),
            $document,
            $this->plainTextExtractor->extract($document),
            fn (string $documentId) => $this->knowledgeRepository->reconcileOccurrences($documentId, $marks, $creates),
        );
    }

    /** @return array<string, mixed> */
    private function document(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new ApiException(422, 'invalid_document', 'documentJson deve essere un oggetto JSON.');
        }
        $this->validator->validate($value);
        return $value;
    }

    private function title(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ApiException(422, 'invalid_request', 'title deve essere una stringa.');
        }
        if (strlen($value) > 1000) {
            throw new ApiException(422, 'invalid_request', 'title supera il limite di 1000 byte.');
        }
        return $value;
    }

    private function validateId(string $id): void
    {
        if (!UuidV7::isValid($id)) {
            throw new ApiException(422, 'invalid_id', 'L’ID deve essere un UUIDv7 canonico lowercase.');
        }
    }

    /** @param array<string, mixed> $input @param list<string> $allowed */
    private function assertOnlyKeys(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new ApiException(422, 'invalid_request', "Campo non supportato: {$key}.");
            }
        }
    }
}

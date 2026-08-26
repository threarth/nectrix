<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

final class DocumentService
{
    /** @var array<string, mixed> */
    private const EMPTY_DOCUMENT = [
        'type' => 'doc',
        'content' => [['type' => 'paragraph']],
    ];

    public function __construct(
        private readonly DocumentRepository $repository,
        private readonly DocumentValidator $validator,
        private readonly PlainTextExtractor $plainTextExtractor,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->repository->list();
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
        $this->assertOnlyKeys($input, ['baseRevision', 'title', 'documentJson']);
        foreach (['baseRevision', 'title', 'documentJson'] as $required) {
            if (!array_key_exists($required, $input)) {
                throw new ApiException(422, 'invalid_request', "Campo obbligatorio mancante: {$required}.");
            }
        }
        if (!is_int($input['baseRevision']) || $input['baseRevision'] < 0) {
            throw new ApiException(422, 'invalid_request', 'baseRevision deve essere un intero non negativo.');
        }

        $document = $this->document($input['documentJson']);
        return $this->repository->update(
            $id,
            $input['baseRevision'],
            $this->title($input['title']),
            $document,
            $this->plainTextExtractor->extract($document),
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

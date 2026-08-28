<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

/**
 * Commands on the Context hierarchy. Cycles are refused, deletion of a node that still holds
 * children or Document is refused, and no KnowledgeObject is ever touched: a Context reaches them
 * only through the explicit path Context→Document→KnowledgeOccurrence.
 */
final class ContextService
{
    private const MAX_NAME_LENGTH = 200;
    private const MODES = ['exact', 'subtree'];

    public function __construct(
        private readonly ContextRepository $repository,
        private readonly DocumentRepository $documents,
        private readonly KnowledgeRepository $knowledge,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->repository->list();
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input): array
    {
        $this->assertOnlyKeys($input, ['name', 'parentId']);
        $parentId = $this->parent($input['parentId'] ?? null);
        return $this->repository->create($this->name($input['name'] ?? null), $parentId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function rename(string $contextId, array $input): array
    {
        $this->assertOnlyKeys($input, ['name']);
        $this->require($contextId);
        $this->repository->rename($contextId, $this->name($input['name'] ?? null));
        return $this->require($contextId);
    }

    /**
     * Moves a whole branch. The destination cannot be the Context itself nor one of its
     * descendants: that would detach the branch from the hierarchy into a cycle.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function move(string $contextId, array $input): array
    {
        $this->assertOnlyKeys($input, ['parentId']);
        $this->require($contextId);
        $parentId = $this->parent($input['parentId'] ?? null);
        if ($parentId !== null && in_array($parentId, $this->repository->subtreeIds($contextId), true)) {
            throw new ApiException(409, 'context_cycle', 'Un Context non può diventare figlio di se stesso o di un proprio discendente.');
        }
        $this->repository->move($contextId, $parentId);
        return $this->require($contextId);
    }

    /** Deletion is prudent: children and assigned Document must be reassigned explicitly first. */
    public function delete(string $contextId): void
    {
        $this->require($contextId);
        if ($this->repository->hasChildren($contextId)) {
            throw new ApiException(409, 'context_has_children', 'Il Context ha sub-context: riassegnali prima di eliminarlo.');
        }
        $documents = $this->repository->documentCount($contextId);
        if ($documents > 0) {
            throw new ApiException(409, 'context_has_documents', "Il Context è assegnato a {$documents} Document: riassegnali prima di eliminarlo.", [
                'documents' => $documents,
            ]);
        }
        $this->repository->delete($contextId);
    }

    /** @return list<array<string, mixed>> */
    public function breadcrumb(string $contextId): array
    {
        $this->require($contextId);
        return $this->repository->ancestors($contextId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function assignDocument(string $documentId, array $input): array
    {
        $this->assertOnlyKeys($input, ['contextId']);
        $document = $this->documents->get($documentId);
        if ($document['status'] !== 'active') {
            throw new ApiException(409, 'document_read_only', 'Un Document archiviato o nel cestino è in sola lettura.');
        }
        $contextId = $input['contextId'] ?? null;
        if ($contextId !== null) {
            $this->assertId((string) $contextId);
            $this->require((string) $contextId);
        }
        $this->repository->assignDocument($documentId, $contextId === null ? null : (string) $contextId);
        return $this->documents->get($documentId);
    }

    /**
     * Context IDs selected by a filter: only the Context itself, or the whole branch.
     *
     * @return list<string>
     */
    public function selectedIds(string $contextId, string $mode): array
    {
        $this->require($contextId);
        if (!in_array($mode, self::MODES, true)) {
            throw new ApiException(422, 'invalid_request', 'Modalità di filtro non supportata.');
        }
        return $mode === 'exact' ? [$contextId] : $this->repository->subtreeIds($contextId);
    }

    /** @return list<string> */
    public function documentIds(string $contextId, string $mode): array
    {
        return $this->repository->documentIds($this->selectedIds($contextId, $mode));
    }

    /** @return list<array<string, mixed>> */
    public function knowledgeObjects(string $contextId, string $mode): array
    {
        return $this->knowledge->objectsInDocuments($this->documentIds($contextId, $mode));
    }

    /** @return array<string, mixed> */
    private function require(string $contextId): array
    {
        $this->assertId($contextId);
        $context = $this->repository->find($contextId);
        if ($context === null) {
            throw new ApiException(404, 'context_not_found', 'Context non trovato.');
        }
        return $context;
    }

    private function parent(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ApiException(422, 'invalid_request', 'parentId deve essere un ID o null.');
        }
        $this->require($value);
        return $value;
    }

    private function name(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || Text::length(trim($value)) > self::MAX_NAME_LENGTH) {
            throw new ApiException(422, 'invalid_request', 'Il nome del Context è obbligatorio e non può superare i limiti.');
        }
        return trim($value);
    }

    private function assertId(string $id): void
    {
        if (!UuidV7::isValid($id)) {
            throw new ApiException(422, 'invalid_id', 'ID non valido.');
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

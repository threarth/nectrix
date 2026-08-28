<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

final class KnowledgeService
{
    private const MAX_RESOLVE_IDS = 200;

    public function __construct(private readonly KnowledgeRepository $repository) {}

    /** @return list<array<string, mixed>> */
    public function search(string $query): array
    {
        return $this->repository->search(substr(trim($query), 0, 200));
    }

    /** @return list<array<string, mixed>> */
    public function entityTypes(): array { return $this->repository->entityTypes(); }

    /**
     * Resolves a comma separated list of KnowledgeObject IDs. Missing IDs are simply absent from
     * the answer: the client uses it to drop pasted marks it cannot trust.
     *
     * @return list<array<string, mixed>>
     */
    public function resolveObjects(string $ids): array
    {
        $requested = array_values(array_filter(array_map('trim', explode(',', $ids)), static fn (string $id): bool => $id !== ''));
        if (count($requested) > self::MAX_RESOLVE_IDS) {
            throw new ApiException(422, 'invalid_request', 'Troppi ID KnowledgeObject in una sola richiesta.');
        }
        foreach ($requested as $id) {
            if (!UuidV7::isValid($id)) {
                throw new ApiException(422, 'invalid_id', 'ID KnowledgeObject non valido.');
            }
        }
        return $this->repository->resolveObjects(array_values(array_unique($requested)));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createEntityType(array $input): array
    {
        if (array_keys($input) !== ['name'] || !is_string($input['name']) || trim($input['name']) === '') {
            throw new ApiException(422, 'invalid_request', 'EntityType richiede solo un name non vuoto.');
        }
        return $this->repository->createEntityType(trim($input['name']));
    }
}

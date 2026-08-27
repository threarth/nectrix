<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

final class KnowledgeService
{
    public function __construct(private readonly KnowledgeRepository $repository) {}

    /** @return list<array<string, mixed>> */
    public function search(string $query): array
    {
        return $this->repository->search(substr(trim($query), 0, 200));
    }

    /** @return list<array<string, mixed>> */
    public function entityTypes(): array { return $this->repository->entityTypes(); }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createEntityType(array $input): array
    {
        if (array_keys($input) !== ['name'] || !is_string($input['name']) || trim($input['name']) === '') {
            throw new ApiException(422, 'invalid_request', 'EntityType richiede solo un name non vuoto.');
        }
        return $this->repository->createEntityType(trim($input['name']));
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

/**
 * Commands on the Context hierarchy. A Context organises fragments of text, not Document: it
 * reaches Concept and Entity through the containment of its own ranges, and a Document never owns
 * one. Cycles are refused and a node that still holds children is refused.
 */
final class ContextService
{
    private const MAX_NAME_LENGTH = 200;
    private const MODES = ['exact', 'subtree'];

    public function __construct(private readonly ContextRepository $repository) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->repository->list();
    }

    /**
     * The hierarchy together with the knowledge each node holds: sub-context and the Concept or
     * Entity whose fragments are contained in its own ranges.
     *
     * @return array<string, mixed>
     */
    public function tree(): array
    {
        return [
            'contexts' => $this->repository->list(),
            'objects' => $this->repository->knowledgeObjectsByContext(),
        ];
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

    /**
     * Deleting a Context is an ordinary command: it is one of the three tools that put order in the
     * chaos, so it must be reversible in the head of the user, not blocked forever. Sub-context are
     * still refused, because their meaning depends on the parent; the ranges are removed from the
     * text by the caller before the node disappears.
     */
    public function delete(string $contextId): void
    {
        $this->require($contextId);
        if ($this->repository->hasChildren($contextId)) {
            throw new ApiException(409, 'context_has_children', 'Il Context ha sub-context: eliminali o spostali prima.');
        }
        $this->repository->delete($contextId);
    }

    /** @return list<array<string, mixed>> */
    public function breadcrumb(string $contextId): array
    {
        $this->require($contextId);
        return $this->repository->ancestors($contextId);
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

    /**
     * Concept and Entity contained in the ranges of the Context. Being in the same Document is not
     * enough: the appunti are chaotic, only the containment declares a fact.
     *
     * @return list<array<string, mixed>>
     */
    public function knowledgeObjects(string $contextId, string $mode): array
    {
        return $this->repository->knowledgeObjects($this->selectedIds($contextId, $mode));
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

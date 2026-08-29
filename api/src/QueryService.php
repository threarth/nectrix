<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

/**
 * Combines the dimensions that select Document. The Tag classifies the container, the Context marks
 * fragments inside it: a Context selects a Document only because some marked range lives there.
 * The two intersect on the Document, and a KnowledgeObject is always reached through an occurrence.
 */
final class QueryService
{
    public function __construct(
        private readonly ContextService $contexts,
        private readonly TagService $tags,
        private readonly DocumentService $documents,
        private readonly KnowledgeRepository $knowledge,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function documents(string $scope, ?string $contextId, string $contextMode, string $tagIds): array
    {
        $byContext = $contextId === null || $contextId === ''
            ? null
            : $this->contexts->documentIds($contextId, $contextMode);
        return $this->documents->list($scope, $this->intersect($byContext, $this->tags->documentIds($tagIds)));
    }

    /**
     * Intersection of two Document selections, keeping null as «no filter on this dimension».
     *
     * @param list<string>|null $first
     * @param list<string>|null $second
     * @return list<string>|null
     */
    private function intersect(?array $first, ?array $second): ?array
    {
        if ($first === null) {
            return $second;
        }
        if ($second === null) {
            return $first;
        }
        return array_values(array_intersect($first, $second));
    }

    /**
     * Concept and Entity selected by the filters. With a Context the answer is the containment —
     * the fragments actually inside its ranges — with a Tag alone it is what lives in the selected
     * Document, because a Tag says nothing about a fragment.
     *
     * @return list<array<string, mixed>>
     */
    public function knowledgeObjects(string $scope, ?string $contextId, string $contextMode, string $tagIds): array
    {
        if ($contextId !== null && $contextId !== '') {
            return $this->contexts->knowledgeObjects($contextId, $contextMode);
        }
        $documents = $this->documents($scope, $contextId, $contextMode, $tagIds);
        return $this->knowledge->objectsInDocuments(array_column($documents, 'id'));
    }
}

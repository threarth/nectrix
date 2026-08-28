<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

/**
 * Combines the dimensions that select Document and, through them, KnowledgeObject.
 * Context and Tag are separate dimensions: they intersect on the Document, never on the
 * KnowledgeObject, which is always reached through an active KnowledgeOccurrence.
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
        $contextIds = $contextId === null || $contextId === ''
            ? null
            : $this->contexts->selectedIds($contextId, $contextMode);
        return $this->documents->list($scope, $contextIds, $this->tags->documentIds($tagIds));
    }

    /**
     * Concept and Entity of the Document selected by the filters. The same object present in more
     * than one selected Document is listed once, and nothing is assigned directly to it.
     *
     * @return list<array<string, mixed>>
     */
    public function knowledgeObjects(string $scope, ?string $contextId, string $contextMode, string $tagIds): array
    {
        $documents = $this->documents($scope, $contextId, $contextMode, $tagIds);
        return $this->knowledge->objectsInDocuments(array_column($documents, 'id'));
    }
}

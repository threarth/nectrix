<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

/**
 * Categorised search. Every result says which category it belongs to and how it matched:
 * `text` and `name` are string matching, `alias` and `identifier` reach a KnowledgeObject through
 * one of its declared names, `identity` matches through an active KnowledgeOccurrence and has
 * nothing to do with the words written in the Document.
 */
final class SearchService
{
    private const MIN_QUERY_LENGTH = 2;
    private const MAX_QUERY_LENGTH = 200;

    public function __construct(
        private readonly SearchRepository $repository,
        private readonly ContextRepository $contexts,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function search(string $query): array
    {
        $trimmed = trim($query);
        if (Text::length($trimmed) < self::MIN_QUERY_LENGTH) {
            throw new ApiException(422, 'query_too_short', 'La ricerca richiede almeno due caratteri.');
        }
        if (Text::length($trimmed) > self::MAX_QUERY_LENGTH) {
            throw new ApiException(422, 'query_too_long', 'La ricerca supera la lunghezza consentita.');
        }

        $like = '%' . $trimmed . '%';
        return ['query' => $trimmed, 'results' => [
            ...$this->documentResults($trimmed),
            ...$this->conceptResults($like),
            ...$this->entityResults($like),
            ...$this->entityTypeResults($like),
            ...$this->contextResults($like),
            ...$this->tagResults($like),
        ]];
    }

    /**
     * Document reached by identity: they contain an active occurrence of the given KnowledgeObject.
     *
     * @return array<string, mixed>
     */
    public function byObject(string $objectId): array
    {
        if (!UuidV7::isValid($objectId)) {
            throw new ApiException(422, 'invalid_id', 'ID non valido.');
        }
        $results = [];
        foreach ($this->repository->documentsByObject($objectId) as $row) {
            $results[] = [
                'category' => 'document',
                'match' => 'identity',
                'id' => $row['id'],
                'label' => $row['title'],
                'detail' => null,
                'status' => $row['status'],
                'documentId' => $row['id'],
                'occurrenceId' => $row['occurrence_id'],
                'objectId' => $objectId,
            ];
        }
        return ['objectId' => $objectId, 'results' => $results];
    }

    public function rebuildIndex(): void
    {
        $this->repository->rebuildIndex();
    }

    /**
     * FTS5 treats the query as an expression: quoting it makes the whole text a phrase and keeps
     * operators typed by the user out of the syntax.
     */
    private function documentResults(string $query): array
    {
        $expression = '"' . str_replace('"', '""', $query) . '"' . '*';
        $results = [];
        foreach ($this->repository->documents($expression) as $row) {
            $results[] = [
                'category' => 'document',
                'match' => 'full_text',
                'id' => $row['id'],
                'label' => $row['title'],
                'detail' => $row['snippet'],
                'status' => $row['status'],
                'documentId' => $row['id'],
            ];
        }
        return $results;
    }

    private function conceptResults(string $like): array
    {
        $results = [];
        foreach ($this->repository->concepts($like) as $row) {
            $results[] = $this->knowledgeResult('concept', 'name', $row);
        }
        foreach ($this->repository->conceptsByAlias($like) as $row) {
            $results[] = $this->knowledgeResult('concept', 'alias', $row);
        }
        return $results;
    }

    private function entityResults(string $like): array
    {
        $results = [];
        foreach ($this->repository->entities($like) as $row) {
            $results[] = $this->knowledgeResult('entity', 'name', $row);
        }
        foreach ($this->repository->entitiesByIdentifier($like) as $row) {
            $results[] = $this->knowledgeResult('entity', 'identifier', $row);
        }
        return $results;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function knowledgeResult(string $category, string $match, array $row): array
    {
        return [
            'category' => $category,
            'match' => $match,
            'id' => $row['id'],
            'label' => $row['name'],
            'detail' => $row['matched_text'] ?? ($row['entity_type_name'] ?? null),
            'status' => $row['status'],
            'objectId' => $row['id'],
            'objectType' => $category,
        ];
    }

    private function entityTypeResults(string $like): array
    {
        $results = [];
        foreach ($this->repository->entityTypes($like) as $row) {
            $results[] = [
                'category' => 'entity_type',
                'match' => 'name',
                'id' => $row['id'],
                'label' => $row['name'],
                'detail' => null,
                'status' => $row['status'],
            ];
        }
        return $results;
    }

    /** The path of a Context is derived from the hierarchy, so the result can show where it sits. */
    private function contextResults(string $like): array
    {
        $results = [];
        foreach ($this->repository->contexts($like) as $row) {
            $path = array_column($this->contexts->ancestors((string) $row['id']), 'name');
            $results[] = [
                'category' => 'context',
                'match' => 'name',
                'id' => $row['id'],
                'label' => $row['name'],
                'detail' => implode(' / ', $path),
                'contextId' => $row['id'],
            ];
        }
        return $results;
    }

    private function tagResults(string $like): array
    {
        $results = [];
        foreach ($this->repository->tags($like) as $row) {
            $results[] = [
                'category' => 'tag',
                'match' => 'name',
                'id' => $row['id'],
                'label' => $row['name'],
                'detail' => null,
                'tagId' => $row['id'],
            ];
        }
        return $results;
    }
}

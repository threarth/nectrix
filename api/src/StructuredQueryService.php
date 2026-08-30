<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

/**
 * Structured search over FieldValue, combinable with the editorial dimensions.
 * Every result declares the path that produced it, so a typed comparison is never confused with a
 * string match or with the identity of a Concept or an Entity.
 */
final class StructuredQueryService
{
    public function __construct(
        private readonly StructuredQueryRepository $repository,
        private readonly FieldFilterCompiler $compiler,
        private readonly QueryService $query,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function search(array $input): array
    {
        $filters = $input['filters'] ?? [];
        if (!is_array($filters) || !array_is_list($filters) || $filters === []) {
            throw new ApiException(422, 'invalid_request', 'La ricerca strutturata richiede almeno un filtro.');
        }

        $matches = [];
        $names = [];
        $types = [];
        $intersection = null;
        foreach ($filters as $filter) {
            [$field, $condition, $parameters, $operator] = $this->compiler->compile($filter);
            $rows = $this->repository->entitiesMatching($field, $condition, $parameters);
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (string) $row['id'];
                $names[(string) $row['id']] = (string) $row['name'];
                $types[(string) $row['id']] = (string) $row['entity_type_name'];
                $matches[(string) $row['id']][] = [
                    'path' => 'field_value',
                    'template' => $field['template_name'] ?? null,
                    'field' => $field['name'],
                    'fieldType' => $field['field_type'],
                    'operator' => $operator,
                ];
            }
            $intersection = $intersection === null ? $ids : array_values(array_intersect($intersection, $ids));
        }

        $entityIds = $intersection ?? [];
        $documents = $this->editorialDocuments($input, $entityIds);
        $entities = [];
        foreach ($entityIds as $entityId) {
            $own = $documents[$entityId] ?? [];
            if ($documents !== null && $this->hasEditorialFilter($input) && $own === []) {
                continue;
            }
            $entities[] = [
                'id' => $entityId,
                'name' => $names[$entityId] ?? '',
                'entityTypeName' => $types[$entityId] ?? null,
                'matches' => $matches[$entityId] ?? [],
                'documents' => $own,
            ];
        }

        return [
            'entities' => $entities,
            'counts' => ['entities' => count($entities), 'documents' => $this->countDocuments($entities)],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function fields(string $query): array
    {
        return $this->repository->templateFieldsMatching('%' . trim($query) . '%');
    }

    /**
     * Documents that link the Entity to the editorial content through an active occurrence,
     * restricted by the Context, Tag and full text filters when they are present.
     *
     * @param array<string, mixed> $input
     * @param list<string> $entityIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function editorialDocuments(array $input, array $entityIds): array
    {
        $allowed = null;
        if ($this->hasEditorialFilter($input)) {
            $allowed = array_column($this->query->documents(
                'active',
                isset($input['contextId']) ? (string) $input['contextId'] : null,
                (string) ($input['contextMode'] ?? 'subtree'),
                (string) ($input['tagIds'] ?? ''),
            ), 'id');
        }

        $documents = [];
        foreach ($this->repository->documentsOfEntities($entityIds) as $row) {
            if ($allowed !== null && !in_array($row['id'], $allowed, true)) {
                continue;
            }
            $documents[(string) $row['entity_id']][] = [
                'path' => 'occurrence',
                'id' => $row['id'],
                'title' => $row['title'],
            ];
        }
        return $documents;
    }

    /** @param array<string, mixed> $input */
    private function hasEditorialFilter(array $input): bool
    {
        return ($input['contextId'] ?? '') !== '' || ($input['tagIds'] ?? '') !== '';
    }

    /** @param list<array<string, mixed>> $entities */
    private function countDocuments(array $entities): int
    {
        $ids = [];
        foreach ($entities as $entity) {
            foreach ($entity['documents'] as $document) {
                $ids[$document['id']] = true;
            }
        }
        return count($ids);
    }
}

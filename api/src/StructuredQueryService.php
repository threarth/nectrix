<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

/**
 * Structured search over FieldValue, combinable with the editorial dimensions.
 * Every result declares the path that produced it, so a typed comparison is never confused with a
 * string match or with the identity of a Concept or an Entity.
 */
final class StructuredQueryService
{
    /** Operators admitted by each family of field types, on the column that family writes. */
    private const OPERATORS = [
        'text' => ['eq', 'contains'],
        'url' => ['eq', 'contains'],
        'enum' => ['eq', 'contains'],
        'multi_enum' => ['eq', 'contains'],
        'number' => ['eq', 'gt', 'gte', 'lt', 'lte'],
        'percentage' => ['eq', 'gt', 'gte', 'lt', 'lte'],
        'measurement' => ['eq', 'gt', 'gte', 'lt', 'lte'],
        'currency' => ['eq', 'gt', 'gte', 'lt', 'lte'],
        'boolean' => ['is_true', 'is_false'],
        'date' => ['eq', 'before', 'after'],
        'entity_reference' => ['eq'],
        'multi_entity_reference' => ['eq'],
        'concept_reference' => ['eq'],
        'multi_concept_reference' => ['eq'],
    ];

    private const COMPARISONS = ['eq' => '=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'before' => '<', 'after' => '>'];

    public function __construct(
        private readonly StructuredQueryRepository $repository,
        private readonly TemplateRepository $templates,
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
            [$field, $condition, $parameters, $operator] = $this->compile($filter);
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

    /**
     * @param mixed $filter
     * @return array{0: array<string, mixed>, 1: string, 2: array<string, mixed>, 3: string}
     */
    private function compile(mixed $filter): array
    {
        if (!is_array($filter)) {
            throw new ApiException(422, 'invalid_request', 'Filtro non valido.');
        }
        $fieldId = $filter['fieldId'] ?? null;
        if (!is_string($fieldId) || !UuidV7::isValid($fieldId)) {
            throw new ApiException(422, 'invalid_id', 'Il filtro richiede il campo su cui cercare.');
        }
        $field = $this->templates->findField($fieldId);
        if ($field === null) {
            throw new ApiException(404, 'template_field_not_found', 'Campo non trovato.');
        }
        $template = $this->templates->find((string) $field['template_id']);
        $field['template_name'] = $template['name'] ?? null;

        $type = (string) $field['field_type'];
        $operator = (string) ($filter['operator'] ?? 'eq');
        if (!in_array($operator, self::OPERATORS[$type] ?? [], true)) {
            throw new ApiException(422, 'invalid_operator', "L’operatore {$operator} non si applica a un campo {$type}.", [
                'allowed' => self::OPERATORS[$type] ?? [],
            ]);
        }

        return [$field, ...$this->condition($type, $operator, $filter['value'] ?? null), $operator];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function condition(string $type, string $operator, mixed $value): array
    {
        if ($operator === 'is_true' || $operator === 'is_false') {
            return ['v.boolean_value = :value', ['value' => $operator === 'is_true' ? 1 : 0]];
        }
        if (in_array($type, ['number', 'percentage', 'measurement', 'currency'], true)) {
            if (!is_int($value) && !is_float($value)) {
                throw new ApiException(422, 'invalid_field_value', 'Il confronto numerico richiede un numero.');
            }
            return ['v.number_value ' . self::COMPARISONS[$operator] . ' :value', ['value' => (float) $value]];
        }
        if ($type === 'date') {
            if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                throw new ApiException(422, 'invalid_field_value', 'Il confronto su data richiede il formato AAAA-MM-GG.');
            }
            return ['v.date_value ' . self::COMPARISONS[$operator] . ' :value', ['value' => $value]];
        }
        if (str_contains($type, 'reference')) {
            if (!is_string($value) || !UuidV7::isValid($value)) {
                throw new ApiException(422, 'invalid_field_value', 'Il confronto su riferimento richiede un ID.');
            }
            return ['(v.entity_reference_id = :value OR v.concept_reference_id = :value)', ['value' => $value]];
        }
        if (!is_string($value) || trim($value) === '') {
            throw new ApiException(422, 'invalid_field_value', 'Il confronto testuale richiede un valore.');
        }
        return $operator === 'contains'
            ? ['v.text_value LIKE :value', ['value' => '%' . trim($value) . '%']]
            : ['v.text_value = :value COLLATE NOCASE', ['value' => trim($value)]];
    }
}

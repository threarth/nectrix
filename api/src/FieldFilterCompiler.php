<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

/**
 * Compiles one filter over field_values into a typed SQL condition and its bound parameters.
 * Each field family is compared on the column that family writes: numbers, dates, booleans and
 * references never go through a generic cast to text. The condition is built only from fixed
 * fragments, the values are always bound.
 */
final class FieldFilterCompiler
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

    private const COMPARISONS = [
        'eq' => '=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'before' => '<', 'after' => '>',
    ];

    public function __construct(private readonly TemplateRepository $templates) {}

    /**
     * @param mixed $filter the filter as received from the client
     * @return array{0: array<string, mixed>, 1: string, 2: array<string, mixed>, 3: string}
     *         the TemplateField, the SQL condition, its parameters and the operator
     */
    public function compile(mixed $filter): array
    {
        if (!is_array($filter)) {
            throw new ApiException(422, 'invalid_request', 'Filtro non valido.');
        }
        $field = $this->field($filter['fieldId'] ?? null);

        $type = (string) $field['field_type'];
        $operator = (string) ($filter['operator'] ?? 'eq');
        if (!in_array($operator, self::OPERATORS[$type] ?? [], true)) {
            throw new ApiException(422, 'invalid_operator', "L’operatore {$operator} non si applica a un campo {$type}.", [
                'allowed' => self::OPERATORS[$type] ?? [],
            ]);
        }

        return [$field, ...$this->condition($type, $operator, $filter['value'] ?? null), $operator];
    }

    /** @return array<string, mixed> */
    private function field(mixed $fieldId): array
    {
        if (!is_string($fieldId) || !UuidV7::isValid($fieldId)) {
            throw new ApiException(422, 'invalid_id', 'Il filtro richiede il campo su cui cercare.');
        }
        $field = $this->templates->findField($fieldId);
        if ($field === null) {
            throw new ApiException(404, 'template_field_not_found', 'Campo non trovato.');
        }
        $template = $this->templates->find((string) $field['template_id']);
        $field['template_name'] = $template['name'] ?? null;
        return $field;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
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

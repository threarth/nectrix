<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

/**
 * Turns the value declared by the client into the typed columns of field_values. Every field type
 * writes its own column and leaves the others null, so numbers, dates and references are never
 * compared as generic text. Nothing here creates a Concept or an Entity: a reference must already
 * exist and is verified by the repository.
 */
final class FieldValueValidator
{
    private const MULTI_TYPES = ['multi_enum', 'multi_entity_reference', 'multi_concept_reference'];
    private const MAX_TEXT_LENGTH = 4000;
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';
    private const CURRENCY_PATTERN = '/^[A-Z]{3}$/';

    public function isMulti(string $fieldType): bool
    {
        return in_array($fieldType, self::MULTI_TYPES, true);
    }

    /**
     * Columns for one value of the given field type.
     *
     * @param array<string, mixed> $field the template field row
     * @return array<string, mixed>
     */
    public function columns(array $field, mixed $value): array
    {
        $type = (string) $field['field_type'];
        $empty = [
            'text_value' => null, 'rich_text_json' => null, 'number_value' => null,
            'boolean_value' => null, 'date_value' => null, 'unit' => null,
            'currency_code' => null, 'entity_reference_id' => null, 'concept_reference_id' => null,
        ];

        return match ($type) {
            'text', 'url' => [...$empty, 'text_value' => $this->text($value, $type)],
            'enum', 'multi_enum' => [...$empty, 'text_value' => $this->option($field, $value)],
            'rich_text' => [...$empty, 'rich_text_json' => $this->richText($value)],
            'number', 'percentage' => [...$empty, 'number_value' => $this->number($value, $type)],
            'measurement' => [...$empty, 'number_value' => $this->number($this->part($value, 'value'), $type), 'unit' => $this->text($this->part($value, 'unit'), 'unit')],
            'currency' => [...$empty, 'number_value' => $this->number($this->part($value, 'value'), $type), 'currency_code' => $this->currency($this->part($value, 'currency'))],
            'boolean' => [...$empty, 'boolean_value' => $this->boolean($value)],
            'date' => [...$empty, 'date_value' => $this->date($value)],
            'entity_reference', 'multi_entity_reference' => [...$empty, 'entity_reference_id' => $this->reference($value)],
            'concept_reference', 'multi_concept_reference' => [...$empty, 'concept_reference_id' => $this->reference($value)],
            'source_reference' => throw new ApiException(422, 'field_type_disabled', 'I riferimenti a Source arrivano con la FASE 16.'),
            default => throw new ApiException(422, 'invalid_field_type', "Tipo di campo non supportato: {$type}."),
        };
    }

    private function text(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || Text::length($value) > self::MAX_TEXT_LENGTH) {
            throw new ApiException(422, 'invalid_field_value', "Valore non valido per {$field}.");
        }
        return trim($value);
    }

    /** @param array<string, mixed> $field */
    private function option(array $field, mixed $value): string
    {
        $option = $this->text($value, 'enum');
        $options = $this->options($field);
        if ($options !== [] && !in_array($option, $options, true)) {
            throw new ApiException(422, 'invalid_field_value', 'Valore fuori dalle opzioni dichiarate dal campo.', [
                'options' => $options,
            ]);
        }
        return $option;
    }

    /** @param array<string, mixed> $field @return list<string> */
    public function options(array $field): array
    {
        $raw = $field['options_json'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function richText(mixed $value): string
    {
        if (!is_array($value)) {
            throw new ApiException(422, 'invalid_field_value', 'Un campo rich text richiede un documento JSON.');
        }
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw new ApiException(422, 'invalid_field_value', 'Il contenuto rich text non è serializzabile.');
        }
    }

    private function number(mixed $value, string $field): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new ApiException(422, 'invalid_field_value', "Il campo {$field} richiede un numero.");
        }
        return (float) $value;
    }

    private function boolean(mixed $value): int
    {
        if (!is_bool($value)) {
            throw new ApiException(422, 'invalid_field_value', 'Il campo boolean richiede true o false.');
        }
        return $value ? 1 : 0;
    }

    private function date(mixed $value): string
    {
        if (!is_string($value) || preg_match(self::DATE_PATTERN, $value) !== 1) {
            throw new ApiException(422, 'invalid_field_value', 'La data richiede il formato AAAA-MM-GG.');
        }
        return $value;
    }

    private function currency(mixed $value): string
    {
        if (!is_string($value) || preg_match(self::CURRENCY_PATTERN, $value) !== 1) {
            throw new ApiException(422, 'invalid_field_value', 'La valuta richiede un codice ISO di tre lettere maiuscole.');
        }
        return $value;
    }

    private function reference(mixed $value): string
    {
        if (!is_string($value) || !UuidV7::isValid($value)) {
            throw new ApiException(422, 'invalid_field_value', 'Un riferimento richiede l’ID di un oggetto esistente.');
        }
        return $value;
    }

    private function part(mixed $value, string $key): mixed
    {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            throw new ApiException(422, 'invalid_field_value', "Il valore richiede il campo {$key}.");
        }
        return $value[$key];
    }
}

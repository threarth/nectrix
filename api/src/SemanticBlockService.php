<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

/**
 * SemanticBlock of an Entity and their typed values. Writing a value never creates the Concept or
 * the Entity it points to, and never touches values the user wrote in another field: one write
 * replaces exactly one field of one block.
 */
final class SemanticBlockService
{
    public function __construct(
        private readonly SemanticBlockRepository $blocks,
        private readonly TemplateRepository $templates,
        private readonly FieldValueValidator $validator,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function blocksOf(string $entityId): array
    {
        $blocks = [];
        foreach ($this->blocks->blocksOfEntity($entityId) as $block) {
            $blocks[] = $this->present($block);
        }
        return $blocks;
    }

    /** @param array<string, mixed> $input @return list<array<string, mixed>> */
    public function addBlock(string $entityId, array $input): array
    {
        $this->assertOnlyKeys($input, ['templateId']);
        $templateId = (string) ($input['templateId'] ?? '');
        $this->assertId($templateId);
        if ($this->templates->find($templateId) === null) {
            throw new ApiException(404, 'template_not_found', 'Template non trovato.');
        }
        if (!$this->blocks->entityExists($entityId)) {
            throw new ApiException(404, 'knowledge_object_not_found', 'Entity non trovata.');
        }
        $this->blocks->create($entityId, $templateId);
        return $this->blocksOf($entityId);
    }

    /** @return list<array<string, mixed>> */
    public function deleteBlock(string $blockId): array
    {
        $block = $this->requireBlock($blockId);
        $this->blocks->delete($blockId);
        return $this->blocksOf((string) $block['entity_id']);
    }

    /**
     * Replaces the values of one field. A single field accepts one value, a multi field a list;
     * a required field cannot be left empty.
     *
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    public function setValues(string $blockId, array $input): array
    {
        $this->assertOnlyKeys($input, ['fieldId', 'values']);
        $block = $this->requireBlock($blockId);
        $field = $this->requireField((string) ($input['fieldId'] ?? ''), (string) $block['template_id']);

        $values = $input['values'] ?? null;
        if (!is_array($values) || !array_is_list($values)) {
            throw new ApiException(422, 'invalid_request', 'I valori vanno inviati come elenco, anche quando è uno solo.');
        }
        $multi = $this->validator->isMulti((string) $field['field_type']);
        if (!$multi && count($values) > 1) {
            throw new ApiException(422, 'field_cardinality', 'Il campo accetta un solo valore.');
        }
        if ($values === [] && (int) $field['is_required'] === 1) {
            throw new ApiException(422, 'field_required', 'Il campo è obbligatorio e non può restare vuoto.');
        }

        $columns = [];
        foreach ($values as $value) {
            $prepared = $this->validator->columns($field, $value);
            $this->assertReferenceExists($prepared);
            $columns[] = $prepared;
        }

        $this->blocks->replaceValues($blockId, $field, $columns);
        return $this->blocksOf((string) $block['entity_id']);
    }

    /** INV: a reference points to something that already exists; nothing is created implicitly. */
    private function assertReferenceExists(array $columns): void
    {
        $entityId = $columns['entity_reference_id'] ?? null;
        if (is_string($entityId) && !$this->blocks->entityExists($entityId)) {
            throw new ApiException(422, 'reference_not_found', 'La Entity riferita non esiste: creala prima di riferirla.');
        }
        $conceptId = $columns['concept_reference_id'] ?? null;
        if (is_string($conceptId) && !$this->blocks->conceptExists($conceptId)) {
            throw new ApiException(422, 'reference_not_found', 'Il Concept riferito non esiste: creane uno prima di riferirlo.');
        }
    }

    /** @param array<string, mixed> $block @return array<string, mixed> */
    private function present(array $block): array
    {
        $fields = [];
        $values = $this->blocks->values((string) $block['id']);
        foreach ($this->templates->fields((string) $block['template_id']) as $field) {
            $own = array_values(array_filter($values, static fn (array $row): bool => $row['template_field_id'] === $field['id']));
            $fields[] = [
                'fieldId' => $field['id'],
                'name' => $field['name'],
                'fieldType' => $field['field_type'],
                'required' => (int) $field['is_required'] === 1,
                'options' => $this->validator->options($field),
                'values' => array_map($this->presentValue(...), $own),
            ];
        }
        return [
            'id' => $block['id'],
            'templateId' => $block['template_id'],
            'templateName' => $block['template_name'] ?? null,
            'sortOrder' => $block['sort_order'],
            'fields' => $fields,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function presentValue(array $row): array
    {
        $type = (string) $row['field_type'];
        $value = match ($type) {
            'text', 'url', 'enum', 'multi_enum' => $row['text_value'],
            'rich_text' => json_decode((string) $row['rich_text_json'], true),
            'number', 'percentage' => $row['number_value'],
            'measurement' => ['value' => $row['number_value'], 'unit' => $row['unit']],
            'currency' => ['value' => $row['number_value'], 'currency' => $row['currency_code']],
            'boolean' => (int) $row['boolean_value'] === 1,
            'date' => $row['date_value'],
            'entity_reference', 'multi_entity_reference' => $row['entity_reference_id'],
            'concept_reference', 'multi_concept_reference' => $row['concept_reference_id'],
            default => null,
        };
        return ['id' => $row['id'], 'ordinal' => $row['ordinal'], 'value' => $value, 'origin' => $row['origin']];
    }

    /** @return array<string, mixed> */
    private function requireBlock(string $blockId): array
    {
        $this->assertId($blockId);
        $block = $this->blocks->find($blockId);
        if ($block === null) {
            throw new ApiException(404, 'semantic_block_not_found', 'SemanticBlock non trovato.');
        }
        return $block;
    }

    /** @return array<string, mixed> */
    private function requireField(string $fieldId, string $templateId): array
    {
        $this->assertId($fieldId);
        $field = $this->templates->findField($fieldId);
        if ($field === null) {
            throw new ApiException(404, 'template_field_not_found', 'Campo non trovato.');
        }
        if ($field['template_id'] !== $templateId) {
            throw new ApiException(422, 'field_wrong_template', 'Il campo non appartiene al Template del blocco.');
        }
        return $field;
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

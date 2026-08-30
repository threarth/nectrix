<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

/**
 * Template, TemplateField, SemanticBlock and FieldValue.
 * A Template is a reusable definition; a SemanticBlock is one application of it to an Entity and
 * stays Entity-owned. Renaming preserves identity, while changing the type of a field that already
 * holds values is not ordinary CRUD: it needs the separate migration command, with a preview.
 */
final class TemplateService
{
    private const MAX_NAME_LENGTH = 200;
    private const MAX_DESCRIPTION_LENGTH = 4000;
    private const OPTION_TYPES = ['enum', 'multi_enum'];

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly SemanticBlockRepository $blocks,
        private readonly FieldValueValidator $validator,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $templates = [];
        foreach ($this->templates->list() as $template) {
            $templates[] = [...$template, 'fields' => $this->templates->fields((string) $template['id'])];
        }
        return $templates;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(array $input): array
    {
        $this->assertOnlyKeys($input, ['name', 'description']);
        $name = $this->name($input['name'] ?? null);
        if ($this->templates->findByName($name) !== null) {
            throw new ApiException(422, 'template_duplicate', 'Esiste già un Template con questo nome.');
        }
        return [...$this->templates->create($name, $this->description($input['description'] ?? null)), 'fields' => []];
    }

    /** Renaming keeps the ID, so the values already written stay attached. */
    public function update(string $templateId, array $input): array
    {
        $this->assertOnlyKeys($input, ['name', 'description']);
        $this->requireTemplate($templateId);
        $name = $this->name($input['name'] ?? null);
        $existing = $this->templates->findByName($name);
        if ($existing !== null && $existing['id'] !== $templateId) {
            throw new ApiException(422, 'template_duplicate', 'Esiste già un Template con questo nome.');
        }
        $this->templates->update($templateId, $name, $this->description($input['description'] ?? null));
        return $this->template($templateId);
    }

    /** @return array<string, mixed> */
    public function setArchived(string $templateId, bool $archived): array
    {
        $this->requireTemplate($templateId);
        $this->templates->setStatus($templateId, $archived ? 'archived' : 'active');
        return $this->template($templateId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function addField(string $templateId, array $input): array
    {
        $this->assertOnlyKeys($input, ['name', 'fieldType', 'required', 'options']);
        $this->requireTemplate($templateId);
        $fieldType = $this->fieldType($input['fieldType'] ?? null);
        $this->templates->createField(
            $templateId,
            $this->name($input['name'] ?? null),
            $fieldType,
            ($input['required'] ?? false) === true,
            $this->options($fieldType, $input['options'] ?? null),
        );
        return $this->template($templateId);
    }

    /** Ordinary CRUD on a field never touches its type or its cardinality. */
    public function updateField(string $fieldId, array $input): array
    {
        $this->assertOnlyKeys($input, ['name', 'required']);
        $field = $this->requireField($fieldId);
        $this->templates->renameField($fieldId, $this->name($input['name'] ?? null), ($input['required'] ?? false) === true);
        return $this->template((string) $field['template_id']);
    }

    /** @return array<string, mixed> */
    public function deleteField(string $fieldId): array
    {
        $field = $this->requireField($fieldId);
        $values = $this->templates->fieldValueCount($fieldId);
        if ($values > 0) {
            throw new ApiException(409, 'field_has_values', "Il campo ha {$values} valori: rimuovili prima di eliminarlo.", [
                'values' => $values,
            ]);
        }
        $this->templates->deleteField($fieldId);
        return $this->template((string) $field['template_id']);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function reorderFields(string $templateId, array $input): array
    {
        $this->assertOnlyKeys($input, ['fieldIds']);
        $this->requireTemplate($templateId);
        $ordered = $input['fieldIds'] ?? null;
        $current = array_column($this->templates->fields($templateId), 'id');
        if (!is_array($ordered) || !array_is_list($ordered) || count($ordered) !== count($current)) {
            throw new ApiException(422, 'invalid_request', 'L’ordine deve elencare esattamente i campi del Template.');
        }
        foreach ($ordered as $fieldId) {
            if (!is_string($fieldId) || !in_array($fieldId, $current, true)) {
                throw new ApiException(422, 'invalid_request', 'L’ordine contiene un campo che non appartiene al Template.');
            }
        }
        $this->templates->reorderFields($templateId, $ordered);
        return $this->template($templateId);
    }

    /**
     * Separate command for the change of type or cardinality. Without `apply` it only reports the
     * impact; with values present it refuses unless the discard is asked for explicitly, and then
     * values and definition change inside one transaction.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function migrateFieldType(string $fieldId, array $input): array
    {
        $this->assertOnlyKeys($input, ['fieldType', 'options', 'apply', 'discardValues']);
        $field = $this->requireField($fieldId);
        $fieldType = $this->fieldType($input['fieldType'] ?? null);
        $options = $this->options($fieldType, $input['options'] ?? null);
        $values = $this->templates->fieldValueCount($fieldId);
        $preview = [
            'fieldId' => $fieldId,
            'from' => $field['field_type'],
            'to' => $fieldType,
            'values' => $values,
            'requiresDiscard' => $values > 0,
        ];

        if (($input['apply'] ?? false) !== true) {
            return ['preview' => $preview, 'applied' => false];
        }
        if ($values > 0 && ($input['discardValues'] ?? false) !== true) {
            throw new ApiException(409, 'field_has_values', "Il campo ha {$values} valori: la migrazione richiede di dichiarare che vanno scartati.", $preview);
        }

        $this->templates->migrateFieldType($fieldId, $fieldType, $options);
        return ['preview' => $preview, 'applied' => true, 'template' => $this->template((string) $field['template_id'])];
    }

    /** @return list<array<string, mixed>> */
    public function recommendations(string $entityTypeId): array
    {
        return $this->templates->recommendations($entityTypeId);
    }

    /** A recommendation guides the UI: it never forbids applying another Template. */
    public function recommend(string $entityTypeId, array $input): array
    {
        $this->assertOnlyKeys($input, ['templateId']);
        $templateId = (string) ($input['templateId'] ?? '');
        $this->requireTemplate($templateId);
        $this->templates->recommend($entityTypeId, $templateId);
        return $this->templates->recommendations($entityTypeId);
    }

    /** @return list<array<string, mixed>> */
    public function unrecommend(string $entityTypeId, string $templateId): array
    {
        $this->templates->unrecommend($entityTypeId, $templateId);
        return $this->templates->recommendations($entityTypeId);
    }

    /** @return array<string, mixed> */
    private function template(string $templateId): array
    {
        $template = $this->requireTemplate($templateId);
        return [...$template, 'fields' => $this->templates->fields($templateId)];
    }

    /** @return array<string, mixed> */
    private function requireTemplate(string $templateId): array
    {
        $this->assertId($templateId);
        $template = $this->templates->find($templateId);
        if ($template === null) {
            throw new ApiException(404, 'template_not_found', 'Template non trovato.');
        }
        return $template;
    }

    /** @return array<string, mixed> */
    private function requireField(string $fieldId): array
    {
        $this->assertId($fieldId);
        $field = $this->templates->findField($fieldId);
        if ($field === null) {
            throw new ApiException(404, 'template_field_not_found', 'Campo non trovato.');
        }
        return $field;
    }

    private function fieldType(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new ApiException(422, 'invalid_request', 'Il tipo del campo è obbligatorio.');
        }
        if ($value === 'source_reference') {
            throw new ApiException(422, 'field_type_disabled', 'I riferimenti a Source arrivano con la FASE 16.');
        }
        return $value;
    }

    /** @return list<string>|null */
    private function options(string $fieldType, mixed $value): ?array
    {
        if (!in_array($fieldType, self::OPTION_TYPES, true)) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new ApiException(422, 'invalid_request', 'Un campo a opzioni richiede l’elenco delle opzioni.');
        }
        $options = [];
        foreach ($value as $option) {
            if (!is_string($option) || trim($option) === '') {
                throw new ApiException(422, 'invalid_request', 'Le opzioni devono essere testi non vuoti.');
            }
            $options[] = trim($option);
        }
        return array_values(array_unique($options));
    }

    private function name(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || Text::length(trim($value)) > self::MAX_NAME_LENGTH) {
            throw new ApiException(422, 'invalid_request', 'Il nome è obbligatorio e non può superare i limiti.');
        }
        return trim($value);
    }

    private function description(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || Text::length($value) > self::MAX_DESCRIPTION_LENGTH) {
            throw new ApiException(422, 'invalid_request', 'La descrizione supera il limite consentito.');
        }
        return trim($value) === '' ? null : trim($value);
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

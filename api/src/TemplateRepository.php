<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;

/** Template, their fields and the ordered recommendations towards EntityType. */
final class TemplateRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->pdo->query(
            'SELECT id, name, description, status FROM templates ORDER BY name COLLATE NOCASE'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(string $templateId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name, description, status FROM templates WHERE id = :id');
        $statement->execute(['id' => $templateId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByName(string $name): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM templates WHERE name = :name COLLATE NOCASE');
        $statement->execute(['name' => $name]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed> */
    public function create(string $name, ?string $description): array
    {
        $id = UuidV7::generate();
        $timestamp = Clock::now();
        $this->pdo->prepare(
            'INSERT INTO templates (id, name, description, created_at, updated_at) ' .
            'VALUES (:id, :name, :description, :created, :updated)'
        )->execute(['id' => $id, 'name' => $name, 'description' => $description, 'created' => $timestamp, 'updated' => $timestamp]);
        return ['id' => $id, 'name' => $name, 'description' => $description, 'status' => 'active'];
    }

    /** Renaming keeps the ID: the values already written stay attached to the same definition. */
    public function update(string $templateId, string $name, ?string $description): void
    {
        $this->pdo->prepare('UPDATE templates SET name = :name, description = :description, updated_at = :updated WHERE id = :id')
            ->execute(['name' => $name, 'description' => $description, 'updated' => Clock::now(), 'id' => $templateId]);
    }

    public function setStatus(string $templateId, string $status): void
    {
        $this->pdo->prepare('UPDATE templates SET status = :status, updated_at = :updated WHERE id = :id')
            ->execute(['status' => $status, 'updated' => Clock::now(), 'id' => $templateId]);
    }

    /** @return list<array<string, mixed>> */
    public function fields(string $templateId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, template_id, name, field_type, is_required, sort_order, options_json ' .
            'FROM template_fields WHERE template_id = :id ORDER BY sort_order'
        );
        $statement->execute(['id' => $templateId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findField(string $fieldId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, template_id, name, field_type, is_required, sort_order, options_json FROM template_fields WHERE id = :id'
        );
        $statement->execute(['id' => $fieldId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function nextFieldOrder(string $templateId): int
    {
        $statement = $this->pdo->prepare('SELECT coalesce(MAX(sort_order), -1) + 1 FROM template_fields WHERE template_id = :id');
        $statement->execute(['id' => $templateId]);
        return (int) $statement->fetchColumn();
    }

    /** @param list<string>|null $options @return array<string, mixed> */
    public function createField(string $templateId, string $name, string $fieldType, bool $required, ?array $options): array
    {
        $id = UuidV7::generate();
        $timestamp = Clock::now();
        $this->pdo->prepare(
            'INSERT INTO template_fields (id, template_id, name, field_type, is_required, sort_order, options_json, created_at, updated_at) ' .
            'VALUES (:id, :template_id, :name, :field_type, :required, :sort_order, :options, :created, :updated)'
        )->execute([
            'id' => $id, 'template_id' => $templateId, 'name' => $name, 'field_type' => $fieldType,
            'required' => $required ? 1 : 0, 'sort_order' => $this->nextFieldOrder($templateId),
            'options' => $options === null ? null : json_encode($options, JSON_UNESCAPED_UNICODE),
            'created' => $timestamp, 'updated' => $timestamp,
        ]);
        return $this->findField($id) ?? [];
    }

    /** Only presentation fields change here: type and cardinality are not touched. */
    public function renameField(string $fieldId, string $name, bool $required): void
    {
        $this->pdo->prepare('UPDATE template_fields SET name = :name, is_required = :required, updated_at = :updated WHERE id = :id')
            ->execute(['name' => $name, 'required' => $required ? 1 : 0, 'updated' => Clock::now(), 'id' => $fieldId]);
    }

    public function deleteField(string $fieldId): void
    {
        $this->pdo->prepare('DELETE FROM template_fields WHERE id = :id')->execute(['id' => $fieldId]);
    }

    /**
     * Reordering happens in two passes inside one transaction: sort_order is unique per Template
     * and must stay non negative, so the first pass parks the fields above the current maximum.
     *
     * @param list<string> $orderedIds
     */
    public function reorderFields(string $templateId, array $orderedIds): void
    {
        $this->pdo->beginTransaction();
        try {
            $offset = $this->nextFieldOrder($templateId);
            $timestamp = Clock::now();
            $park = $this->pdo->prepare('UPDATE template_fields SET sort_order = :position WHERE id = :id AND template_id = :template_id');
            $apply = $this->pdo->prepare('UPDATE template_fields SET sort_order = :position, updated_at = :updated WHERE id = :id AND template_id = :template_id');
            foreach ($orderedIds as $position => $fieldId) {
                $park->execute(['position' => $offset + $position, 'id' => $fieldId, 'template_id' => $templateId]);
            }
            foreach ($orderedIds as $position => $fieldId) {
                $apply->execute(['position' => $position, 'updated' => $timestamp, 'id' => $fieldId, 'template_id' => $templateId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Changes type and options of a field and discards its values, in one transaction: a value of
     * the old type would violate the constraints of the new one.
     *
     * @param list<string>|null $options
     */
    public function migrateFieldType(string $fieldId, string $fieldType, ?array $options): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM field_values WHERE template_field_id = :id')->execute(['id' => $fieldId]);
            $this->pdo->prepare(
                'UPDATE template_fields SET field_type = :field_type, options_json = :options, updated_at = :updated WHERE id = :id'
            )->execute([
                'field_type' => $fieldType,
                'options' => $options === null ? null : json_encode($options, JSON_UNESCAPED_UNICODE),
                'updated' => Clock::now(),
                'id' => $fieldId,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function fieldValueCount(string $fieldId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM field_values WHERE template_field_id = :id');
        $statement->execute(['id' => $fieldId]);
        return (int) $statement->fetchColumn();
    }

    public function blockCount(string $templateId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM semantic_blocks WHERE template_id = :id');
        $statement->execute(['id' => $templateId]);
        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function recommendations(string $entityTypeId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.name, t.status, r.sort_order FROM entity_type_templates r ' .
            'JOIN templates t ON t.id = r.template_id WHERE r.entity_type_id = :id ORDER BY r.sort_order'
        );
        $statement->execute(['id' => $entityTypeId]);
        return $statement->fetchAll();
    }

    public function recommend(string $entityTypeId, string $templateId): void
    {
        $statement = $this->pdo->prepare('SELECT coalesce(MAX(sort_order), -1) + 1 FROM entity_type_templates WHERE entity_type_id = :id');
        $statement->execute(['id' => $entityTypeId]);
        $this->pdo->prepare(
            'INSERT INTO entity_type_templates (entity_type_id, template_id, sort_order, created_at) ' .
            'VALUES (:entity_type_id, :template_id, :sort_order, :created) ' .
            'ON CONFLICT (entity_type_id, template_id) DO NOTHING'
        )->execute([
            'entity_type_id' => $entityTypeId, 'template_id' => $templateId,
            'sort_order' => (int) $statement->fetchColumn(), 'created' => Clock::now(),
        ]);
    }

    public function unrecommend(string $entityTypeId, string $templateId): void
    {
        $this->pdo->prepare('DELETE FROM entity_type_templates WHERE entity_type_id = :entity_type_id AND template_id = :template_id')
            ->execute(['entity_type_id' => $entityTypeId, 'template_id' => $templateId]);
    }
}

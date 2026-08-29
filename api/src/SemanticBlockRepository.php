<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;

/**
 * SemanticBlock and their FieldValue. A block belongs to an Entity and to a Template: it is not a
 * highlight, an occurrence, a range or a copy inside the document content.
 */
final class SemanticBlockRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string, mixed>> */
    public function blocksOfEntity(string $entityId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT b.id, b.template_id, b.sort_order, t.name AS template_name ' .
            'FROM semantic_blocks b JOIN templates t ON t.id = b.template_id ' .
            'WHERE b.entity_id = :id ORDER BY b.sort_order'
        );
        $statement->execute(['id' => $entityId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(string $blockId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, entity_id, template_id, sort_order FROM semantic_blocks WHERE id = :id');
        $statement->execute(['id' => $blockId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed> */
    public function create(string $entityId, string $templateId): array
    {
        $order = $this->pdo->prepare('SELECT coalesce(MAX(sort_order), -1) + 1 FROM semantic_blocks WHERE entity_id = :id');
        $order->execute(['id' => $entityId]);
        $id = UuidV7::generate();
        $timestamp = Clock::now();
        $this->pdo->prepare(
            'INSERT INTO semantic_blocks (id, entity_id, template_id, sort_order, created_at, updated_at) ' .
            'VALUES (:id, :entity_id, :template_id, :sort_order, :created, :updated)'
        )->execute([
            'id' => $id, 'entity_id' => $entityId, 'template_id' => $templateId,
            'sort_order' => (int) $order->fetchColumn(), 'created' => $timestamp, 'updated' => $timestamp,
        ]);
        return $this->find($id) ?? [];
    }

    public function delete(string $blockId): void
    {
        $this->pdo->prepare('DELETE FROM field_values WHERE semantic_block_id = :id')->execute(['id' => $blockId]);
        $this->pdo->prepare('DELETE FROM semantic_blocks WHERE id = :id')->execute(['id' => $blockId]);
    }

    /** @return list<array<string, mixed>> */
    public function values(string $blockId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT v.id, v.template_field_id, v.field_type, v.ordinal, v.text_value, v.rich_text_json, ' .
            'v.number_value, v.boolean_value, v.date_value, v.unit, v.currency_code, ' .
            'v.entity_reference_id, v.concept_reference_id, v.origin ' .
            'FROM field_values v JOIN template_fields f ON f.id = v.template_field_id ' .
            'WHERE v.semantic_block_id = :id ORDER BY f.sort_order, v.ordinal'
        );
        $statement->execute(['id' => $blockId]);
        return $statement->fetchAll();
    }

    /**
     * Replaces the values of one field inside one block, in a single transaction: a partially
     * written multi value would leave the block inconsistent.
     *
     * @param array<string, mixed> $field
     * @param list<array<string, mixed>> $columns one entry per value, already validated
     */
    public function replaceValues(string $blockId, array $field, array $columns): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM field_values WHERE semantic_block_id = :block AND template_field_id = :field')
                ->execute(['block' => $blockId, 'field' => $field['id']]);
            $timestamp = Clock::now();
            $insert = $this->pdo->prepare(
                'INSERT INTO field_values (id, semantic_block_id, template_id, template_field_id, field_type, ordinal, ' .
                'text_value, rich_text_json, number_value, boolean_value, date_value, unit, currency_code, ' .
                'entity_reference_id, concept_reference_id, origin, created_at, updated_at) ' .
                'VALUES (:id, :block, :template, :field, :field_type, :ordinal, ' .
                ':text_value, :rich_text_json, :number_value, :boolean_value, :date_value, :unit, :currency_code, ' .
                ":entity_reference_id, :concept_reference_id, 'manual', :created, :updated)"
            );
            foreach ($columns as $ordinal => $value) {
                $insert->execute([
                    'id' => UuidV7::generate(), 'block' => $blockId, 'template' => $field['template_id'],
                    'field' => $field['id'], 'field_type' => $field['field_type'], 'ordinal' => $ordinal,
                    ...$value, 'created' => $timestamp, 'updated' => $timestamp,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function entityExists(string $entityId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM entities WHERE id = :id');
        $statement->execute(['id' => $entityId]);
        return $statement->fetch() !== false;
    }

    public function conceptExists(string $conceptId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM concepts WHERE id = :id');
        $statement->execute(['id' => $conceptId]);
        return $statement->fetch() !== false;
    }
}

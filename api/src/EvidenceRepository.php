<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;

/**
 * Evidence linking a KnowledgeRelation or a derived FieldValue to the data that supports it.
 * One dedicated table per destination family, each with real foreign keys: nothing here relies on
 * a generic target_type/target_id pair that the database could not verify.
 */
final class EvidenceRepository
{
    /** family => [table, destination column, existence check]. */
    public const FAMILIES = [
        'document' => ['evidence_documents', 'document_id', 'documents'],
        'occurrence' => ['evidence_occurrences', 'knowledge_occurrence_id', 'knowledge_occurrences'],
        'semantic_block' => ['evidence_semantic_blocks', 'semantic_block_id', 'semantic_blocks'],
        'field_value' => ['evidence_field_values', 'target_field_value_id', 'field_values'],
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function destinationExists(string $family, string $destinationId): bool
    {
        [, , $table] = self::FAMILIES[$family];
        $statement = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id");
        $statement->execute(['id' => $destinationId]);
        return $statement->fetch() !== false;
    }

    public function add(string $family, string $subjectColumn, string $subjectId, string $destinationId, ?string $note): string
    {
        [$table, $column] = self::FAMILIES[$family];
        $id = UuidV7::generate();
        $this->pdo->prepare(
            "INSERT INTO {$table} (id, {$subjectColumn}, {$column}, note, created_at) " .
            'VALUES (:id, :subject, :destination, :note, :created)'
        )->execute([
            'id' => $id, 'subject' => $subjectId, 'destination' => $destinationId,
            'note' => $note, 'created' => Clock::now(),
        ]);
        return $id;
    }

    public function remove(string $family, string $evidenceId): void
    {
        [$table] = self::FAMILIES[$family];
        $this->pdo->prepare("DELETE FROM {$table} WHERE id = :id")->execute(['id' => $evidenceId]);
    }

    public function exists(string $family, string $evidenceId): bool
    {
        [$table] = self::FAMILIES[$family];
        $statement = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id");
        $statement->execute(['id' => $evidenceId]);
        return $statement->fetch() !== false;
    }

    /**
     * Evidence of a subject, each keeping the path towards the authoritative data: the Document it
     * lives in, the state of the occurrence, the Entity that owns the block or the value.
     *
     * @return list<array<string, mixed>>
     */
    public function of(string $subjectColumn, string $subjectId): array
    {
        $queries = [
            'document' => 'SELECT e.id, e.note, d.id AS destination_id, d.title AS label, ' .
                "d.status AS state, d.id AS document_id, NULL AS detail FROM evidence_documents e " .
                "JOIN documents d ON d.id = e.document_id WHERE e.{$subjectColumn} = :id",
            'occurrence' => 'SELECT e.id, e.note, k.id AS destination_id, d.title AS label, ' .
                'k.status AS state, d.id AS document_id, ' .
                "coalesce(c.canonical_name, en.name) AS detail FROM evidence_occurrences e " .
                'JOIN knowledge_occurrences k ON k.id = e.knowledge_occurrence_id ' .
                'JOIN documents d ON d.id = k.document_id ' .
                'LEFT JOIN concepts c ON c.id = k.knowledge_object_id ' .
                'LEFT JOIN entities en ON en.id = k.knowledge_object_id ' .
                "WHERE e.{$subjectColumn} = :id",
            'semantic_block' => 'SELECT e.id, e.note, b.id AS destination_id, t.name AS label, ' .
                "'active' AS state, NULL AS document_id, en.name AS detail FROM evidence_semantic_blocks e " .
                'JOIN semantic_blocks b ON b.id = e.semantic_block_id ' .
                'JOIN templates t ON t.id = b.template_id JOIN entities en ON en.id = b.entity_id ' .
                "WHERE e.{$subjectColumn} = :id",
            'field_value' => 'SELECT e.id, e.note, v.id AS destination_id, f.name AS label, ' .
                "v.origin AS state, NULL AS document_id, en.name AS detail FROM evidence_field_values e " .
                'JOIN field_values v ON v.id = e.target_field_value_id ' .
                'JOIN template_fields f ON f.id = v.template_field_id ' .
                'JOIN semantic_blocks b ON b.id = v.semantic_block_id ' .
                'JOIN entities en ON en.id = b.entity_id ' .
                "WHERE e.{$subjectColumn} = :id",
        ];

        $evidence = [];
        foreach ($queries as $family => $sql) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute(['id' => $subjectId]);
            foreach ($statement->fetchAll() as $row) {
                $evidence[] = [...$row, 'family' => $family];
            }
        }
        return $evidence;
    }
}

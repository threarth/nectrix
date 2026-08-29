<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;

/**
 * Queries over the typed columns of field_values. Each field type is compared on its own column:
 * numbers, dates, booleans and references never go through a generic cast to text.
 */
final class StructuredQueryRepository
{
    private const LIMIT = 100;

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Entity whose values satisfy one filter, with the path that produced the match.
     *
     * @param array<string, mixed> $field the template field row
     * @return list<array<string, mixed>>
     */
    public function entitiesMatching(array $field, string $condition, array $parameters): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT e.id, e.name, t.name AS entity_type_name ' .
            'FROM field_values v ' .
            'JOIN semantic_blocks b ON b.id = v.semantic_block_id ' .
            'JOIN entities e ON e.id = b.entity_id ' .
            'JOIN entity_types t ON t.id = e.entity_type_id ' .
            'WHERE v.template_field_id = :field_id AND ' . $condition . ' ' .
            'ORDER BY e.name COLLATE NOCASE LIMIT ' . self::LIMIT
        );
        $statement->execute(['field_id' => $field['id'], ...$parameters]);
        return $statement->fetchAll();
    }

    /**
     * Document linked to an Entity through an active occurrence. This is the declared link between
     * structured data and editorial content: never the equality of names.
     *
     * @param list<string> $entityIds
     * @return list<array<string, mixed>>
     */
    public function documentsOfEntities(array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT k.knowledge_object_id AS entity_id, d.id, d.title ' .
            'FROM knowledge_occurrences k JOIN documents d ON d.id = k.document_id ' .
            "WHERE k.status = 'active' AND k.object_type = 'entity' " .
            "AND k.knowledge_object_id IN ({$placeholders}) ORDER BY d.updated_at DESC"
        );
        $statement->execute($entityIds);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function templateFieldsMatching(string $like): array
    {
        $statement = $this->pdo->prepare(
            'SELECT f.id, f.name, f.field_type, t.id AS template_id, t.name AS template_name ' .
            'FROM template_fields f JOIN templates t ON t.id = f.template_id ' .
            'WHERE f.name LIKE :like OR t.name LIKE :like ORDER BY t.name, f.sort_order LIMIT ' . self::LIMIT
        );
        $statement->execute(['like' => $like]);
        return $statement->fetchAll();
    }
}

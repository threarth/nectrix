<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;

/**
 * Counts and drill-down of the Context matrices. Every axis starts from the same backbone — the
 * active KnowledgeOccurrence and the Context that contains it — because a Context is a range drawn
 * around a fragment, not a property of the Document: EntityType, Template and FieldValue extend
 * that same path, they never bypass it. A fragment inside no range stays in its own column.
 * The SQL is assembled from fixed fragments chosen by the axis; every value is bound.
 */
final class MatrixRepository
{
    /** SELECT list and joins of each axis, keyed by the axis name accepted by the service. */
    private const AXES = [
        'concept' => [
            'select' => 'k.knowledge_object_id AS row_id, c.canonical_name AS row_label',
            'joins' => 'JOIN concepts c ON c.id = k.knowledge_object_id',
            'where' => "AND k.object_type = 'concept'",
            'group' => 'k.knowledge_object_id, c.canonical_name',
        ],
        'entity' => [
            'select' => 'k.knowledge_object_id AS row_id, e.name AS row_label',
            'joins' => 'JOIN entities e ON e.id = k.knowledge_object_id',
            'where' => "AND k.object_type = 'entity'",
            'group' => 'k.knowledge_object_id, e.name',
        ],
        'entity_type' => [
            'select' => 't.id AS row_id, t.name AS row_label',
            'joins' => 'JOIN entities e ON e.id = k.knowledge_object_id ' .
                'JOIN entity_types t ON t.id = e.entity_type_id',
            'where' => "AND k.object_type = 'entity'",
            'group' => 't.id, t.name',
        ],
        'template' => [
            'select' => 'tp.id AS row_id, tp.name AS row_label',
            'joins' => 'JOIN entities e ON e.id = k.knowledge_object_id ' .
                'JOIN semantic_blocks b ON b.entity_id = e.id ' .
                'JOIN templates tp ON tp.id = b.template_id',
            'where' => "AND k.object_type = 'entity'",
            'group' => 'tp.id, tp.name',
        ],
    ];

    /**
     * The Context of a fragment is the range that contains it: the left join keeps the fragments
     * that no range covers, which belong to the column without Context.
     */
    private const BACKBONE = 'FROM knowledge_occurrences k JOIN documents d ON d.id = k.document_id ' .
        'LEFT JOIN context_memberships m ON m.knowledge_occurrence_id = k.id ' .
        "LEFT JOIN context_occurrences co ON co.id = m.context_occurrence_id AND co.status = 'active' ";

    private const ACTIVE = "WHERE k.status = 'active' AND d.status = 'active' ";

    /** The column a fragment falls in: the Context of its containing range, or none. */
    private const COLUMN = 'CASE WHEN co.id IS NULL THEN NULL ELSE m.context_id END';

    public function __construct(private readonly PDO $pdo) {}

    public static function knowsAxis(string $axis): bool
    {
        return array_key_exists($axis, self::AXES);
    }

    /**
     * One row per (subject, Context of the Document): the leaf counts the service then aggregates
     * on the hierarchy. The count is the number of distinct active occurrence, so the drill-down
     * of a cell returns exactly as many rows as the cell declares.
     *
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    public function counts(string $axis, ?string $filter, array $parameters): array
    {
        $definition = self::AXES[$axis];
        $statement = $this->pdo->prepare(
            'SELECT ' . $definition['select'] . ', ' . self::COLUMN . ' AS context_id, ' .
            'COUNT(DISTINCT k.id) AS matches ' . self::BACKBONE . $definition['joins'] . ' ' .
            self::ACTIVE . $definition['where'] . ' ' . $this->filterClause($axis, $filter) .
            ' GROUP BY ' . $definition['group'] . ', ' . self::COLUMN
        );
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /**
     * The occurrence behind one cell, with the Document that carries them.
     *
     * @param list<string>|null $contextIds null selects the Document without a Context
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    public function cell(string $axis, string $rowId, ?array $contextIds, ?string $filter, array $parameters): array
    {
        if ($contextIds === []) {
            return [];
        }
        $definition = self::AXES[$axis];
        $rowColumn = explode(' AS ', $definition['select'])[0];
        [$contextClause, $contextParameters] = $this->contextClause($contextIds);
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT k.id AS occurrence_id, k.status, k.knowledge_object_id, k.object_type, ' .
            'd.id AS document_id, d.title, ' . self::COLUMN . ' AS context_id ' . self::BACKBONE . $definition['joins'] . ' ' .
            self::ACTIVE . $definition['where'] . ' ' . $this->filterClause($axis, $filter) .
            ' AND ' . $rowColumn . ' = :row_id ' . $contextClause . ' ORDER BY d.updated_at DESC'
        );
        $statement->execute([...$parameters, ...$contextParameters, 'row_id' => $rowId]);
        return $statement->fetchAll();
    }

    /**
     * KnowledgeObject occurring in the same Document: the co-occurrence the drill-down shows. It is
     * context, never a relation — a KnowledgeRelation is only the one explicitly declared.
     *
     * @param list<string> $documentIds
     * @return list<array<string, mixed>>
     */
    public function coObjects(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT k.document_id, k.knowledge_object_id AS id, k.object_type, ' .
            'COALESCE(c.canonical_name, e.name) AS label FROM knowledge_occurrences k ' .
            'LEFT JOIN concepts c ON c.id = k.knowledge_object_id ' .
            'LEFT JOIN entities e ON e.id = k.knowledge_object_id ' .
            "WHERE k.status = 'active' AND k.document_id IN ({$placeholders}) " .
            'ORDER BY label COLLATE NOCASE'
        );
        $statement->execute($documentIds);
        return $statement->fetchAll();
    }

    /** The FieldValue filter, applied on the block of the Entity the axis already reached. */
    private function filterClause(string $axis, ?string $filter): string
    {
        if ($filter === null) {
            return '';
        }
        $scope = $axis === 'template'
            ? 'v.semantic_block_id = b.id'
            : 'v.semantic_block_id IN (SELECT id FROM semantic_blocks WHERE entity_id = e.id)';
        return 'AND EXISTS (SELECT 1 FROM field_values v WHERE ' . $scope .
            ' AND v.template_field_id = :field_id AND ' . $filter . ') ';
    }

    /**
     * @param list<string>|null $contextIds
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function contextClause(?array $contextIds): array
    {
        if ($contextIds === null) {
            return ['AND co.id IS NULL', []];
        }
        $parameters = [];
        $placeholders = [];
        foreach (array_values($contextIds) as $position => $contextId) {
            $placeholders[] = ':context' . $position;
            $parameters['context' . $position] = $contextId;
        }
        return ['AND co.id IS NOT NULL AND m.context_id IN (' . implode(',', $placeholders) . ')', $parameters];
    }
}

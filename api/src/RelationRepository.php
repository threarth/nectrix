<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

use PDO;

/**
 * Directed arcs between KnowledgeObject. Direction and the discriminator of both ends are stored
 * and verified: nothing here is inferred from co-occurrence, Context or Tag.
 */
final class RelationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Arcs where the object is the source or the target, each keeping its own direction.
     *
     * @return list<array<string, mixed>>
     */
    public function of(string $objectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.relation_type, r.description, ' .
            'r.source_knowledge_object_id, r.source_object_type, ' .
            'r.target_knowledge_object_id, r.target_object_type, ' .
            'coalesce(sc.canonical_name, se.name) AS source_name, ' .
            'coalesce(tc.canonical_name, te.name) AS target_name ' .
            'FROM knowledge_relations r ' .
            'LEFT JOIN concepts sc ON sc.id = r.source_knowledge_object_id ' .
            'LEFT JOIN entities se ON se.id = r.source_knowledge_object_id ' .
            'LEFT JOIN concepts tc ON tc.id = r.target_knowledge_object_id ' .
            'LEFT JOIN entities te ON te.id = r.target_knowledge_object_id ' .
            'WHERE r.source_knowledge_object_id = :id OR r.target_knowledge_object_id = :id ' .
            'ORDER BY r.relation_type, target_name COLLATE NOCASE'
        );
        $statement->execute(['id' => $objectId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(string $relationId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, source_knowledge_object_id, source_object_type, ' .
            'target_knowledge_object_id, target_object_type, relation_type, description ' .
            'FROM knowledge_relations WHERE id = :id'
        );
        $statement->execute(['id' => $relationId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** The same predicate between the same ordered pair exists once: direction is part of it. */
    public function exists(string $sourceId, string $targetId, string $relationType): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM knowledge_relations WHERE source_knowledge_object_id = :source ' .
            'AND target_knowledge_object_id = :target AND relation_type = :type COLLATE NOCASE'
        );
        $statement->execute(['source' => $sourceId, 'target' => $targetId, 'type' => $relationType]);
        return $statement->fetch() !== false;
    }

    public function create(
        string $sourceId,
        string $sourceType,
        string $targetId,
        string $targetType,
        string $relationType,
        ?string $description,
    ): string {
        $id = UuidV7::generate();
        $timestamp = Clock::now();
        $this->pdo->prepare(
            'INSERT INTO knowledge_relations (id, source_knowledge_object_id, source_object_type, ' .
            'target_knowledge_object_id, target_object_type, relation_type, description, created_at, updated_at) ' .
            'VALUES (:id, :source, :source_type, :target, :target_type, :type, :description, :created, :updated)'
        )->execute([
            'id' => $id, 'source' => $sourceId, 'source_type' => $sourceType,
            'target' => $targetId, 'target_type' => $targetType, 'type' => $relationType,
            'description' => $description, 'created' => $timestamp, 'updated' => $timestamp,
        ]);
        return $id;
    }

    public function delete(string $relationId): void
    {
        $this->pdo->prepare('DELETE FROM knowledge_relations WHERE id = :id')->execute(['id' => $relationId]);
    }

    /** Predicates already used, so the UI can suggest them without imposing a closed list. */
    public function usedTypes(): array
    {
        return array_column(
            $this->pdo->query('SELECT DISTINCT relation_type FROM knowledge_relations ORDER BY relation_type COLLATE NOCASE')->fetchAll(),
            'relation_type',
        );
    }
}

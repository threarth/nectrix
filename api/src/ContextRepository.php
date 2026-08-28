<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;

/**
 * Hierarchy of Context. Depth is unlimited and the path is always derived from parent_id: no
 * materialised path is stored, so a move of a whole branch is a single update.
 */
final class ContextRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->pdo->query(
            'SELECT id, parent_id, name FROM contexts ORDER BY name COLLATE NOCASE'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(string $contextId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, parent_id, name FROM contexts WHERE id = :id');
        $statement->execute(['id' => $contextId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed> */
    public function create(string $name, ?string $parentId): array
    {
        $id = UuidV7::generate();
        $timestamp = Clock::now();
        $this->pdo->prepare(
            'INSERT INTO contexts (id, parent_id, name, created_at, updated_at) ' .
            'VALUES (:id, :parent_id, :name, :created, :updated)'
        )->execute(['id' => $id, 'parent_id' => $parentId, 'name' => $name, 'created' => $timestamp, 'updated' => $timestamp]);
        return ['id' => $id, 'parent_id' => $parentId, 'name' => $name];
    }

    public function rename(string $contextId, string $name): void
    {
        $this->pdo->prepare('UPDATE contexts SET name = :name, updated_at = :updated WHERE id = :id')
            ->execute(['name' => $name, 'updated' => Clock::now(), 'id' => $contextId]);
    }

    public function move(string $contextId, ?string $parentId): void
    {
        $this->pdo->prepare('UPDATE contexts SET parent_id = :parent_id, updated_at = :updated WHERE id = :id')
            ->execute(['parent_id' => $parentId, 'updated' => Clock::now(), 'id' => $contextId]);
    }

    public function delete(string $contextId): void
    {
        $this->pdo->prepare('DELETE FROM contexts WHERE id = :id')->execute(['id' => $contextId]);
    }

    /** Ancestors from the root down to the Context itself, the breadcrumb of the UI. */
    public function ancestors(string $contextId): array
    {
        $statement = $this->pdo->prepare(
            'WITH RECURSIVE path(id, parent_id, name, depth) AS (' .
            '  SELECT id, parent_id, name, 0 FROM contexts WHERE id = :id' .
            '  UNION ALL' .
            '  SELECT c.id, c.parent_id, c.name, path.depth + 1 FROM contexts c JOIN path ON c.id = path.parent_id' .
            ') SELECT id, parent_id, name FROM path ORDER BY depth DESC'
        );
        $statement->execute(['id' => $contextId]);
        return $statement->fetchAll();
    }

    /** The Context and all its descendants, used by the subtree filter and by the move check. */
    public function subtreeIds(string $contextId): array
    {
        $statement = $this->pdo->prepare(
            'WITH RECURSIVE subtree(id) AS (' .
            '  SELECT id FROM contexts WHERE id = :id' .
            '  UNION ALL' .
            '  SELECT c.id FROM contexts c JOIN subtree ON c.parent_id = subtree.id' .
            ') SELECT id FROM subtree'
        );
        $statement->execute(['id' => $contextId]);
        return array_column($statement->fetchAll(), 'id');
    }

    public function hasChildren(string $contextId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM contexts WHERE parent_id = :id LIMIT 1');
        $statement->execute(['id' => $contextId]);
        return $statement->fetch() !== false;
    }

    public function documentCount(string $contextId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM documents WHERE context_id = :id');
        $statement->execute(['id' => $contextId]);
        return (int) $statement->fetchColumn();
    }

    public function assignDocument(string $documentId, ?string $contextId): void
    {
        $this->pdo->prepare('UPDATE documents SET context_id = :context_id WHERE id = :id')
            ->execute(['context_id' => $contextId, 'id' => $documentId]);
    }

    /**
     * KnowledgeObject reached through the explicit path Context→Document→KnowledgeOccurrence.
     * Nothing receives a context_id: the result is derived, and one object listed once even when
     * several of its occurrence fall in the same Context.
     *
     * @param list<string> $contextIds
     * @return list<array<string, mixed>>
     */
    public function knowledgeObjects(array $contextIds): array
    {
        if ($contextIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($contextIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT o.id, o.object_type, coalesce(c.canonical_name, e.name) AS name " .
            'FROM knowledge_occurrences k ' .
            'JOIN documents d ON d.id = k.document_id ' .
            'JOIN knowledge_objects o ON o.id = k.knowledge_object_id ' .
            'LEFT JOIN concepts c ON c.id = o.id ' .
            'LEFT JOIN entities e ON e.id = o.id ' .
            "WHERE k.status = 'active' AND d.context_id IN ({$placeholders}) " .
            'ORDER BY name COLLATE NOCASE'
        );
        $statement->execute($contextIds);
        return $statement->fetchAll();
    }
}

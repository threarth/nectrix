<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

use PDO;
use PDOException;

/**
 * Hierarchy of Context. Depth is unlimited and the path is always derived from parent_id: no
 * materialised path is stored, so a move of a whole branch is a single update.
 */
final class ContextRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * The whole hierarchy with the number of text ranges marked with each node. The Document does
     * not own a Context: what is counted is the fragments, so the UI can explain why a deletion is
     * refused before attempting it.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return $this->pdo->query(
            'SELECT c.id, c.parent_id, c.name, COUNT(o.id) AS occurrences, ' .
            'COUNT(DISTINCT o.document_id) AS documents FROM contexts c ' .
            "LEFT JOIN context_occurrences o ON o.context_id = c.id AND o.status = 'active' " .
            'WHERE NOT EXISTS (SELECT 1 FROM context_trash t WHERE t.context_id = c.id) ' .
            'GROUP BY c.id, c.parent_id, c.name ORDER BY c.name COLLATE NOCASE'
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
        $this->uniqueName($name, function () use ($id, $parentId, $name, $timestamp): void {
            $this->pdo->prepare(
                'INSERT INTO contexts (id, parent_id, name, created_at, updated_at) ' .
                'VALUES (:id, :parent_id, :name, :created, :updated)'
            )->execute(['id' => $id, 'parent_id' => $parentId, 'name' => $name, 'created' => $timestamp, 'updated' => $timestamp]);
        });
        return ['id' => $id, 'parent_id' => $parentId, 'name' => $name];
    }

    public function rename(string $contextId, string $name): void
    {
        $this->uniqueName($name, function () use ($contextId, $name): void {
            $this->pdo->prepare('UPDATE contexts SET name = :name, updated_at = :updated WHERE id = :id')
                ->execute(['name' => $name, 'updated' => Clock::now(), 'id' => $contextId]);
        });
    }

    /**
     * Two sibling Context cannot share a name: the index would stop telling them apart. The
     * refusal says which name is taken, instead of surfacing a constraint as an internal error.
     *
     * @param callable(): void $write
     */
    private function uniqueName(string $name, callable $write): void
    {
        try {
            $write();
        } catch (PDOException $error) {
            if (!str_contains($error->getMessage(), 'contexts_sibling_name_idx')) {
                throw $error;
            }
            throw new ApiException(409, 'context_name_taken', "Esiste già un Context «{$name}» allo stesso livello.", [
                'name' => $name,
            ]);
        }
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

    /** Ranges still marked with the Context: what a deletion would take away. */
    public function occurrenceCount(string $contextId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM context_occurrences WHERE context_id = :id AND status <> 'deleted'"
        );
        $statement->execute(['id' => $contextId]);
        return (int) $statement->fetchColumn();
    }

    /**
     * Document holding at least one active range of the given Context. The Document is not assigned
     * to a Context: it is simply where some marked fragment happens to live.
     *
     * @param list<string> $contextIds
     * @return list<string>
     */
    public function documentIds(array $contextIds): array
    {
        if ($contextIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($contextIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT document_id AS id FROM context_occurrences ' .
            "WHERE status = 'active' AND context_id IN ({$placeholders})"
        );
        $statement->execute($contextIds);
        return array_column($statement->fetchAll(), 'id');
    }

    /**
     * Concept and Entity of every Context, each one under the Context that actually contains its
     * fragment. This is what makes the sidebar a tree: a Context holds its sub-context and the
     * knowledge marked inside its own ranges, not the knowledge of its descendants.
     *
     * @return list<array<string, mixed>>
     */
    public function knowledgeObjectsByContext(): array
    {
        return $this->pdo->query(
            'SELECT DISTINCT m.context_id, m.knowledge_object_id AS id, m.object_type, ' .
            'COALESCE(c.canonical_name, e.name) AS name FROM context_memberships m ' .
            'JOIN context_occurrences o ON o.id = m.context_occurrence_id ' .
            'LEFT JOIN concepts c ON c.id = m.knowledge_object_id ' .
            'LEFT JOIN entities e ON e.id = m.knowledge_object_id ' .
            "WHERE o.status = 'active' " .
            'AND NOT EXISTS (SELECT 1 FROM knowledge_object_trash t ' .
            'WHERE t.knowledge_object_id = m.knowledge_object_id) ' .
            'ORDER BY name COLLATE NOCASE'
        )->fetchAll();
    }

    /**
     * Concept and Entity whose fragment is contained in one of the given Context. This is the
     * derived membership, not the co-presence in the same Document.
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
            'SELECT DISTINCT m.knowledge_object_id AS id, m.object_type, ' .
            'COALESCE(c.canonical_name, e.name) AS name FROM context_memberships m ' .
            'JOIN context_occurrences o ON o.id = m.context_occurrence_id ' .
            'LEFT JOIN concepts c ON c.id = m.knowledge_object_id ' .
            'LEFT JOIN entities e ON e.id = m.knowledge_object_id ' .
            "WHERE o.status = 'active' AND m.context_id IN ({$placeholders}) " .
            'AND NOT EXISTS (SELECT 1 FROM knowledge_object_trash t ' .
            'WHERE t.knowledge_object_id = m.knowledge_object_id) ' .
            'ORDER BY name COLLATE NOCASE'
        );
        $statement->execute($contextIds);
        return $statement->fetchAll();
    }
}

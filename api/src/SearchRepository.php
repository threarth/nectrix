<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;

/**
 * Queries behind the search. Every result declares the category it belongs to and how it matched,
 * so a string match is never confused with a match by Concept or Entity identity.
 */
final class SearchRepository
{
    /** Un oggetto cestinato sparisce dalle ricerche finche non viene ripristinato. */
    private const NOT_TRASHED =
        'NOT EXISTS (SELECT 1 FROM knowledge_object_trash t WHERE t.knowledge_object_id = ';

    private const LIMIT = 20;
    private const SNIPPET_TOKENS = 10;

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Document whose title or derived plain text matches the query. The index is external content:
     * the authoritative text stays in documents and the index is rebuildable from it.
     *
     * @return list<array<string, mixed>>
     */
    public function documents(string $expression): array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.id, d.title, d.status, ' .
            "snippet(documents_fts, 1, '', '', '…', " . self::SNIPPET_TOKENS . ') AS snippet ' .
            'FROM documents_fts f JOIN documents d ON d.rowid = f.rowid ' .
            'WHERE documents_fts MATCH :expression ORDER BY rank LIMIT ' . self::LIMIT
        );
        $statement->execute(['expression' => $expression]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function concepts(string $like): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.canonical_name AS name, c.status, NULL AS matched_text ' .
            'FROM concepts c WHERE c.canonical_name LIKE :like AND ' . self::NOT_TRASHED . 'c.id) ' .
            'ORDER BY c.canonical_name COLLATE NOCASE LIMIT ' . self::LIMIT
        );
        $statement->execute(['like' => $like]);
        return $statement->fetchAll();
    }

    /** Concept reached through one of its Alias: the Alias is the match, the Concept the result. */
    public function conceptsByAlias(string $like): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.canonical_name AS name, c.status, a.alias AS matched_text ' .
            'FROM concept_aliases a JOIN concepts c ON c.id = a.concept_id ' .
            'WHERE a.alias LIKE :like AND ' . self::NOT_TRASHED . 'c.id) ' .
            'ORDER BY a.alias COLLATE NOCASE LIMIT ' . self::LIMIT
        );
        $statement->execute(['like' => $like]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function entities(string $like): array
    {
        $statement = $this->pdo->prepare(
            'SELECT e.id, e.name, e.status, t.name AS entity_type_name, NULL AS matched_text ' .
            'FROM entities e JOIN entity_types t ON t.id = e.entity_type_id ' .
            'WHERE e.name LIKE :like AND ' . self::NOT_TRASHED . 'e.id) ' .
            'ORDER BY e.name COLLATE NOCASE LIMIT ' . self::LIMIT
        );
        $statement->execute(['like' => $like]);
        return $statement->fetchAll();
    }

    /** Entity reached through one of its Identifier, whose scheme stays visible in the result. */
    public function entitiesByIdentifier(string $like): array
    {
        $statement = $this->pdo->prepare(
            'SELECT e.id, e.name, e.status, t.name AS entity_type_name, ' .
            "i.scheme || ' ' || i.value AS matched_text " .
            'FROM entity_identifiers i JOIN entities e ON e.id = i.entity_id ' .
            'JOIN entity_types t ON t.id = e.entity_type_id ' .
            'WHERE (i.value LIKE :like OR i.normalized_value LIKE :like) AND ' . self::NOT_TRASHED . 'e.id) ' .
            'ORDER BY i.value COLLATE NOCASE LIMIT ' . self::LIMIT
        );
        $statement->execute(['like' => $like]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function entityTypes(string $like): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, status FROM entity_types WHERE name LIKE :like ORDER BY name COLLATE NOCASE LIMIT ' . self::LIMIT
        );
        $statement->execute(['like' => $like]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function contexts(string $like): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, parent_id, name FROM contexts WHERE name LIKE :like ' .
            'AND NOT EXISTS (SELECT 1 FROM context_trash t WHERE t.context_id = contexts.id) ' .
            'ORDER BY name COLLATE NOCASE LIMIT ' . self::LIMIT
        );
        $statement->execute(['like' => $like]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function tags(string $like): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name FROM tags WHERE name LIKE :like ORDER BY name COLLATE NOCASE LIMIT ' . self::LIMIT
        );
        $statement->execute(['like' => $like]);
        return $statement->fetchAll();
    }

    /**
     * Document containing an active occurrence of a KnowledgeObject. This is identity matching:
     * the text is irrelevant, what counts is which object the occurrence declares.
     *
     * @return list<array<string, mixed>>
     */
    public function documentsByObject(string $objectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT d.id, d.title, d.status, k.id AS occurrence_id ' .
            'FROM knowledge_occurrences k JOIN documents d ON d.id = k.document_id ' .
            "WHERE k.knowledge_object_id = :id AND k.status = 'active' " .
            'ORDER BY d.updated_at DESC LIMIT ' . self::LIMIT
        );
        $statement->execute(['id' => $objectId]);
        return $statement->fetchAll();
    }

    /** Rebuilds the whole index from the authoritative content. */
    public function rebuildIndex(): void
    {
        $this->pdo->exec("INSERT INTO documents_fts (documents_fts) VALUES ('rebuild')");
    }
}

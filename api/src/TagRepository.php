<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

use PDO;

/**
 * Tag and their assignment to Document. A Tag is free metadata: it never becomes a Concept, an
 * Entity or an EntityType, and it is never assigned directly to a KnowledgeObject.
 */
final class TagRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->pdo->query(
            'SELECT t.id, t.name, COUNT(dt.document_id) AS documents ' .
            'FROM tags t LEFT JOIN document_tags dt ON dt.tag_id = t.id ' .
            'GROUP BY t.id, t.name ORDER BY t.name COLLATE NOCASE'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(string $tagId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM tags WHERE id = :id');
        $statement->execute(['id' => $tagId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByName(string $name): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM tags WHERE name = :name COLLATE NOCASE');
        $statement->execute(['name' => $name]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed> */
    public function create(string $name): array
    {
        $id = UuidV7::generate();
        $timestamp = Clock::now();
        $this->pdo->prepare('INSERT INTO tags (id, name, created_at, updated_at) VALUES (:id, :name, :created, :updated)')
            ->execute(['id' => $id, 'name' => $name, 'created' => $timestamp, 'updated' => $timestamp]);
        return ['id' => $id, 'name' => $name];
    }

    public function rename(string $tagId, string $name): void
    {
        $this->pdo->prepare('UPDATE tags SET name = :name, updated_at = :updated WHERE id = :id')
            ->execute(['name' => $name, 'updated' => Clock::now(), 'id' => $tagId]);
    }

    public function delete(string $tagId): void
    {
        $this->pdo->prepare('DELETE FROM tags WHERE id = :id')->execute(['id' => $tagId]);
    }

    public function assignmentCount(string $tagId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM document_tags WHERE tag_id = :id');
        $statement->execute(['id' => $tagId]);
        return (int) $statement->fetchColumn();
    }

    /** Assignment is idempotent: the same pair cannot appear twice. */
    public function assign(string $documentId, string $tagId): void
    {
        $this->pdo->prepare(
            'INSERT INTO document_tags (document_id, tag_id, created_at) VALUES (:document_id, :tag_id, :created) ' .
            'ON CONFLICT (document_id, tag_id) DO NOTHING'
        )->execute(['document_id' => $documentId, 'tag_id' => $tagId, 'created' => Clock::now()]);
    }

    public function unassign(string $documentId, string $tagId): void
    {
        $this->pdo->prepare('DELETE FROM document_tags WHERE document_id = :document_id AND tag_id = :tag_id')
            ->execute(['document_id' => $documentId, 'tag_id' => $tagId]);
    }

    /** @return list<array<string, mixed>> */
    public function documentTags(string $documentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.name FROM document_tags dt JOIN tags t ON t.id = dt.tag_id ' .
            'WHERE dt.document_id = :id ORDER BY t.name COLLATE NOCASE'
        );
        $statement->execute(['id' => $documentId]);
        return $statement->fetchAll();
    }

    /**
     * Document ids carrying every requested Tag. An empty list means "no filter", handled by the
     * caller: here an empty list would select nothing.
     *
     * @param list<string> $tagIds
     * @return list<string>
     */
    public function documentIdsWithAllTags(array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT document_id FROM document_tags WHERE tag_id IN ({$placeholders}) " .
            'GROUP BY document_id HAVING COUNT(DISTINCT tag_id) = ?'
        );
        // Il confronto e con un'espressione, non con una colonna: senza affinita un intero legato
        // come testo non corrisponderebbe mai.
        $position = 1;
        foreach ($tagIds as $tagId) {
            $statement->bindValue($position++, $tagId, PDO::PARAM_STR);
        }
        $statement->bindValue($position, count($tagIds), PDO::PARAM_INT);
        $statement->execute();
        return array_column($statement->fetchAll(), 'document_id');
    }
}

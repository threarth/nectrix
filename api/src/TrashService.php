<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;

/**
 * Trash of the three organisers of the chaos: Concept, Entity and Context.
 *
 * Trashing hides them from the lists and from the searches without destroying anything: the
 * occurrences and the marks in the text stay exactly where they are, so a restore brings the index
 * back whole. Only an explicit deletion from the trash removes them, and even then the words of the
 * user are never touched.
 */
final class TrashService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string, mixed> */
    public function trashKnowledgeObject(string $objectId): array
    {
        $this->assertId($objectId);
        $row = $this->fetch('SELECT id, object_type FROM knowledge_objects WHERE id = :id', $objectId);
        if ($row === null) {
            throw new ApiException(404, 'knowledge_object_not_found', 'Concept o Entity non trovati.');
        }
        $this->pdo->prepare(
            'INSERT INTO knowledge_object_trash (knowledge_object_id, trashed_at) VALUES (:id, :at) ' .
            'ON CONFLICT (knowledge_object_id) DO NOTHING'
        )->execute(['id' => $objectId, 'at' => Clock::now()]);
        return ['id' => $objectId, 'objectType' => (string) $row['object_type'], 'trashed' => true];
    }

    /** @return array<string, mixed> */
    public function restoreKnowledgeObject(string $objectId): array
    {
        $this->assertId($objectId);
        $this->pdo->prepare('DELETE FROM knowledge_object_trash WHERE knowledge_object_id = :id')
            ->execute(['id' => $objectId]);
        return ['id' => $objectId, 'trashed' => false];
    }

    /** @return array<string, mixed> */
    public function trashContext(string $contextId): array
    {
        $this->assertId($contextId);
        if ($this->fetch('SELECT id FROM contexts WHERE id = :id', $contextId) === null) {
            throw new ApiException(404, 'context_not_found', 'Context non trovato.');
        }
        $this->pdo->prepare(
            'INSERT INTO context_trash (context_id, trashed_at) VALUES (:id, :at) ' .
            'ON CONFLICT (context_id) DO NOTHING'
        )->execute(['id' => $contextId, 'at' => Clock::now()]);
        return ['id' => $contextId, 'trashed' => true];
    }

    /** @return array<string, mixed> */
    public function restoreContext(string $contextId): array
    {
        $this->assertId($contextId);
        $this->pdo->prepare('DELETE FROM context_trash WHERE context_id = :id')->execute(['id' => $contextId]);
        return ['id' => $contextId, 'trashed' => false];
    }

    /**
     * What sits in the trash, with the ranges each item still holds in the text: the user decides
     * knowing what a definitive deletion would take away.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function list(): array
    {
        $objects = $this->pdo->query(
            'SELECT t.knowledge_object_id AS id, o.object_type, ' .
            'COALESCE(c.canonical_name, e.name) AS name, t.trashed_at, ' .
            "(SELECT COUNT(*) FROM knowledge_occurrences k WHERE k.knowledge_object_id = t.knowledge_object_id " .
            "AND k.status = 'active') AS occurrences " .
            'FROM knowledge_object_trash t ' .
            'JOIN knowledge_objects o ON o.id = t.knowledge_object_id ' .
            'LEFT JOIN concepts c ON c.id = t.knowledge_object_id ' .
            'LEFT JOIN entities e ON e.id = t.knowledge_object_id ' .
            'ORDER BY t.trashed_at DESC'
        )->fetchAll();

        $contexts = $this->pdo->query(
            'SELECT t.context_id AS id, x.name, t.trashed_at, ' .
            "(SELECT COUNT(*) FROM context_occurrences o WHERE o.context_id = t.context_id " .
            "AND o.status = 'active') AS occurrences " .
            'FROM context_trash t JOIN contexts x ON x.id = t.context_id ORDER BY t.trashed_at DESC'
        )->fetchAll();

        return ['knowledgeObjects' => $objects, 'contexts' => $contexts];
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $sql, string $id): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    private function assertId(string $id): void
    {
        if (!UuidV7::isValid($id)) {
            throw new ApiException(422, 'invalid_id', 'ID non valido.');
        }
    }
}

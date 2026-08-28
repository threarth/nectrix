<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;

final class KnowledgeRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string, mixed>> */
    public function search(string $query): array
    {
        $statement = $this->pdo->prepare("SELECT c.id, 'concept' AS object_type, c.canonical_name AS name, NULL AS entity_type_id, NULL AS entity_type_name FROM concepts c WHERE c.canonical_name LIKE :query UNION ALL SELECT e.id, 'entity', e.name, e.entity_type_id, t.name FROM entities e JOIN entity_types t ON t.id = e.entity_type_id WHERE e.name LIKE :query ORDER BY name COLLATE NOCASE LIMIT 30");
        $statement->execute(['query' => '%' . $query . '%']);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function entityTypes(): array
    {
        return $this->pdo->query('SELECT id, name, description FROM entity_types ORDER BY name COLLATE NOCASE')->fetchAll();
    }

    /** @return array<string, mixed> */
    public function createEntityType(string $name): array
    {
        $existing = $this->pdo->prepare('SELECT id, name, description FROM entity_types WHERE name = :name COLLATE NOCASE');
        $existing->execute(['name' => $name]);
        $row = $existing->fetch();
        if ($row !== false) return $row;
        $id = UuidV7::generate();
        $now = Clock::now();
        $statement = $this->pdo->prepare('INSERT INTO entity_types (id, name, created_at, updated_at) VALUES (:id, :name, :created, :updated)');
        $statement->execute(['id' => $id, 'name' => $name, 'created' => $now, 'updated' => $now]);
        return ['id' => $id, 'name' => $name, 'description' => null];
    }

    /**
     * Existence and discriminator of the requested KnowledgeObject, used by the client to decide
     * whether a pasted mark can be kept. Read only: it never creates anything.
     *
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    public function resolveObjects(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare("SELECT id, object_type FROM knowledge_objects WHERE id IN ({$placeholders})");
        $statement->execute($ids);
        return $statement->fetchAll();
    }

    /**
     * Reconciles the occurrence records of a Document with the marks of the revision being saved.
     * Runs inside the save transaction, so any rejection rolls the whole save back and repeating
     * the same save changes nothing further.
     *
     * @param array<string, array<string, string>> $marks
     * @param list<array<string, mixed>> $creates
     */
    public function reconcileOccurrences(string $documentId, array $marks, array $creates): void
    {
        $before = $this->documentOccurrences($documentId);
        $touched = $this->createDeclared($documentId, $marks, $creates);
        $touched += $this->activatePresent($documentId, $marks);
        $touched += $this->detachAbsent($marks, $before);
        $this->refreshConceptStatus($touched);
    }

    /**
     * Occurrence records currently owned by the Document, read before any change.
     *
     * @return array<string, array<string, string>>
     */
    private function documentOccurrences(string $documentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, knowledge_object_id, object_type, status FROM knowledge_occurrences WHERE document_id = :document_id'
        );
        $statement->execute(['document_id' => $documentId]);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[$row['id']] = $row;
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function findOccurrence(string $occurrenceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT knowledge_object_id, object_type, document_id, status FROM knowledge_occurrences WHERE id = :id'
        );
        $statement->execute(['id' => $occurrenceId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Creates the records declared by the client, each one verified against its mark.
     *
     * @param array<string, array<string, string>> $marks
     * @param list<array<string, mixed>> $creates
     * @return array<string, string> KnowledgeObject touched by the save, by discriminator
     */
    private function createDeclared(string $documentId, array $marks, array $creates): array
    {
        $declared = [];
        $touched = [];
        foreach ($creates as $create) {
            $occurrenceId = $create['occurrenceId'] ?? null;
            $objectId = $create['knowledgeObjectId'] ?? null;
            $type = $create['objectType'] ?? null;
            if (!is_string($occurrenceId) || !is_string($objectId) || !is_string($type)
                || !isset($marks[$occurrenceId])
                || $marks[$occurrenceId]['knowledgeObjectId'] !== $objectId
                || $marks[$occurrenceId]['objectType'] !== $type) {
                throw new ApiException(422, 'occurrence_creation_mismatch', 'La creazione occurrence non coincide con il mark.');
            }
            if (isset($declared[$occurrenceId])) {
                throw new ApiException(422, 'occurrence_duplicate', 'Occurrence dichiarata più volte.');
            }
            $declared[$occurrenceId] = true;
            $this->createIfAbsent($documentId, $occurrenceId, $objectId, $type, $create);
            $touched[$objectId] = $type;
        }
        return $touched;
    }

    /**
     * Creates the declared record, or accepts the record that already says exactly the same thing:
     * a declaration repeated after undo must reactivate the occurrence, not fail as a duplicate.
     *
     * @param array<string, mixed> $create
     */
    private function createIfAbsent(string $documentId, string $occurrenceId, string $objectId, string $type, array $create): void
    {
        $existing = $this->findOccurrence($occurrenceId);
        if ($existing !== null) {
            if ($existing['document_id'] !== $documentId
                || $existing['knowledge_object_id'] !== $objectId
                || $existing['object_type'] !== $type) {
                throw new ApiException(422, 'occurrence_duplicate', 'Occurrence ID già usato con un’altra associazione.');
            }
            return;
        }
        if (($create['newObject'] ?? false) !== true) {
            $this->assertObjectExists($objectId, $type);
        } else {
            $this->createObject($objectId, $type, $create);
        }
        $timestamp = Clock::now();
        $this->pdo->prepare(
            'INSERT INTO knowledge_occurrences (id, knowledge_object_id, object_type, document_id, created_at, updated_at) ' .
            'VALUES (:id, :object_id, :type, :document_id, :created, :updated)'
        )->execute([
            'id' => $occurrenceId, 'object_id' => $objectId, 'type' => $type,
            'document_id' => $documentId, 'created' => $timestamp, 'updated' => $timestamp,
        ]);
    }

    /** @param array<string, mixed> $create */
    private function createObject(string $objectId, string $type, array $create): void
    {
        $statement = $this->pdo->prepare('SELECT object_type FROM knowledge_objects WHERE id = :id');
        $statement->execute(['id' => $objectId]);
        $row = $statement->fetch();
        if ($row !== false) {
            if ($row['object_type'] !== $type) {
                throw new ApiException(422, 'knowledge_object_missing', 'Il KnowledgeObject esiste con un discriminator differente.');
            }
            return;
        }

        $name = $create['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new ApiException(422, 'invalid_knowledge_object', 'Nome obbligatorio.');
        }
        $timestamp = Clock::now();
        $this->pdo->prepare('INSERT INTO knowledge_objects (id, object_type, created_at, updated_at) VALUES (:id, :type, :created, :updated)')
            ->execute(['id' => $objectId, 'type' => $type, 'created' => $timestamp, 'updated' => $timestamp]);
        if ($type === 'concept') {
            $this->pdo->prepare('INSERT INTO concepts (id, canonical_name) VALUES (:id, :name)')
                ->execute(['id' => $objectId, 'name' => $name]);
            return;
        }
        $entityTypeId = $create['entityTypeId'] ?? null;
        if (!is_string($entityTypeId)) {
            throw new ApiException(422, 'entity_type_required', 'Una Entity richiede EntityType.');
        }
        $this->pdo->prepare('INSERT INTO entities (id, entity_type_id, name) VALUES (:id, :entity_type_id, :name)')
            ->execute(['id' => $objectId, 'entity_type_id' => $entityTypeId, 'name' => $name]);
    }

    /**
     * Every mark must have a coherent record of the same Document. A record left `detached` by a
     * previous save returns `active`, while a `deleted` one is terminal and blocks the save.
     *
     * @param array<string, array<string, string>> $marks
     * @return array<string, string>
     */
    private function activatePresent(string $documentId, array $marks): array
    {
        $touched = [];
        foreach ($marks as $occurrenceId => $attributes) {
            $row = $this->findOccurrence($occurrenceId);
            if ($row === null
                || $row['document_id'] !== $documentId
                || $row['knowledge_object_id'] !== $attributes['knowledgeObjectId']
                || $row['object_type'] !== $attributes['objectType']) {
                throw new ApiException(422, 'occurrence_not_persisted', 'Il mark occurrence non ha un record persistito coerente.');
            }
            if ($row['status'] === 'deleted') {
                throw new ApiException(422, 'occurrence_deleted', 'Una KnowledgeOccurrence eliminata non può tornare attiva.');
            }
            if ($row['status'] === 'detached') {
                $this->setOccurrenceStatus($occurrenceId, 'active');
                $touched[$row['knowledge_object_id']] = $row['object_type'];
            }
        }
        return $touched;
    }

    /**
     * An active record whose mark is no longer in the saved revision becomes `detached`. Nothing is
     * removed physically, so undo followed by a new save can bring it back.
     *
     * @param array<string, array<string, string>> $marks
     * @param array<string, array<string, string>> $before
     * @return array<string, string>
     */
    private function detachAbsent(array $marks, array $before): array
    {
        $touched = [];
        foreach ($before as $occurrenceId => $row) {
            if ($row['status'] !== 'active' || isset($marks[$occurrenceId])) {
                continue;
            }
            $this->setOccurrenceStatus($occurrenceId, 'detached');
            $touched[$row['knowledge_object_id']] = $row['object_type'];
        }
        return $touched;
    }

    private function setOccurrenceStatus(string $occurrenceId, string $status): void
    {
        $this->pdo->prepare('UPDATE knowledge_occurrences SET status = :status, updated_at = :updated WHERE id = :id')
            ->execute(['status' => $status, 'updated' => Clock::now(), 'id' => $occurrenceId]);
    }

    /**
     * A Concept that loses its last active occurrence becomes `orphan`, and returns `active` when
     * one comes back. Entities never use this state and no KnowledgeObject is ever deleted.
     *
     * @param array<string, string> $touched
     */
    private function refreshConceptStatus(array $touched): void
    {
        foreach ($touched as $objectId => $type) {
            if ($type !== 'concept') {
                continue;
            }
            $current = $this->pdo->prepare('SELECT status FROM concepts WHERE id = :id');
            $current->execute(['id' => $objectId]);
            $status = $current->fetchColumn();
            $active = $this->pdo->prepare(
                "SELECT COUNT(*) FROM knowledge_occurrences WHERE knowledge_object_id = :id AND status = 'active'"
            );
            $active->execute(['id' => $objectId]);
            $count = (int) $active->fetchColumn();
            if ($status === 'active' && $count === 0) {
                $this->setConceptStatus($objectId, 'orphan');
            }
            if ($status === 'orphan' && $count > 0) {
                $this->setConceptStatus($objectId, 'active');
            }
        }
    }

    private function setConceptStatus(string $objectId, string $status): void
    {
        $this->pdo->prepare('UPDATE concepts SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $objectId]);
    }

    /** INV-OCC-15: associating an unknown KnowledgeObject fails, it never creates one implicitly. */
    private function assertObjectExists(string $objectId, string $type): void
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM knowledge_objects WHERE id = :id AND object_type = :type');
        $statement->execute(['id' => $objectId, 'type' => $type]);
        if ($statement->fetch() === false) {
            throw new ApiException(422, 'knowledge_object_missing', 'Il KnowledgeObject associato non esiste o ha un discriminator differente.');
        }
    }
}

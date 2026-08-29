<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;

final class KnowledgeRepository
{
    /** Un oggetto cestinato sparisce dagli elenchi, ma le sue occurrence restano nel testo. */
    private const NOT_TRASHED =
        'NOT EXISTS (SELECT 1 FROM knowledge_object_trash t WHERE t.knowledge_object_id = ';

    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string, mixed>> */
    public function search(string $query): array
    {
        // Un Concept compare una sola volta anche se piu alias corrispondono: l'ambiguita si vede
        // come Concept distinti, non come righe ripetute dello stesso Concept.
        $statement = $this->pdo->prepare(
            "SELECT c.id, 'concept' AS object_type, c.canonical_name AS name, NULL AS entity_type_id, NULL AS entity_type_name " .
            'FROM concepts c WHERE ' . self::NOT_TRASHED . 'c.id) AND (c.canonical_name LIKE :query ' .
            'OR EXISTS (SELECT 1 FROM concept_aliases a WHERE a.concept_id = c.id AND a.alias LIKE :query)) ' .
            "UNION ALL SELECT e.id, 'entity', e.name, e.entity_type_id, t.name " .
            'FROM entities e JOIN entity_types t ON t.id = e.entity_type_id ' .
            'WHERE ' . self::NOT_TRASHED . 'e.id) AND e.name LIKE :query ' .
            'ORDER BY name COLLATE NOCASE LIMIT 30'
        );
        $statement->execute(['query' => '%' . $query . '%']);
        return $statement->fetchAll();
    }

    /**
     * The three organisers together — Concept, Entity and Context — because when the user is
     * marking a fragment they are choosing where it belongs, and that choice is not split in three
     * separate searches. A Context carries its path, so two homonyms of different branches are told
     * apart at a glance.
     *
     * @return list<array<string, mixed>>
     */
    public function searchIndex(string $query): array
    {
        $statement = $this->pdo->prepare(
            "SELECT c.id, 'concept' AS object_type, c.canonical_name AS name, NULL AS entity_type_name, NULL AS parent_id " .
            'FROM concepts c WHERE ' . self::NOT_TRASHED . 'c.id) AND (c.canonical_name LIKE :query ' .
            'OR EXISTS (SELECT 1 FROM concept_aliases a WHERE a.concept_id = c.id AND a.alias LIKE :query)) ' .
            "UNION ALL SELECT e.id, 'entity', e.name, t.name, NULL " .
            'FROM entities e JOIN entity_types t ON t.id = e.entity_type_id ' .
            'WHERE ' . self::NOT_TRASHED . 'e.id) AND e.name LIKE :query ' .
            "UNION ALL SELECT x.id, 'context', x.name, NULL, x.parent_id " .
            'FROM contexts x WHERE x.name LIKE :query ' .
            'AND NOT EXISTS (SELECT 1 FROM context_trash ct WHERE ct.context_id = x.id) ' .
            'ORDER BY name COLLATE NOCASE LIMIT 40'
        );
        $statement->execute(['query' => '%' . $query . '%']);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function entityTypes(): array
    {
        return $this->pdo->query('SELECT id, name, description, status FROM entity_types ORDER BY name COLLATE NOCASE')->fetchAll();
    }

    /** @return array<string, mixed> */
    public function createEntityType(string $name): array
    {
        $existing = $this->pdo->prepare('SELECT id, name, description, status FROM entity_types WHERE name = :name COLLATE NOCASE');
        $existing->execute(['name' => $name]);
        $row = $existing->fetch();
        if ($row !== false) return $row;
        $id = UuidV7::generate();
        $now = Clock::now();
        $statement = $this->pdo->prepare('INSERT INTO entity_types (id, name, created_at, updated_at) VALUES (:id, :name, :created, :updated)');
        $statement->execute(['id' => $id, 'name' => $name, 'created' => $now, 'updated' => $now]);
        return ['id' => $id, 'name' => $name, 'description' => null, 'status' => 'active'];
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
     * Identity, presentation fields and lifecycle of one KnowledgeObject, or null when unknown.
     * Never returns occurrence text: that lives only in the Document content.
     *
     * @return array<string, mixed>|null
     */
    public function objectDetail(string $objectId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT o.id, o.object_type, c.canonical_name, c.description AS concept_description, c.status AS concept_status, " .
            "e.name AS entity_name, e.description AS entity_description, e.status AS entity_status, " .
            "t.id AS entity_type_id, t.name AS entity_type_name, t.status AS entity_type_status " .
            "FROM knowledge_objects o " .
            "LEFT JOIN concepts c ON c.id = o.id " .
            "LEFT JOIN entities e ON e.id = o.id " .
            "LEFT JOIN entity_types t ON t.id = e.entity_type_id " .
            "WHERE o.id = :id"
        );
        $statement->execute(['id' => $objectId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Occurrence records of a KnowledgeObject with the content of the owning Document, so the
     * caller can extract the current text instead of reading a stale copy.
     *
     * @return list<array<string, mixed>>
     */
    public function objectOccurrences(string $objectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT k.id, k.document_id, k.status, d.title AS document_title, d.document_json ' .
            'FROM knowledge_occurrences k JOIN documents d ON d.id = k.document_id ' .
            'WHERE k.knowledge_object_id = :id ORDER BY d.updated_at DESC, k.created_at'
        );
        $statement->execute(['id' => $objectId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function conceptAliases(string $conceptId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, alias FROM concept_aliases WHERE concept_id = :id ORDER BY alias COLLATE NOCASE'
        );
        $statement->execute(['id' => $conceptId]);
        return $statement->fetchAll();
    }

    /**
     * INV-ALS-01: adding an alias touches no occurrence. The same alias may belong to different
     * Concept, because language is ambiguous; only a repetition inside one Concept is refused.
     */
    public function addConceptAlias(string $conceptId, string $alias): void
    {
        $existing = $this->pdo->prepare('SELECT 1 FROM concept_aliases WHERE concept_id = :id AND alias = :alias COLLATE NOCASE');
        $existing->execute(['id' => $conceptId, 'alias' => $alias]);
        if ($existing->fetch() !== false) {
            throw new ApiException(422, 'alias_duplicate', 'Il Concept ha già questo alias.');
        }
        $this->pdo->prepare('INSERT INTO concept_aliases (id, concept_id, alias) VALUES (:id, :concept_id, :alias)')
            ->execute(['id' => UuidV7::generate(), 'concept_id' => $conceptId, 'alias' => $alias]);
    }

    /** @return array<string, mixed>|null */
    public function findConceptAlias(string $aliasId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, concept_id, alias FROM concept_aliases WHERE id = :id');
        $statement->execute(['id' => $aliasId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function removeConceptAlias(string $aliasId): void
    {
        $this->pdo->prepare('DELETE FROM concept_aliases WHERE id = :id')->execute(['id' => $aliasId]);
    }

    /** @return list<array<string, mixed>> */
    public function entityIdentifiers(string $entityId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, scheme, value, normalized_value, authority_or_namespace, normalization_version ' .
            'FROM entity_identifiers WHERE entity_id = :id ORDER BY scheme, normalized_value'
        );
        $statement->execute(['id' => $entityId]);
        return $statement->fetchAll();
    }

    /**
     * INV-EID-02: the normalised identity is unique inside one Entity. The authority takes part in
     * it, and an absent authority is compared as the empty key so two NULL are not distinct.
     */
    public function addEntityIdentifier(string $entityId, string $scheme, string $value, string $normalized, ?string $authority, int $version): void
    {
        $existing = $this->pdo->prepare(
            'SELECT 1 FROM entity_identifiers WHERE entity_id = :entity_id AND scheme = :scheme ' .
            "AND normalized_value = :normalized AND ifnull(authority_or_namespace, '') = :authority_key"
        );
        $existing->execute([
            'entity_id' => $entityId, 'scheme' => $scheme,
            'normalized' => $normalized, 'authority_key' => $authority ?? '',
        ]);
        if ($existing->fetch() !== false) {
            throw new ApiException(422, 'identifier_duplicate', 'La Entity ha già questo identificatore.');
        }
        $timestamp = Clock::now();
        $this->pdo->prepare(
            'INSERT INTO entity_identifiers ' .
            '(id, entity_id, scheme, value, normalized_value, authority_or_namespace, normalization_version, created_at, updated_at) ' .
            'VALUES (:id, :entity_id, :scheme, :value, :normalized, :authority, :version, :created, :updated)'
        )->execute([
            'id' => UuidV7::generate(), 'entity_id' => $entityId, 'scheme' => $scheme, 'value' => $value,
            'normalized' => $normalized, 'authority' => $authority, 'version' => $version,
            'created' => $timestamp, 'updated' => $timestamp,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findEntityIdentifier(string $identifierId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, entity_id, scheme FROM entity_identifiers WHERE id = :id');
        $statement->execute(['id' => $identifierId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function removeEntityIdentifier(string $identifierId): void
    {
        $this->pdo->prepare('DELETE FROM entity_identifiers WHERE id = :id')->execute(['id' => $identifierId]);
    }

    /**
     * Other Entity already declaring the same normalised identity. They are duplicate candidates,
     * never a reason to merge automatically.
     *
     * @return list<array<string, mixed>>
     */
    public function duplicateIdentifierCandidates(string $scheme, string $normalized, ?string $authority, string $entityId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT e.id, e.name FROM entity_identifiers i JOIN entities e ON e.id = i.entity_id ' .
            'WHERE i.scheme = :scheme AND i.normalized_value = :normalized ' .
            "AND ifnull(i.authority_or_namespace, '') = :authority_key AND i.entity_id <> :entity_id " .
            'ORDER BY e.name COLLATE NOCASE'
        );
        $statement->execute([
            'scheme' => $scheme, 'normalized' => $normalized,
            'authority_key' => $authority ?? '', 'entity_id' => $entityId,
        ]);
        return $statement->fetchAll();
    }

    /**
     * Concept and Entity reached from a set of Document through their active occurrence. The path
     * is always explicit: no KnowledgeObject carries a Context or a Tag of its own, and an object
     * present in several of those Document is listed once.
     *
     * @param list<string> $documentIds
     * @return list<array<string, mixed>>
     */
    public function objectsInDocuments(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT o.id, o.object_type, coalesce(c.canonical_name, e.name) AS name ' .
            'FROM knowledge_occurrences k ' .
            'JOIN knowledge_objects o ON o.id = k.knowledge_object_id ' .
            'LEFT JOIN concepts c ON c.id = o.id ' .
            'LEFT JOIN entities e ON e.id = o.id ' .
            "WHERE k.status = 'active' AND k.document_id IN ({$placeholders}) " .
            'AND ' . self::NOT_TRASHED . 'o.id) ORDER BY name COLLATE NOCASE'
        );
        $statement->execute($documentIds);
        return $statement->fetchAll();
    }

    /**
     * Renames a KnowledgeObject and rewrites its description. Identity, occurrence, alias and
     * identifier restano invariati: cambia soltanto come lo chiami.
     */
    public function updateObject(string $objectId, string $type, string $name, ?string $description): void
    {
        $table = $type === 'concept' ? 'concepts' : 'entities';
        $column = $type === 'concept' ? 'canonical_name' : 'name';
        $statement = $this->pdo->prepare(
            "UPDATE {$table} SET {$column} = :name, description = :description WHERE id = :id"
        );
        $statement->execute(['name' => $name, 'description' => $description, 'id' => $objectId]);
        $this->pdo->prepare('UPDATE knowledge_objects SET updated_at = :updated WHERE id = :id')
            ->execute(['updated' => Clock::now(), 'id' => $objectId]);
    }

    /** Archives a Concept or an Entity. Nothing is deleted and no occurrence changes state. */
    public function archiveObject(string $objectId, string $type): void
    {
        if ($type === 'concept') {
            $this->setConceptStatus($objectId, 'archived');
            return;
        }
        $this->setEntityStatus($objectId, 'archived');
    }

    /**
     * Restores an archived KnowledgeObject. A Concept comes back `active` only if it still has an
     * active occurrence, otherwise it comes back `orphan`.
     */
    public function restoreObject(string $objectId, string $type): void
    {
        if ($type === 'entity') {
            $this->setEntityStatus($objectId, 'active');
            return;
        }
        $this->setConceptStatus($objectId, $this->activeOccurrenceCount($objectId) > 0 ? 'active' : 'orphan');
    }

    /** @return array<string, mixed>|null */
    public function findEntityType(string $entityTypeId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name, description, status FROM entity_types WHERE id = :id');
        $statement->execute(['id' => $entityTypeId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function setEntityTypeStatus(string $entityTypeId, string $status): void
    {
        $this->pdo->prepare('UPDATE entity_types SET status = :status, updated_at = :updated WHERE id = :id')
            ->execute(['status' => $status, 'updated' => Clock::now(), 'id' => $entityTypeId]);
    }

    private function activeOccurrenceCount(string $objectId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM knowledge_occurrences WHERE knowledge_object_id = :id AND status = 'active'"
        );
        $statement->execute(['id' => $objectId]);
        return (int) $statement->fetchColumn();
    }

    private function setEntityStatus(string $objectId, string $status): void
    {
        $this->pdo->prepare('UPDATE entities SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $objectId]);
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
        $entityType = $this->findEntityType($entityTypeId);
        if ($entityType !== null && $entityType['status'] === 'archived') {
            throw new ApiException(422, 'entity_type_archived', 'L’EntityType è archiviato: ripristinalo prima di creare nuove Entity.');
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
     * Public because the purge of a Document has to refresh the Concept it touches as well.
     *
     * @param array<string, string> $touched KnowledgeObject id => discriminator
     */
    public function refreshConceptStatus(array $touched): void
    {
        foreach ($touched as $objectId => $type) {
            if ($type !== 'concept') {
                continue;
            }
            $current = $this->pdo->prepare('SELECT status FROM concepts WHERE id = :id');
            $current->execute(['id' => $objectId]);
            $status = $current->fetchColumn();
            $count = $this->activeOccurrenceCount($objectId);
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

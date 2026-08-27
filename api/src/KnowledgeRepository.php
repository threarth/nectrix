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

    /** @param array<string, array<string, string>> $marks @param list<array<string, mixed>> $creates */
    public function assertAndCreateOccurrences(string $documentId, array $marks, array $creates): void
    {
        $declared = [];
        foreach ($creates as $create) {
            $occurrenceId = $create['occurrenceId'] ?? null;
            $objectId = $create['knowledgeObjectId'] ?? null;
            $type = $create['objectType'] ?? null;
            if (!is_string($occurrenceId) || !is_string($objectId) || !is_string($type) || !isset($marks[$occurrenceId]) || $marks[$occurrenceId]['knowledgeObjectId'] !== $objectId || $marks[$occurrenceId]['objectType'] !== $type) {
                throw new ApiException(422, 'occurrence_creation_mismatch', 'La creazione occurrence non coincide con il mark.');
            }
            if (isset($declared[$occurrenceId])) throw new ApiException(422, 'occurrence_duplicate', 'Occurrence dichiarata più volte.');
            $declared[$occurrenceId] = true;
            $this->create($documentId, $occurrenceId, $objectId, $type, $create);
        }
        foreach ($marks as $occurrenceId => $attrs) {
            $statement = $this->pdo->prepare('SELECT knowledge_object_id, object_type, document_id FROM knowledge_occurrences WHERE id = :id');
            $statement->execute(['id' => $occurrenceId]);
            $row = $statement->fetch();
            if ($row === false || $row['knowledge_object_id'] !== $attrs['knowledgeObjectId'] || $row['object_type'] !== $attrs['objectType'] || $row['document_id'] !== $documentId) {
                throw new ApiException(422, 'occurrence_not_persisted', 'Il mark occurrence non ha un record persistito coerente.');
            }
        }
    }

    /** @param array<string, mixed> $create */
    private function create(string $documentId, string $occurrenceId, string $objectId, string $type, array $create): void
    {
        $timestamp = Clock::now();
        $existing = $this->pdo->prepare('SELECT 1 FROM knowledge_occurrences WHERE id = :id');
        $existing->execute(['id' => $occurrenceId]);
        if ($existing->fetch() !== false) throw new ApiException(422, 'occurrence_duplicate', 'Occurrence ID già esistente.');
        if (($create['newObject'] ?? false) === true) {
            $name = $create['name'] ?? null;
            if (!is_string($name) || trim($name) === '') throw new ApiException(422, 'invalid_knowledge_object', 'Nome obbligatorio.');
            $this->pdo->prepare('INSERT INTO knowledge_objects (id, object_type, created_at, updated_at) VALUES (:id, :type, :created, :updated)')->execute(['id'=>$objectId,'type'=>$type,'created'=>$timestamp,'updated'=>$timestamp]);
            if ($type === 'concept') {
                $this->pdo->prepare('INSERT INTO concepts (id, canonical_name) VALUES (:id, :name)')->execute(['id'=>$objectId,'name'=>$name]);
            } else {
                $entityTypeId = $create['entityTypeId'] ?? null;
                if (!is_string($entityTypeId)) throw new ApiException(422, 'entity_type_required', 'Una Entity richiede EntityType.');
                $this->pdo->prepare('INSERT INTO entities (id, entity_type_id, name) VALUES (:id, :entity_type_id, :name)')->execute(['id'=>$objectId,'entity_type_id'=>$entityTypeId,'name'=>$name]);
            }
        }
        $this->pdo->prepare('INSERT INTO knowledge_occurrences (id, knowledge_object_id, object_type, document_id, created_at, updated_at) VALUES (:id, :object_id, :type, :document_id, :created, :updated)')->execute(['id'=>$occurrenceId,'object_id'=>$objectId,'type'=>$type,'document_id'=>$documentId,'created'=>$timestamp,'updated'=>$timestamp]);
    }
}

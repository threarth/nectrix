<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

use PDO;

/**
 * Deletion of the three organisers of the chaos: Concept, Entity and Context.
 *
 * Deleting one of them is an ordinary command, not an exception: they exist to give order to
 * disordered notes, and an index one cannot undo is a trap. What disappears is the index — the
 * ranges, the marks in the text and everything owned by the object — never the words of the user,
 * which stay exactly where they were written.
 *
 * The whole operation runs in one transaction: either the documents lose the marks and the records
 * disappear together, or nothing changes at all.
 */
final class DeletionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DocumentRepository $documents,
        private readonly DocumentPruner $pruner,
        private readonly PlainTextExtractor $plainText,
    ) {
    }

    /**
     * Removes a Concept or an Entity, its occurrences, the knowledge it owns and every trace it
     * left in the documents.
     *
     * @return array<string, mixed> what the deletion took away
     */
    public function deleteKnowledgeObject(string $objectId): array
    {
        $object = $this->requireObject($objectId);
        $occurrences = $this->columnOf(
            'SELECT id FROM knowledge_occurrences WHERE knowledge_object_id = :id',
            ['id' => $objectId],
        );
        $references = $object['object_type'] === 'entity' ? [$objectId] : [];

        return $this->inTransaction(function () use ($objectId, $object, $occurrences, $references): array {
            $blocked = $this->structuredReferences($objectId);
            if ($blocked > 0) {
                throw new ApiException(409, 'knowledge_object_referenced', "L’oggetto è usato da {$blocked} FieldValue di altre Entity: cambia quei valori prima di eliminarlo.", [
                    'fieldValues' => $blocked,
                ]);
            }
            $documents = $this->pruneDocuments($occurrences, [], $references);
            $this->deleteOwnedKnowledge($objectId, $object['object_type'], $occurrences);
            return [
                'id' => $objectId,
                'objectType' => $object['object_type'],
                'occurrences' => count($occurrences),
                'documents' => $documents,
            ];
        });
    }

    /**
     * Removes a Context, its ranges and the containment they declared. Sub-context are refused:
     * their meaning depends on the parent, so they are moved or deleted first, on purpose.
     *
     * @return array<string, mixed>
     */
    public function deleteContext(string $contextId): array
    {
        $this->requireContext($contextId);
        if ($this->columnOf('SELECT id FROM contexts WHERE parent_id = :id', ['id' => $contextId]) !== []) {
            throw new ApiException(409, 'context_has_children', 'Il Context ha sub-context: eliminali o spostali prima.');
        }
        $occurrences = $this->columnOf(
            'SELECT id FROM context_occurrences WHERE context_id = :id',
            ['id' => $contextId],
        );

        return $this->inTransaction(function () use ($contextId, $occurrences): array {
            $documents = $this->pruneDocuments([], $occurrences, []);
            $this->execute('DELETE FROM context_trash WHERE context_id = :id', ['id' => $contextId]);
            $this->execute('DELETE FROM context_memberships WHERE context_id = :id', ['id' => $contextId]);
            $this->execute('DELETE FROM context_occurrences WHERE context_id = :id', ['id' => $contextId]);
            $this->execute('DELETE FROM contexts WHERE id = :id', ['id' => $contextId]);
            return ['id' => $contextId, 'occurrences' => count($occurrences), 'documents' => $documents];
        });
    }

    /**
     * Rewrites every Document that carried a trace of what is being deleted. The revision advances,
     * so an editor still holding the old text discovers the conflict instead of resurrecting a mark.
     *
     * @param list<string> $occurrenceIds
     * @param list<string> $contextOccurrenceIds
     * @param list<string> $referenceDestinations
     * @return int Document actually rewritten
     */
    private function pruneDocuments(array $occurrenceIds, array $contextOccurrenceIds, array $referenceDestinations): int
    {
        $touched = 0;
        foreach ($this->documentsToPrune($occurrenceIds, $contextOccurrenceIds, $referenceDestinations) as $documentId) {
            $document = $this->documents->get($documentId);
            [$pruned, $changed] = $this->pruner->prune(
                $document['documentJson'],
                $occurrenceIds,
                $contextOccurrenceIds,
                $referenceDestinations,
            );
            if (!$changed) {
                continue;
            }
            $this->documents->replaceContent($documentId, $pruned, $this->plainText->extract($pruned));
            $touched++;
        }
        return $touched;
    }

    /**
     * @param list<string> $occurrenceIds
     * @param list<string> $contextOccurrenceIds
     * @param list<string> $referenceDestinations
     * @return list<string>
     */
    private function documentsToPrune(array $occurrenceIds, array $contextOccurrenceIds, array $referenceDestinations): array
    {
        $ids = [];
        foreach ($this->owningDocuments('knowledge_occurrences', $occurrenceIds) as $id) {
            $ids[$id] = true;
        }
        foreach ($this->owningDocuments('context_occurrences', $contextOccurrenceIds) as $id) {
            $ids[$id] = true;
        }
        if ($referenceDestinations !== []) {
            // Un riferimento editoriale vive solo nel document_json: si cerca nel testo salvato.
            foreach ($referenceDestinations as $destination) {
                foreach ($this->columnOf(
                    'SELECT id FROM documents WHERE document_json LIKE :like',
                    ['like' => '%' . $destination . '%'],
                ) as $id) {
                    $ids[$id] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /**
     * @param list<string> $occurrenceIds
     * @return list<string>
     */
    private function owningDocuments(string $table, array $occurrenceIds): array
    {
        if ($occurrenceIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($occurrenceIds), '?'));
        $statement = $this->pdo->prepare("SELECT DISTINCT document_id FROM {$table} WHERE id IN ({$placeholders})");
        $statement->execute($occurrenceIds);
        return array_column($statement->fetchAll(), 'document_id');
    }

    /**
     * Everything owned by the object: what it declared, what pointed at its occurrences, and the
     * structured blocks that exist only because the Entity existed.
     *
     * @param list<string> $occurrenceIds
     */
    private function deleteOwnedKnowledge(string $objectId, string $type, array $occurrenceIds): void
    {
        $this->execute('DELETE FROM knowledge_object_trash WHERE knowledge_object_id = :id', ['id' => $objectId]);
        $this->deleteEvidenceOfOccurrences($occurrenceIds);
        $this->execute('DELETE FROM context_memberships WHERE knowledge_object_id = :id', ['id' => $objectId]);
        $this->deleteRelationsOf($objectId);
        $this->execute('DELETE FROM knowledge_occurrences WHERE knowledge_object_id = :id', ['id' => $objectId]);
        if ($type === 'concept') {
            $this->execute('DELETE FROM concept_aliases WHERE concept_id = :id', ['id' => $objectId]);
            $this->execute('DELETE FROM concepts WHERE id = :id', ['id' => $objectId]);
        } else {
            $this->deleteEntityStructuredData($objectId);
            $this->execute('DELETE FROM entity_identifiers WHERE entity_id = :id', ['id' => $objectId]);
            $this->execute('DELETE FROM entities WHERE id = :id', ['id' => $objectId]);
        }
        $this->execute('DELETE FROM knowledge_objects WHERE id = :id', ['id' => $objectId]);
    }

    /** @param list<string> $occurrenceIds */
    private function deleteEvidenceOfOccurrences(array $occurrenceIds): void
    {
        foreach ($occurrenceIds as $occurrenceId) {
            $this->execute('DELETE FROM evidence_occurrences WHERE knowledge_occurrence_id = :id', ['id' => $occurrenceId]);
        }
    }

    private function deleteRelationsOf(string $objectId): void
    {
        $relations = $this->columnOf(
            'SELECT id FROM knowledge_relations WHERE source_knowledge_object_id = :id ' .
            'OR target_knowledge_object_id = :id',
            ['id' => $objectId],
        );
        foreach ($relations as $relationId) {
            foreach (['evidence_documents', 'evidence_occurrences', 'evidence_semantic_blocks', 'evidence_field_values'] as $table) {
                $this->execute("DELETE FROM {$table} WHERE relation_id = :id", ['id' => $relationId]);
            }
            $this->execute('DELETE FROM knowledge_relations WHERE id = :id', ['id' => $relationId]);
        }
    }

    private function deleteEntityStructuredData(string $entityId): void
    {
        $blocks = $this->columnOf('SELECT id FROM semantic_blocks WHERE entity_id = :id', ['id' => $entityId]);
        foreach ($blocks as $blockId) {
            $values = $this->columnOf('SELECT id FROM field_values WHERE semantic_block_id = :id', ['id' => $blockId]);
            foreach ($values as $valueId) {
                foreach (['evidence_documents', 'evidence_occurrences', 'evidence_semantic_blocks', 'evidence_field_values'] as $table) {
                    $this->execute("DELETE FROM {$table} WHERE field_value_id = :id", ['id' => $valueId]);
                }
                $this->execute('DELETE FROM evidence_field_values WHERE target_field_value_id = :id', ['id' => $valueId]);
            }
            $this->execute('DELETE FROM evidence_semantic_blocks WHERE semantic_block_id = :id', ['id' => $blockId]);
            $this->execute('DELETE FROM field_values WHERE semantic_block_id = :id', ['id' => $blockId]);
            $this->execute('DELETE FROM semantic_blocks WHERE id = :id', ['id' => $blockId]);
        }
    }

    /** FieldValue of other Entity pointing here: data the deletion would silently corrupt. */
    private function structuredReferences(string $objectId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM field_values v JOIN semantic_blocks b ON b.id = v.semantic_block_id ' .
            'WHERE b.entity_id <> :id AND (v.entity_reference_id = :id OR v.concept_reference_id = :id ' .
            'OR v.linked_concept_id = :id)'
        );
        $statement->execute(['id' => $objectId]);
        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function requireObject(string $objectId): array
    {
        if (!UuidV7::isValid($objectId)) {
            throw new ApiException(422, 'invalid_id', 'ID non valido.');
        }
        $statement = $this->pdo->prepare('SELECT id, object_type FROM knowledge_objects WHERE id = :id');
        $statement->execute(['id' => $objectId]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new ApiException(404, 'knowledge_object_not_found', 'Concept o Entity non trovati.');
        }
        return $row;
    }

    private function requireContext(string $contextId): void
    {
        if (!UuidV7::isValid($contextId)) {
            throw new ApiException(422, 'invalid_id', 'ID non valido.');
        }
        $statement = $this->pdo->prepare('SELECT 1 FROM contexts WHERE id = :id');
        $statement->execute(['id' => $contextId]);
        if ($statement->fetch() === false) {
            throw new ApiException(404, 'context_not_found', 'Context non trovato.');
        }
    }

    /**
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function inTransaction(callable $operation): array
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $parameters @return list<string> */
    private function columnOf(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return array_map(static fn (array $row): string => (string) array_values($row)[0], $statement->fetchAll());
    }

    /** @param array<string, mixed> $parameters */
    private function execute(string $sql, array $parameters): void
    {
        $this->pdo->prepare($sql)->execute($parameters);
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

use PDO;

/**
 * Persistence of the ContextOccurrence and of the containment they derive.
 *
 * The reconciliation mirrors the one of the KnowledgeOccurrence: what the saved revision declares
 * becomes `active`, what disappears becomes `detached`, and nothing is removed physically, so an
 * undo followed by a save brings the range back. The membership table is derived and is rewritten
 * whole for the Document at every save: it never holds authoritative data.
 */
final class ContextOccurrenceRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<string, array<string, string>> $marks by occurrenceId
     * @param list<array<string, mixed>> $creates declared by the client
     * @param list<array{contextOccurrenceId: string, knowledgeOccurrenceId: string}> $memberships
     */
    public function reconcile(string $documentId, array $marks, array $creates, array $memberships): void
    {
        $before = $this->documentOccurrences($documentId);
        $this->createDeclared($documentId, $marks, $creates);
        $this->activatePresent($documentId, $marks);
        $this->detachAbsent($marks, $before);
        $this->rewriteMemberships($documentId, $marks, $memberships);
    }

    /** @return array<string, array<string, string>> */
    private function documentOccurrences(string $documentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, context_id, status FROM context_occurrences WHERE document_id = :document_id'
        );
        $statement->execute(['document_id' => $documentId]);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['id']] = $row;
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function find(string $occurrenceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, context_id, document_id, status FROM context_occurrences WHERE id = :id'
        );
        $statement->execute(['id' => $occurrenceId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, array<string, string>> $marks
     * @param list<array<string, mixed>> $creates
     */
    private function createDeclared(string $documentId, array $marks, array $creates): void
    {
        $declared = [];
        foreach ($creates as $create) {
            $occurrenceId = $create['occurrenceId'] ?? null;
            $contextId = $create['contextId'] ?? null;
            if (!is_string($occurrenceId) || !is_string($contextId)
                || !isset($marks[$occurrenceId])
                || $marks[$occurrenceId]['contextId'] !== $contextId) {
                throw new ApiException(422, 'context_occurrence_mismatch', 'La creazione della ContextOccurrence non coincide con il mark.');
            }
            if (isset($declared[$occurrenceId])) {
                throw new ApiException(422, 'context_occurrence_duplicate', 'ContextOccurrence dichiarata più volte.');
            }
            $declared[$occurrenceId] = true;
            $this->createIfAbsent($documentId, $occurrenceId, $contextId);
        }
    }

    /** A declaration repeated after an undo reactivates the range instead of failing as duplicate. */
    private function createIfAbsent(string $documentId, string $occurrenceId, string $contextId): void
    {
        $existing = $this->find($occurrenceId);
        if ($existing !== null) {
            if ($existing['document_id'] !== $documentId || $existing['context_id'] !== $contextId) {
                throw new ApiException(422, 'context_occurrence_duplicate', 'ID di ContextOccurrence già usato con un altro Context.');
            }
            return;
        }
        $this->assertContextExists($contextId);
        $timestamp = Clock::now();
        $this->pdo->prepare(
            'INSERT INTO context_occurrences (id, context_id, document_id, created_at, updated_at) ' .
            'VALUES (:id, :context_id, :document_id, :created, :updated)'
        )->execute([
            'id' => $occurrenceId, 'context_id' => $contextId,
            'document_id' => $documentId, 'created' => $timestamp, 'updated' => $timestamp,
        ]);
    }

    /** @param array<string, array<string, string>> $marks */
    private function activatePresent(string $documentId, array $marks): void
    {
        foreach ($marks as $occurrenceId => $attributes) {
            $row = $this->find((string) $occurrenceId);
            if ($row === null || $row['document_id'] !== $documentId || $row['context_id'] !== $attributes['contextId']) {
                throw new ApiException(422, 'context_occurrence_not_persisted', 'Il mark contextOccurrence non ha un record persistito coerente.');
            }
            if ($row['status'] === 'deleted') {
                throw new ApiException(422, 'context_occurrence_deleted', 'Una ContextOccurrence eliminata non può tornare attiva.');
            }
            if ($row['status'] === 'detached') {
                $this->setStatus((string) $occurrenceId, 'active');
            }
        }
    }

    /**
     * @param array<string, array<string, string>> $marks
     * @param array<string, array<string, string>> $before
     */
    private function detachAbsent(array $marks, array $before): void
    {
        foreach ($before as $occurrenceId => $row) {
            if (isset($marks[$occurrenceId]) || $row['status'] !== 'active') {
                continue;
            }
            $this->setStatus((string) $occurrenceId, 'detached');
        }
    }

    private function setStatus(string $occurrenceId, string $status): void
    {
        $this->pdo->prepare('UPDATE context_occurrences SET status = :status, updated_at = :updated WHERE id = :id')
            ->execute(['status' => $status, 'updated' => Clock::now(), 'id' => $occurrenceId]);
    }

    /**
     * Rewrites the derived containment of the Document. Only an active range of the same Document
     * produces membership, and only a fragment covered entirely, as the extractor established.
     *
     * @param array<string, array<string, string>> $marks
     * @param list<array{contextOccurrenceId: string, knowledgeOccurrenceId: string}> $memberships
     */
    private function rewriteMemberships(string $documentId, array $marks, array $memberships): void
    {
        $this->pdo->prepare('DELETE FROM context_memberships WHERE document_id = :document_id')
            ->execute(['document_id' => $documentId]);
        if ($memberships === []) {
            return;
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO context_memberships (context_occurrence_id, knowledge_occurrence_id, context_id, ' .
            'knowledge_object_id, object_type, document_id) ' .
            'SELECT :context_occurrence_id, k.id, :context_id, k.knowledge_object_id, k.object_type, :document_id ' .
            "FROM knowledge_occurrences k WHERE k.id = :knowledge_occurrence_id AND k.status = 'active'"
        );
        foreach ($memberships as $membership) {
            $contextOccurrenceId = $membership['contextOccurrenceId'];
            if (!isset($marks[$contextOccurrenceId])) {
                continue;
            }
            $insert->execute([
                'context_occurrence_id' => $contextOccurrenceId,
                'context_id' => $marks[$contextOccurrenceId]['contextId'],
                'document_id' => $documentId,
                'knowledge_occurrence_id' => $membership['knowledgeOccurrenceId'],
            ]);
        }
    }

    private function assertContextExists(string $contextId): void
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM contexts WHERE id = :id');
        $statement->execute(['id' => $contextId]);
        if ($statement->fetch() === false) {
            throw new ApiException(422, 'context_not_found', 'Il Context della occurrence non esiste.');
        }
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

use PDO;

/**
 * What an organiser actually organises: the Document that carry its fragments, each one shown with
 * the words that surround them.
 *
 * This is the answer to «che cos'era questo Concept?» — a question that the index alone cannot
 * answer, because the meaning lives in the notes. A trashed object answers too: its fragments are
 * still in the text, so the preview shows what it was before deciding whether to delete it.
 */
final class PreviewService
{
    /** Fragments shown for each Document: enough to recognise it, not enough to reread it whole. */
    private const MAX_FRAGMENTS = 6;

    public function __construct(
        private readonly PDO $pdo,
        private readonly DocumentRepository $documents,
        private readonly FragmentExtractor $fragments,
        private readonly ContextRepository $contexts,
    ) {
    }

    /**
     * Preview of a Concept or an Entity, trashed or not.
     *
     * @return array<string, mixed>
     */
    public function knowledgeObject(string $objectId): array
    {
        $this->assertId($objectId);
        $object = $this->fetch(
            'SELECT o.id, o.object_type, COALESCE(c.canonical_name, e.name) AS name, ' .
            'EXISTS (SELECT 1 FROM knowledge_object_trash t WHERE t.knowledge_object_id = o.id) AS trashed ' .
            'FROM knowledge_objects o ' .
            'LEFT JOIN concepts c ON c.id = o.id LEFT JOIN entities e ON e.id = o.id WHERE o.id = :id',
            $objectId,
        );
        if ($object === null) {
            throw new ApiException(404, 'knowledge_object_not_found', 'Concept o Entity non trovati.');
        }

        $occurrences = $this->rows(
            'SELECT id, document_id, status FROM knowledge_occurrences ' .
            "WHERE knowledge_object_id = :id AND status <> 'deleted' ORDER BY created_at",
            ['id' => $objectId],
        );

        return [
            'kind' => (string) $object['object_type'],
            'id' => $objectId,
            'label' => (string) $object['name'],
            'trashed' => (bool) $object['trashed'],
            'documents' => $this->documentsOf($occurrences, 'knowledgeOccurrence'),
        ];
    }

    /**
     * Preview of a Context: the Document where its ranges live, with the text they cover.
     *
     * @return array<string, mixed>
     */
    public function context(string $contextId, string $mode): array
    {
        $this->assertId($contextId);
        $context = $this->fetch(
            'SELECT c.id, c.name, EXISTS (SELECT 1 FROM context_trash t WHERE t.context_id = c.id) AS trashed ' .
            'FROM contexts c WHERE c.id = :id',
            $contextId,
        );
        if ($context === null) {
            throw new ApiException(404, 'context_not_found', 'Context non trovato.');
        }

        $selected = $mode === 'exact' ? [$contextId] : $this->contexts->subtreeIds($contextId);
        $placeholders = implode(',', array_fill(0, count($selected), '?'));
        $occurrences = $this->rows(
            'SELECT id, document_id, status FROM context_occurrences ' .
            "WHERE status <> 'deleted' AND context_id IN ({$placeholders}) ORDER BY created_at",
            $selected,
        );

        return [
            'kind' => 'context',
            'id' => $contextId,
            'label' => (string) $context['name'],
            'trashed' => (bool) $context['trashed'],
            'documents' => $this->documentsOf($occurrences, 'contextOccurrence'),
        ];
    }

    /**
     * Groups the occurrences by Document and reads the text they mark. A Document that no longer
     * carries the mark is skipped: the preview shows what exists now, not what the database
     * remembers.
     *
     * @param list<array<string, mixed>> $occurrences
     * @return list<array<string, mixed>>
     */
    private function documentsOf(array $occurrences, string $markType): array
    {
        $byDocument = [];
        foreach ($occurrences as $occurrence) {
            $byDocument[(string) $occurrence['document_id']][] = $occurrence;
        }

        $previews = [];
        foreach ($byDocument as $documentId => $rows) {
            $document = $this->documents->get($documentId);
            $fragments = $this->fragments->extract($document['documentJson'], $markType);
            $shown = [];
            foreach ($rows as $row) {
                $fragment = $fragments[(string) $row['id']] ?? null;
                if ($fragment === null) {
                    continue;
                }
                $shown[] = [
                    'occurrenceId' => (string) $row['id'],
                    'status' => (string) $row['status'],
                    ...$fragment,
                ];
            }
            if ($shown === []) {
                continue;
            }
            $previews[] = [
                'id' => (string) $document['id'],
                'title' => (string) $document['title'],
                'status' => (string) $document['status'],
                'revision' => (int) $document['revision'],
                'updatedAt' => $document['updatedAt'] ?? null,
                'fragments' => array_slice($shown, 0, self::MAX_FRAGMENTS),
                'total' => count($shown),
            ];
        }

        usort($previews, static fn (array $first, array $second): int
            => strcmp((string) $second['updatedAt'], (string) $first['updatedAt']));
        return $previews;
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $sql, string $id): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed>|list<string> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    private function assertId(string $id): void
    {
        if (!UuidV7::isValid($id)) {
            throw new ApiException(422, 'invalid_id', 'ID non valido.');
        }
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;
use Throwable;

/**
 * Physical removal of a Document. This is maintenance, not CRUD: it runs only on a trashed
 * Document, shows its impact first, writes a backup and removes in one transaction the Document
 * with the manifestations it owns. KnowledgeObject and Entity owned data are never deleted.
 */
final class DocumentPurgeService
{
    /** Tables whose rows belong to the Document and are removed with it. */
    private const DOCUMENT_OWNED_TABLES = ['knowledge_occurrences'];

    private const SAFE_IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(
        private readonly PDO $pdo,
        private readonly DocumentRepository $documents,
        private readonly KnowledgeRepository $knowledge,
    ) {
    }

    /**
     * Everything the purge would remove and every reason why it would refuse. Changes nothing.
     *
     * @return array<string, mixed>
     */
    public function preview(string $documentId): array
    {
        $document = $this->documents->get($documentId);
        $occurrences = $this->documentOccurrences($documentId);
        $blockers = $this->blockers($document, $documentId);

        return [
            'documentId' => $document['id'],
            'title' => $document['title'],
            'status' => $document['status'],
            'revision' => $document['revision'],
            'occurrences' => $this->countByStatus($occurrences),
            'knowledgeObjects' => $this->touchedObjects($occurrences),
            'blockers' => $blockers,
            'canPurge' => $blockers === [],
        ];
    }

    /**
     * Removes the Document after the checks of the preview, keeping the backup file in any case.
     *
     * @return array<string, mixed>
     */
    public function purge(string $documentId, string $backupDirectory): array
    {
        $preview = $this->preview($documentId);
        if ($preview['canPurge'] !== true) {
            throw new ApiException(409, 'purge_blocked', 'Il purge è bloccato: risolvi prima gli impedimenti elencati.', [
                'blockers' => $preview['blockers'],
            ]);
        }

        $backupPath = $this->writeBackup($documentId, $backupDirectory);
        $touched = $this->touchedObjects($this->documentOccurrences($documentId));

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM knowledge_occurrences WHERE document_id = :id')->execute(['id' => $documentId]);
            $this->pdo->prepare('DELETE FROM documents WHERE id = :id')->execute(['id' => $documentId]);
            $this->knowledge->refreshConceptStatus($touched);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return ['backupPath' => $backupPath, 'preview' => $preview];
    }

    /**
     * @param array<string, mixed> $document
     * @return list<array<string, mixed>>
     */
    private function blockers(array $document, string $documentId): array
    {
        $blockers = [];
        if ($document['status'] !== 'trashed') {
            $blockers[] = ['reason' => 'document_not_trashed', 'detail' => (string) $document['status']];
        }
        foreach ($this->incomingReferences($documentId) as $reference) {
            $blockers[] = ['reason' => 'incoming_reference', 'detail' => $reference];
        }
        return $blockers;
    }

    /**
     * Rows of other tables that point at this Document. Discovered from the schema, so a table
     * added by a future phase blocks the purge until the purge learns how to handle it.
     *
     * @return list<string>
     */
    private function incomingReferences(string $documentId): array
    {
        $references = [];
        foreach ($this->tableNames() as $table) {
            if ($table === 'documents' || in_array($table, self::DOCUMENT_OWNED_TABLES, true)) {
                continue;
            }
            foreach ($this->pdo->query("PRAGMA foreign_key_list('{$table}')")->fetchAll() as $foreignKey) {
                if ($foreignKey['table'] !== 'documents' || preg_match(self::SAFE_IDENTIFIER, (string) $foreignKey['from']) !== 1) {
                    continue;
                }
                $count = $this->countReferences($table, (string) $foreignKey['from'], $documentId);
                if ($count > 0) {
                    $references[] = "{$table}.{$foreignKey['from']}: {$count}";
                }
            }
        }
        return $references;
    }

    /**
     * Table and column names cannot be bound as parameters, so they are validated against a strict
     * identifier pattern and read from the schema, never from user input.
     */
    private function countReferences(string $table, string $column, string $documentId): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM \"{$table}\" WHERE \"{$column}\" = :id");
        $statement->execute(['id' => $documentId]);
        return (int) $statement->fetchColumn();
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        $names = [];
        $rows = $this->pdo->query("SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll();
        foreach ($rows as $row) {
            if (preg_match(self::SAFE_IDENTIFIER, (string) $row['name']) === 1) {
                $names[] = (string) $row['name'];
            }
        }
        return $names;
    }

    /** @return list<array<string, mixed>> */
    private function documentOccurrences(string $documentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, knowledge_object_id, object_type, status FROM knowledge_occurrences WHERE document_id = :id'
        );
        $statement->execute(['id' => $documentId]);
        return $statement->fetchAll();
    }

    /** @param list<array<string, mixed>> $occurrences @return array<string, int> */
    private function countByStatus(array $occurrences): array
    {
        $counts = ['active' => 0, 'detached' => 0, 'deleted' => 0];
        foreach ($occurrences as $occurrence) {
            $status = (string) $occurrence['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        return $counts;
    }

    /** @param list<array<string, mixed>> $occurrences @return array<string, string> */
    private function touchedObjects(array $occurrences): array
    {
        $objects = [];
        foreach ($occurrences as $occurrence) {
            $objects[(string) $occurrence['knowledge_object_id']] = (string) $occurrence['object_type'];
        }
        return $objects;
    }

    /** Writes the recoverable copy before anything is removed. */
    private function writeBackup(string $documentId, string $backupDirectory): string
    {
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0770, true) && !is_dir($backupDirectory)) {
            throw new ApiException(500, 'backup_failed', "Impossibile creare la directory di backup: {$backupDirectory}");
        }
        $statement = $this->pdo->prepare('SELECT * FROM documents WHERE id = :id');
        $statement->execute(['id' => $documentId]);
        $payload = [
            'document' => $statement->fetch(),
            'occurrences' => $this->documentOccurrences($documentId),
            'backedUpAt' => Clock::now(),
        ];
        $path = rtrim($backupDirectory, '/') . '/document-' . $documentId . '-' . str_replace([':', '.'], '-', Clock::now()) . '.json';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || file_put_contents($path, $encoded) === false) {
            throw new ApiException(500, 'backup_failed', "Impossibile scrivere il backup in {$path}");
        }
        return $path;
    }
}

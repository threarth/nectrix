<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use JsonException;
use PDO;

final class DocumentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Documents of one lifecycle scope. Archived and trashed ones never appear by default: the
     * caller has to ask for them explicitly.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $status, ?array $documentIds = null): array
    {
        if ($documentIds === []) {
            return [];
        }
        $conditions = ['status = ?'];
        $parameters = [$status];
        if ($documentIds !== null) {
            $conditions[] = 'id IN (' . implode(',', array_fill(0, count($documentIds), '?')) . ')';
            $parameters = [...$parameters, ...$documentIds];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, title, revision, status, created_at, updated_at FROM documents WHERE ' .
            implode(' AND ', $conditions) . ' ORDER BY updated_at DESC, id'
        );
        $statement->execute($parameters);
        return array_map($this->mapSummary(...), $statement->fetchAll());
    }

    /**
     * Maintenance write used when something the text referred to is deleted: the words stay, the
     * index over them does not. The revision advances so an editor holding the old content finds
     * the conflict instead of writing the removed mark back. The caller owns the transaction.
     *
     * @param array<string, mixed> $documentJson
     */
    public function replaceContent(string $id, array $documentJson, string $plainText): void
    {
        $this->pdo->prepare(
            'UPDATE documents SET document_json = :document_json, plain_text = :plain_text, ' .
            'revision = revision + 1, updated_at = :updated_at WHERE id = :id'
        )->execute([
            'document_json' => $this->encode($documentJson),
            'plain_text' => $plainText,
            'updated_at' => Clock::now(),
            'id' => $id,
        ]);
    }

    /** Archive, trash and restore change only the lifecycle state, never the content. */
    public function setStatus(string $id, string $status): array
    {
        $this->pdo->beginTransaction();
        try {
            $current = $this->get($id);
            $statement = $this->pdo->prepare('UPDATE documents SET status = :status WHERE id = :id');
            $statement->execute(['status' => $status, 'id' => $current['id']]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return $this->get($id);
    }

    /** @return array<string, mixed> */
    public function create(string $title, array $documentJson, string $plainText): array
    {
        $id = UuidV7::generate();
        $timestamp = Clock::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO documents ' .
            '(id, title, document_json, plain_text, revision, created_at, updated_at) ' .
            'VALUES (:id, :title, :document_json, :plain_text, 0, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'title' => $title,
            'document_json' => $this->encode($documentJson),
            'plain_text' => $plainText,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $this->get($id);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM documents WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new ApiException(404, 'document_not_found', 'Document non trovato.');
        }
        return $this->mapDocument($row);
    }

    /** @return array<string, mixed> */
    public function update(string $id, int $baseRevision, string $title, array $documentJson, string $plainText, ?callable $beforeUpdate = null): array
    {
        $this->pdo->beginTransaction();
        try {
            $current = $this->get($id);
            if ($current['status'] !== 'active') {
                throw new ApiException(
                    409,
                    'document_read_only',
                    'Un Document archiviato o nel cestino è in sola lettura: ripristinalo prima di modificarlo.',
                    ['status' => $current['status']],
                );
            }
            if ($current['revision'] !== $baseRevision) {
                throw new ApiException(
                    409,
                    'revision_conflict',
                    'Il Document è stato modificato da una revisione più recente.',
                    ['currentRevision' => $current['revision']],
                );
            }
            if ($beforeUpdate !== null) $beforeUpdate($id);

            $updatedAt = Clock::after($current['updatedAt']);
            $statement = $this->pdo->prepare(
                'UPDATE documents SET title = :title, document_json = :document_json, ' .
                'plain_text = :plain_text, revision = revision + 1, updated_at = :updated_at ' .
                'WHERE id = :id AND revision = :base_revision'
            );
            $statement->execute([
                'title' => $title,
                'document_json' => $this->encode($documentJson),
                'plain_text' => $plainText,
                'updated_at' => $updatedAt,
                'id' => $id,
                'base_revision' => $baseRevision,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new ApiException(409, 'revision_conflict', 'Conflitto durante il salvataggio del Document.');
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return $this->get($id);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapSummary(array $row): array
    {
        return [
            'id' => $row['id'],
            'title' => $row['title'],
            'revision' => (int) $row['revision'],
            'status' => $row['status'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapDocument(array $row): array
    {
        return [
            ...$this->mapSummary($row),
            'documentJson' => json_decode($row['document_json'], true, 512, JSON_THROW_ON_ERROR),
            'plainText' => $row['plain_text'],
        ];
    }

    /** @param array<string, mixed> $document */
    private function encode(array $document): string
    {
        try {
            return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new ApiException(422, 'invalid_document', 'Il documento non è serializzabile come JSON.');
        }
    }
}

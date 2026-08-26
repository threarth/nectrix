<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    public function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (' .
            'version TEXT PRIMARY KEY NOT NULL, applied_at TEXT NOT NULL' .
            ') STRICT'
        );

        $files = glob($this->directory . '/*.sql');
        if ($files === false) {
            throw new RuntimeException('Impossibile leggere le migrazioni.');
        }
        sort($files, SORT_STRING);

        $isApplied = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $record = $this->pdo->prepare(
            'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)'
        );

        foreach ($files as $file) {
            $version = basename($file, '.sql');
            $isApplied->execute(['version' => $version]);
            if ($isApplied->fetchColumn() !== false) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Impossibile leggere la migrazione {$version}.");
            }

            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $record->execute(['version' => $version, 'applied_at' => Clock::now()]);
                $this->pdo->commit();
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
    }
}

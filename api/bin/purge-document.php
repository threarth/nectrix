#!/usr/bin/env php
<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Nectrix\ApiException;
use Nectrix\Database;
use Nectrix\DocumentPurgeService;
use Nectrix\DocumentRepository;
use Nectrix\KnowledgeRepository;
use Nectrix\Migrator;

require dirname(__DIR__) . '/bootstrap.php';

const USAGE = <<<TEXT
Purge di un Document. Comando di manutenzione, non un DELETE ordinario.

  php api/bin/purge-document.php --id=<uuid> [--apply] [--db=<path>] [--backup-dir=<path>]

Senza --apply mostra soltanto la preview. Il purge richiede un Document nel cestino e
rimuove il Document con le manifestazioni che possiede, mai i KnowledgeObject.

TEXT;

/** @return array<string, string|bool> */
function parseArguments(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--apply') {
            $options['apply'] = true;
            continue;
        }
        if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $matches) === 1) {
            $options[$matches[1]] = $matches[2];
            continue;
        }
        fwrite(STDERR, "Argomento non riconosciuto: {$argument}\n\n" . USAGE);
        exit(2);
    }
    return $options;
}

function printPreview(array $preview): void
{
    fwrite(STDOUT, "Document: {$preview['documentId']}\n");
    fwrite(STDOUT, "Titolo:   {$preview['title']}\n");
    fwrite(STDOUT, "Stato:    {$preview['status']} (revisione {$preview['revision']})\n");
    fwrite(STDOUT, sprintf(
        "Occurrence da rimuovere: %d attive, %d staccate, %d eliminate\n",
        $preview['occurrences']['active'],
        $preview['occurrences']['detached'],
        $preview['occurrences']['deleted'],
    ));
    fwrite(STDOUT, sprintf("KnowledgeObject coinvolti, che NON vengono eliminati: %d\n", count($preview['knowledgeObjects'])));
    if ($preview['blockers'] === []) {
        fwrite(STDOUT, "Nessun impedimento.\n");
        return;
    }
    fwrite(STDOUT, "Impedimenti:\n");
    foreach ($preview['blockers'] as $blocker) {
        fwrite(STDOUT, "  - {$blocker['reason']}: {$blocker['detail']}\n");
    }
}

$options = parseArguments($argv);
$documentId = is_string($options['id'] ?? null) ? $options['id'] : '';
if ($documentId === '') {
    fwrite(STDERR, USAGE);
    exit(2);
}

$databasePath = is_string($options['db'] ?? null) ? $options['db'] : dirname(__DIR__, 2) . '/data/nectrix.sqlite';
$backupDirectory = is_string($options['backup-dir'] ?? null) ? $options['backup-dir'] : dirname(__DIR__, 2) . '/data/backups';

try {
    $pdo = Database::connect($databasePath);
    (new Migrator($pdo, dirname(__DIR__) . '/migrations'))->migrate();
    $purge = new DocumentPurgeService($pdo, new DocumentRepository($pdo), new KnowledgeRepository($pdo));

    $preview = $purge->preview($documentId);
    printPreview($preview);

    if (($options['apply'] ?? false) !== true) {
        fwrite(STDOUT, "\nAnteprima soltanto. Aggiungi --apply per eseguire il purge.\n");
        exit(0);
    }

    $result = $purge->purge($documentId, $backupDirectory);
    fwrite(STDOUT, "\nBackup scritto in {$result['backupPath']}\nPurge completato.\n");
    exit(0);
} catch (ApiException $error) {
    fwrite(STDERR, "\n{$error->errorCode}: {$error->getMessage()}\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, "\nErrore inatteso: {$error->getMessage()}\n");
    exit(1);
}

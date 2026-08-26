<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Nectrix\ApiException;
use Nectrix\Database;
use Nectrix\DocumentRepository;
use Nectrix\DocumentService;
use Nectrix\DocumentValidator;
use Nectrix\Migrator;
use Nectrix\PlainTextExtractor;
use Nectrix\UuidV7;

require dirname(__DIR__) . '/bootstrap.php';

final class TestSuite
{
    private int $passed = 0;
    private int $failed = 0;

    public function test(string $name, callable $test): void
    {
        try {
            $test();
            ++$this->passed;
            fwrite(STDOUT, "✓ {$name}\n");
        } catch (Throwable $error) {
            ++$this->failed;
            fwrite(STDERR, "✗ {$name}\n  {$error->getMessage()}\n");
        }
    }

    public function finish(): never
    {
        fwrite(STDOUT, "\n{$this->passed} test superati, {$this->failed} falliti.\n");
        exit($this->failed === 0 ? 0 : 1);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = $message === '' ? '' : "{$message}\n";
        throw new RuntimeException($detail . 'Atteso: ' . var_export($expected, true) . '\nOttenuto: ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function richDocument(): array
{
    return [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'heading',
                'attrs' => ['level' => 2],
                'content' => [
                    ['type' => 'text', 'text' => 'Titolo '],
                    [
                        'type' => 'text',
                        'marks' => [['type' => 'bold'], ['type' => 'underline']],
                        'text' => 'forte',
                    ],
                ],
            ],
            [
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'marks' => [['type' => 'italic']],
                    'text' => 'Corpo normale',
                ]],
            ],
            [
                'type' => 'orderedList',
                'attrs' => ['start' => 3, 'type' => null],
                'content' => [[
                    'type' => 'listItem',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => 'Primo elemento']],
                    ]],
                ]],
            ],
            [
                'type' => 'bulletList',
                'content' => [[
                    'type' => 'listItem',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => 'Secondo elemento']],
                    ]],
                ]],
            ],
            [
                'type' => 'blockquote',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Citazione']],
                ]],
            ],
        ],
    ];
}

$pdo = Database::connect(':memory:');
$migrator = new Migrator($pdo, dirname(__DIR__) . '/migrations');
$migrator->migrate();
$migrator->migrate();
$validator = new DocumentValidator();
$extractor = new PlainTextExtractor();
$repository = new DocumentRepository($pdo);
$service = new DocumentService($repository, $validator, $extractor);
$suite = new TestSuite();

$suite->test('la connessione SQLite abilita sempre le foreign key', static function () use ($pdo): void {
    assertSameValue(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
});

$suite->test('la migrazione iniziale contiene solo i campi Document della FASE 1', static function () use ($pdo): void {
    $columns = $pdo->query('PRAGMA table_info(documents)')->fetchAll();
    assertSameValue(
        ['id', 'title', 'document_json', 'plain_text', 'revision', 'created_at', 'updated_at'],
        array_column($columns, 'name'),
    );
    assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
});

$suite->test('UUIDv7 è canonico, lowercase e non collide nel campione', static function (): void {
    $ids = [];
    for ($index = 0; $index < 100; ++$index) {
        $id = UuidV7::generate();
        assertTrue(UuidV7::isValid($id), "UUIDv7 non valido: {$id}");
        $ids[$id] = true;
    }
    assertSameValue(100, count($ids));
    assertTrue(!UuidV7::isValid(strtoupper(array_key_first($ids))), 'Un UUID uppercase non deve essere accettato.');
});

$suite->test('allowlist e plain text coprono tutti i nodi e mark della FASE 1', static function () use ($validator, $extractor): void {
    $document = richDocument();
    $validator->validate($document);
    assertSameValue(
        "Titolo forte\nCorpo normale\nPrimo elemento\nSecondo elemento\nCitazione",
        $extractor->extract($document),
    );
});

$suite->test('nodi e mark fuori allowlist vengono rifiutati con il path', static function () use ($validator): void {
    $invalid = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.test']]],
                'text' => 'link',
            ]],
        ]],
    ];
    try {
        $validator->validate($invalid);
        throw new RuntimeException('Il documento non supportato è stato accettato.');
    } catch (ApiException $error) {
        assertSameValue(422, $error->status);
        assertSameValue('invalid_document', $error->errorCode);
        assertSameValue('$.content[0].content[0].marks[0].attrs', $error->details['path']);
    }
});

$created = null;
$suite->test('create, get e list preservano semanticamente il JSON rich text', static function () use ($service, &$created): void {
    $fixture = richDocument();
    $created = $service->create(['title' => 'Documento di prova', 'documentJson' => $fixture]);
    assertSameValue(0, $created['revision']);
    assertSameValue($fixture, $created['documentJson']);
    assertSameValue($created, $service->get($created['id']));
    assertSameValue($created['id'], $service->list()[0]['id']);
    assertSameValue($created['createdAt'], $created['updatedAt']);
    assertTrue(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $created['createdAt']) === 1,
        'Timestamp non canonico.',
    );
});

$saved = null;
$suite->test('save incrementa revision e aggiorna deterministicamente plain_text', static function () use ($service, &$created, &$saved): void {
    $changed = richDocument();
    $changed['content'][1]['content'][0]['text'] = 'Corpo modificato';
    $saved = $service->update($created['id'], [
        'baseRevision' => 0,
        'title' => '',
        'documentJson' => $changed,
    ]);
    assertSameValue(1, $saved['revision']);
    assertSameValue('', $saved['title']);
    assertSameValue($changed, $saved['documentJson']);
    assertSameValue("Titolo forte\nCorpo modificato\nPrimo elemento\nSecondo elemento\nCitazione", $saved['plainText']);
    assertTrue(strcmp($saved['updatedAt'], $created['updatedAt']) > 0, 'updatedAt deve essere monotono.');
});

$suite->test('una revisione obsoleta fallisce atomicamente senza toccare dati o timestamp', static function () use ($service, &$created, &$saved): void {
    try {
        $service->update($created['id'], [
            'baseRevision' => 0,
            'title' => 'stale',
            'documentJson' => richDocument(),
        ]);
        throw new RuntimeException('Il salvataggio obsoleto è stato accettato.');
    } catch (ApiException $error) {
        assertSameValue(409, $error->status);
        assertSameValue('revision_conflict', $error->errorCode);
    }
    assertSameValue($saved, $service->get($created['id']));
});

$suite->test('ID malformati e campi input inattesi vengono rifiutati', static function () use ($service): void {
    try {
        $service->get('documento-semantico');
        throw new RuntimeException('Un ID non UUID è stato accettato.');
    } catch (ApiException $error) {
        assertSameValue('invalid_id', $error->errorCode);
    }
    try {
        $service->create(['plainText' => 'dato non autorevole']);
        throw new RuntimeException('plainText client-side è stato accettato.');
    } catch (ApiException $error) {
        assertSameValue('invalid_request', $error->errorCode);
    }
});

$suite->finish();

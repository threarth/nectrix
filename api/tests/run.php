<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Nectrix\ApiException;
use Nectrix\Database;
use Nectrix\DocumentRepository;
use Nectrix\DocumentService;
use Nectrix\DocumentValidator;
use Nectrix\KnowledgeOccurrenceExtractor;
use Nectrix\KnowledgeRepository;
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

function assertThrows(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message);
}

/** @param array<string, mixed> $parameters */
function executeStatement(PDO $pdo, string $sql, array $parameters): void
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
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

/** @return array<string, mixed> */
function highlightedDocument(): array
{
    return [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Testo '],
                ['type' => 'text', 'marks' => [['type' => 'highlight', 'attrs' => ['color' => '#b8dff4']]], 'text' => 'evidenziato'],
            ],
        ]],
    ];
}

$pdo = Database::connect(':memory:');
$migrator = new Migrator($pdo, dirname(__DIR__) . '/migrations');
$migrator->migrate();
$migrator->migrate();
$validator = new DocumentValidator();
$extractor = new PlainTextExtractor();
$repository = new DocumentRepository($pdo);
$service = new DocumentService($repository, $validator, $extractor, new KnowledgeOccurrenceExtractor(), new KnowledgeRepository($pdo));
$suite = new TestSuite();

$suite->test('la connessione SQLite abilita sempre le foreign key', static function () use ($pdo): void {
    assertSameValue(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
});

$suite->test('le migrazioni mantengono invariato lo schema Document della FASE 1', static function () use ($pdo): void {
    $columns = $pdo->query('PRAGMA table_info(documents)')->fetchAll();
    assertSameValue(
        ['id', 'title', 'document_json', 'plain_text', 'revision', 'created_at', 'updated_at'],
        array_column($columns, 'name'),
    );
    assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
});

$domainFixture = [];
$suite->test('la Phase 1.1 crea Concept ed Entity paralleli con relazioni e valori tipizzati', static function () use ($pdo, &$domainFixture): void {
    $timestamp = '2026-08-26T12:00:00.000Z';
    $entityTypeId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $entityId = UuidV7::generate();
    $templateId = UuidV7::generate();
    $numberFieldId = UuidV7::generate();
    $blockId = UuidV7::generate();

    executeStatement($pdo, 'INSERT INTO entity_types (id, name, created_at, updated_at) VALUES (:id, :name, :created, :updated)', [
        'id' => $entityTypeId, 'name' => 'Lesion', 'created' => $timestamp, 'updated' => $timestamp,
    ]);
    executeStatement($pdo, 'INSERT INTO knowledge_objects (id, object_type, created_at, updated_at) VALUES (:id, :type, :created, :updated)', [
        'id' => $conceptId, 'type' => 'concept', 'created' => $timestamp, 'updated' => $timestamp,
    ]);
    executeStatement($pdo, 'INSERT INTO concepts (id, canonical_name) VALUES (:id, :name)', [
        'id' => $conceptId, 'name' => 'Lesione #1',
    ]);
    executeStatement($pdo, 'INSERT INTO knowledge_objects (id, object_type, created_at, updated_at) VALUES (:id, :type, :created, :updated)', [
        'id' => $entityId, 'type' => 'entity', 'created' => $timestamp, 'updated' => $timestamp,
    ]);
    executeStatement($pdo, 'INSERT INTO entities (id, entity_type_id, name) VALUES (:id, :entity_type_id, :name)', [
        'id' => $entityId, 'entity_type_id' => $entityTypeId, 'name' => 'Lesione #1',
    ]);
    executeStatement($pdo, 'INSERT INTO templates (id, name, created_at, updated_at) VALUES (:id, :name, :created, :updated)', [
        'id' => $templateId, 'name' => 'MRI Lesion Characterization', 'created' => $timestamp, 'updated' => $timestamp,
    ]);
    executeStatement($pdo, 'INSERT INTO template_fields (id, template_id, name, field_type, is_searchable, is_indexed, sort_order, created_at, updated_at) VALUES (:id, :template_id, :name, :field_type, 1, 1, 0, :created, :updated)', [
        'id' => $numberFieldId, 'template_id' => $templateId, 'name' => 'dimension_mm',
        'field_type' => 'number', 'created' => $timestamp, 'updated' => $timestamp,
    ]);
    executeStatement($pdo, 'INSERT INTO semantic_blocks (id, entity_id, template_id, created_at, updated_at) VALUES (:id, :entity_id, :template_id, :created, :updated)', [
        'id' => $blockId, 'entity_id' => $entityId, 'template_id' => $templateId,
        'created' => $timestamp, 'updated' => $timestamp,
    ]);
    executeStatement($pdo, 'INSERT INTO field_values (id, semantic_block_id, template_id, template_field_id, field_type, number_value, linked_concept_id, created_at, updated_at) VALUES (:id, :block_id, :template_id, :field_id, :field_type, :value, :concept_id, :created, :updated)', [
        'id' => UuidV7::generate(), 'block_id' => $blockId, 'template_id' => $templateId,
        'field_id' => $numberFieldId, 'field_type' => 'number', 'value' => 14,
        'concept_id' => $conceptId, 'created' => $timestamp, 'updated' => $timestamp,
    ]);
    executeStatement($pdo, 'INSERT INTO knowledge_relations (id, source_knowledge_object_id, source_object_type, target_knowledge_object_id, target_object_type, relation_type, created_at, updated_at) VALUES (:id, :source_id, :source_type, :target_id, :target_type, :relation_type, :created, :updated)', [
        'id' => UuidV7::generate(), 'source_id' => $entityId, 'source_type' => 'entity',
        'target_id' => $conceptId, 'target_type' => 'concept', 'relation_type' => 'related_to',
        'created' => $timestamp, 'updated' => $timestamp,
    ]);

    assertSameValue(14.0, (float) $pdo->query('SELECT number_value FROM field_values')->fetchColumn());
    assertSameValue(2, (int) $pdo->query("SELECT COUNT(*) FROM knowledge_objects WHERE id IN ('{$conceptId}', '{$entityId}')")->fetchColumn());

    assertThrows(static function () use ($pdo, $blockId, $templateId, $numberFieldId, $timestamp): void {
        executeStatement($pdo, 'INSERT INTO field_values (id, semantic_block_id, template_id, template_field_id, field_type, text_value, created_at, updated_at) VALUES (:id, :block_id, :template_id, :field_id, :field_type, :value, :created, :updated)', [
            'id' => UuidV7::generate(), 'block_id' => $blockId, 'template_id' => $templateId,
            'field_id' => $numberFieldId, 'field_type' => 'text', 'value' => '14',
            'created' => $timestamp, 'updated' => $timestamp,
        ]);
    }, 'Un valore text non deve essere accettato per un TemplateField number.');

    $sourceFieldId = UuidV7::generate();
    executeStatement($pdo, 'INSERT INTO template_fields (id, template_id, name, field_type, sort_order, created_at, updated_at) VALUES (:id, :template_id, :name, :field_type, 1, :created, :updated)', [
        'id' => $sourceFieldId, 'template_id' => $templateId, 'name' => 'Source',
        'field_type' => 'source_reference', 'created' => $timestamp, 'updated' => $timestamp,
    ]);
    assertThrows(static function () use ($pdo, $blockId, $templateId, $sourceFieldId, $timestamp): void {
        executeStatement($pdo, 'INSERT INTO field_values (id, semantic_block_id, template_id, template_field_id, field_type, created_at, updated_at) VALUES (:id, :block_id, :template_id, :field_id, :field_type, :created, :updated)', [
            'id' => UuidV7::generate(), 'block_id' => $blockId, 'template_id' => $templateId,
            'field_id' => $sourceFieldId, 'field_type' => 'source_reference',
            'created' => $timestamp, 'updated' => $timestamp,
        ]);
    }, 'source_reference non deve accettare valori prima della FK verso Source.');

    assertThrows(static function () use ($pdo, $entityId, $conceptId, $timestamp): void {
        executeStatement($pdo, 'INSERT INTO knowledge_relations (id, source_knowledge_object_id, source_object_type, target_knowledge_object_id, target_object_type, relation_type, created_at, updated_at) VALUES (:id, :source_id, :source_type, :target_id, :target_type, :relation_type, :created, :updated)', [
            'id' => UuidV7::generate(), 'source_id' => $entityId, 'source_type' => 'entity',
            'target_id' => $conceptId, 'target_type' => 'entity', 'relation_type' => 'invalid_kind',
            'created' => $timestamp, 'updated' => $timestamp,
        ]);
    }, 'Il discriminator di una Relation deve coincidere con il KnowledgeObject.');

    $incompleteConceptId = UuidV7::generate();
    $pdo->beginTransaction();
    executeStatement($pdo, 'INSERT INTO knowledge_objects (id, object_type, created_at, updated_at) VALUES (:id, :type, :created, :updated)', [
        'id' => $incompleteConceptId, 'type' => 'concept', 'created' => $timestamp, 'updated' => $timestamp,
    ]);
    assertThrows(static function () use ($pdo, $entityId, $incompleteConceptId, $timestamp): void {
        executeStatement($pdo, 'INSERT INTO knowledge_relations (id, source_knowledge_object_id, source_object_type, target_knowledge_object_id, target_object_type, relation_type, created_at, updated_at) VALUES (:id, :source_id, :source_type, :target_id, :target_type, :relation_type, :created, :updated)', [
            'id' => UuidV7::generate(), 'source_id' => $entityId, 'source_type' => 'entity',
            'target_id' => $incompleteConceptId, 'target_type' => 'concept', 'relation_type' => 'invalid_subtype',
            'created' => $timestamp, 'updated' => $timestamp,
        ]);
    }, 'Una Relation non deve puntare a un KnowledgeObject senza il sottotipo dichiarato.');
    $pdo->rollBack();

    $domainFixture = ['conceptId' => $conceptId, 'entityId' => $entityId];
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

$suite->test('allowlist e plain text coprono tutti i nodi e mark fino alla FASE 2', static function () use ($validator, $extractor): void {
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
        assertSameValue('$.content[0].content[0].marks[0].type', $error->details['path']);
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

$suite->test('Highlight persiste come sola formattazione senza scritture semantiche', static function () use ($pdo, $service): void {
    $tables = ['knowledge_objects', 'concepts', 'entities', 'knowledge_occurrences', 'semantic_blocks', 'field_values'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    $document = highlightedDocument();
    $createdHighlight = $service->create(['title' => 'Solo highlight', 'documentJson' => $document]);
    assertSameValue($document, $createdHighlight['documentJson']);
    assertSameValue('Testo evidenziato', $createdHighlight['plainText']);

    $savedHighlight = $service->update($createdHighlight['id'], [
        'baseRevision' => 0,
        'title' => 'Solo highlight',
        'documentJson' => $document,
    ]);
    assertSameValue($document, $savedHighlight['documentJson']);
    assertSameValue($savedHighlight, $service->get($createdHighlight['id']));

    foreach ($tables as $table) {
        assertSameValue($before[$table], (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), "Highlight ha scritto in {$table}.");
    }
});

$suite->test('Highlight accetta colori esadecimali e rifiuta valori non sicuri', static function () use ($validator): void {
    $validator->validate(highlightedDocument());
    $invalid = highlightedDocument();
    $invalid['content'][0]['content'][1]['marks'][0]['attrs']['color'] = 'not-a-color';
    try {
        $validator->validate($invalid);
        throw new RuntimeException('Un colore Highlight non supportato è stato accettato.');
    } catch (ApiException $error) {
        assertSameValue(422, $error->status);
        assertSameValue('$.content[0].content[1].marks[0].attrs.color', $error->details['path']);
    }
});

$suite->test('crea atomically Concept e KnowledgeOccurrence insieme al Document', static function () use ($pdo, $service): void {
    $document = $service->create(['title' => 'Occurrence atomica']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $json = ['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'Backlog', 'marks' => [[
            'type' => 'knowledgeOccurrence',
            'attrs' => ['occurrenceId' => $occurrenceId, 'knowledgeObjectId' => $conceptId, 'objectType' => 'concept'],
        ]]]],
    ]]];
    $saved = $service->update($document['id'], [
        'baseRevision' => 0, 'title' => 'Occurrence atomica', 'documentJson' => $json,
        'occurrenceCreates' => [[
            'occurrenceId' => $occurrenceId, 'knowledgeObjectId' => $conceptId,
            'objectType' => 'concept', 'newObject' => true, 'name' => 'Backlog',
        ]],
    ]);
    assertSameValue(1, $saved['revision']);
    assertSameValue('Backlog', $pdo->query("SELECT canonical_name FROM concepts WHERE id = '{$conceptId}'")->fetchColumn());
    assertSameValue('active', $pdo->query("SELECT status FROM knowledge_occurrences WHERE id = '{$occurrenceId}'")->fetchColumn());
});

$suite->test('un mark occurrence non dichiarato causa rollback atomico', static function () use ($pdo, $service): void {
    $document = $service->create(['title' => 'Rollback occurrence']);
    $conceptId = UuidV7::generate();
    $declaredId = UuidV7::generate();
    $missingId = UuidV7::generate();
    $json = ['type' => 'doc', 'content' => [[
        'type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Uno', 'marks' => [['type' => 'knowledgeOccurrence', 'attrs' => ['occurrenceId' => $declaredId, 'knowledgeObjectId' => $conceptId, 'objectType' => 'concept']]]],
            ['type' => 'text', 'text' => ' Due', 'marks' => [['type' => 'knowledgeOccurrence', 'attrs' => ['occurrenceId' => $missingId, 'knowledgeObjectId' => $conceptId, 'objectType' => 'concept']]]],
        ],
    ]]];
    try {
        $service->update($document['id'], ['baseRevision' => 0, 'title' => 'Rollback occurrence', 'documentJson' => $json, 'occurrenceCreates' => [[
            'occurrenceId' => $declaredId, 'knowledgeObjectId' => $conceptId, 'objectType' => 'concept', 'newObject' => true, 'name' => 'Uno',
        ]]]);
        throw new RuntimeException('Salvataggio incoerente accettato.');
    } catch (ApiException $error) {
        assertSameValue('occurrence_not_persisted', $error->errorCode);
    }
    assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM knowledge_objects WHERE id = '{$conceptId}'")->fetchColumn());
    assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM knowledge_occurrences WHERE document_id = '{$document['id']}'")->fetchColumn());
});

$suite->test('KnowledgeOccurrence usa lo stesso lifecycle relazionale per Concept ed Entity', static function () use ($pdo, &$created, &$domainFixture): void {
    $timestamp = '2026-08-26T12:30:00.000Z';
    foreach (['concept', 'entity'] as $objectType) {
        executeStatement($pdo, 'INSERT INTO knowledge_occurrences (id, knowledge_object_id, object_type, document_id, created_at, updated_at) VALUES (:id, :object_id, :object_type, :document_id, :created, :updated)', [
            'id' => UuidV7::generate(), 'object_id' => $domainFixture[$objectType . 'Id'],
            'object_type' => $objectType, 'document_id' => $created['id'],
            'created' => $timestamp, 'updated' => $timestamp,
        ]);
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM knowledge_occurrences WHERE document_id = :document_id');
    $count->execute(['document_id' => $created['id']]);
    assertSameValue(2, (int) $count->fetchColumn());

    assertThrows(static function () use ($pdo, &$created, &$domainFixture, $timestamp): void {
        executeStatement($pdo, 'INSERT INTO knowledge_occurrences (id, knowledge_object_id, object_type, document_id, created_at, updated_at) VALUES (:id, :object_id, :object_type, :document_id, :created, :updated)', [
            'id' => UuidV7::generate(), 'object_id' => $domainFixture['conceptId'],
            'object_type' => 'entity', 'document_id' => $created['id'],
            'created' => $timestamp, 'updated' => $timestamp,
        ]);
    }, 'Il discriminator manipolato di una KnowledgeOccurrence deve essere rifiutato.');
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

$suite->test('la migration Phase 1.1 aggiorna un database FASE 1 senza perdere Document', static function (): void {
    $upgradePdo = Database::connect(':memory:');
    $upgradePdo->exec(
        'CREATE TABLE schema_migrations (' .
        'version TEXT PRIMARY KEY NOT NULL, applied_at TEXT NOT NULL' .
        ') STRICT'
    );
    $initialSql = file_get_contents(dirname(__DIR__) . '/migrations/001_create_documents.sql');
    if ($initialSql === false) {
        throw new RuntimeException('Migration iniziale non leggibile.');
    }
    $upgradePdo->exec($initialSql);
    executeStatement($upgradePdo, 'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)', [
        'version' => '001_create_documents', 'applied_at' => '2026-08-26T11:00:00.000Z',
    ]);
    executeStatement($upgradePdo, 'INSERT INTO documents (id, title, document_json, plain_text, revision, created_at, updated_at) VALUES (:id, :title, :json, :plain_text, 0, :created, :updated)', [
        'id' => UuidV7::generate(), 'title' => 'Documento preesistente',
        'json' => '{"type":"doc","content":[{"type":"paragraph"}]}', 'plain_text' => '',
        'created' => '2026-08-26T11:00:00.000Z', 'updated' => '2026-08-26T11:00:00.000Z',
    ]);

    (new Migrator($upgradePdo, dirname(__DIR__) . '/migrations'))->migrate();

    assertSameValue(1, (int) $upgradePdo->query('SELECT COUNT(*) FROM documents')->fetchColumn());
    assertSameValue('Documento preesistente', $upgradePdo->query('SELECT title FROM documents')->fetchColumn());
    assertSameValue(2, (int) $upgradePdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    assertSameValue('field_values', $upgradePdo->query("SELECT name FROM sqlite_schema WHERE type = 'table' AND name = 'field_values'")->fetchColumn());
});

$suite->test('lo schema finale non contiene violazioni di foreign key', static function () use ($pdo): void {
    assertSameValue([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
    assertSameValue('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
});

$suite->finish();

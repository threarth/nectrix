<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Nectrix\ApiException;
use Nectrix\ContextRepository;
use Nectrix\ContextService;
use Nectrix\Database;
use Nectrix\DocumentPurgeService;
use Nectrix\DocumentRepository;
use Nectrix\DocumentService;
use Nectrix\DocumentValidator;
use Nectrix\KnowledgeOccurrenceExtractor;
use Nectrix\KnowledgeRepository;
use Nectrix\IdentifierNormalizer;
use Nectrix\KnowledgeService;
use Nectrix\OccurrenceTextExtractor;
use Nectrix\Migrator;
use Nectrix\PlainTextExtractor;
use Nectrix\QueryService;
use Nectrix\TagRepository;
use Nectrix\TagService;
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

$suite->test('le migrazioni estendono lo schema Document senza rimuovere le colonne della FASE 1', static function () use ($pdo): void {
    $columns = array_column($pdo->query('PRAGMA table_info(documents)')->fetchAll(), 'name');
    $phaseOne = ['id', 'title', 'document_json', 'plain_text', 'revision', 'created_at', 'updated_at'];

    assertSameValue($phaseOne, array_slice($columns, 0, count($phaseOne)));
    assertSameValue(['status', 'context_id'], array_slice($columns, count($phaseOne)));
    assertSameValue(7, (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
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
    assertSameValue(7, (int) $upgradePdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    assertSameValue('field_values', $upgradePdo->query("SELECT name FROM sqlite_schema WHERE type = 'table' AND name = 'field_values'")->fetchColumn());
});

/** Text node carrying the knowledgeOccurrence mark, used by the occurrence invariant tests. */
function occurrenceText(string $text, string $occurrenceId, string $objectId, string $type = 'concept'): array
{
    return ['type' => 'text', 'text' => $text, 'marks' => [[
        'type' => 'knowledgeOccurrence',
        'attrs' => ['occurrenceId' => $occurrenceId, 'knowledgeObjectId' => $objectId, 'objectType' => $type],
    ]]];
}

/** Document made of one paragraph per argument. @param list<array<string, mixed>> $paragraphs */
function documentOfParagraphs(array ...$paragraphs): array
{
    $content = array_map(
        static fn (array $nodes): array => ['type' => 'paragraph', 'content' => $nodes],
        $paragraphs,
    );
    return ['type' => 'doc', 'content' => $content];
}

$suite->test('INV-OCC-05: frammenti contigui dello stesso ID restano una sola occurrence', static function (): void {
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $document = documentOfParagraphs([
        occurrenceText('Rocket', $occurrenceId, $conceptId),
        occurrenceText(' Lab', $occurrenceId, $conceptId),
    ]);

    $marks = (new KnowledgeOccurrenceExtractor())->extract($document);
    assertSameValue([$occurrenceId], array_keys($marks));
    assertSameValue($conceptId, $marks[$occurrenceId]['knowledgeObjectId']);
});

$suite->test('INV-OCC-05: lo stesso ID in intervalli disgiunti viene rifiutato', static function (): void {
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $extractor = new KnowledgeOccurrenceExtractor();

    $sameParagraph = documentOfParagraphs([
        occurrenceText('primo', $occurrenceId, $conceptId),
        ['type' => 'text', 'text' => ' intervallo '],
        occurrenceText('secondo', $occurrenceId, $conceptId),
    ]);
    $twoParagraphs = documentOfParagraphs(
        [occurrenceText('primo', $occurrenceId, $conceptId)],
        [occurrenceText('secondo', $occurrenceId, $conceptId)],
    );

    foreach ([$sameParagraph, $twoParagraphs] as $document) {
        try {
            $extractor->extract($document);
            throw new RuntimeException('Intervalli disgiunti accettati.');
        } catch (ApiException $error) {
            assertSameValue('occurrence_split', $error->errorCode);
        }
    }
});

$suite->test('INV-OCC-05: l’estrattore trova le occurrence dentro liste e citazioni', static function (): void {
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $document = ['type' => 'doc', 'content' => [[
        'type' => 'bulletList',
        'content' => [['type' => 'listItem', 'content' => [[
            'type' => 'paragraph',
            'content' => [occurrenceText('Backlog', $occurrenceId, $conceptId)],
        ]]]],
    ]]];

    assertSameValue([$occurrenceId], array_keys((new KnowledgeOccurrenceExtractor())->extract($document)));
});

$suite->test('INV-OCC-16: lo stesso ID con KnowledgeObject differenti viene rifiutato', static function (): void {
    $occurrenceId = UuidV7::generate();
    $document = documentOfParagraphs([
        occurrenceText('primo', $occurrenceId, UuidV7::generate()),
        occurrenceText('secondo', $occurrenceId, UuidV7::generate()),
    ]);

    try {
        (new KnowledgeOccurrenceExtractor())->extract($document);
        throw new RuntimeException('Attributi incoerenti accettati.');
    } catch (ApiException $error) {
        assertSameValue('occurrence_duplicate', $error->errorCode);
    }
});

$suite->test('INV-OCC-03: un mark occurrence senza attributi completi viene rifiutato', static function (): void {
    $document = ['type' => 'doc', 'content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'manipolato', 'marks' => [['type' => 'knowledgeOccurrence']]]],
    ]]];

    try {
        (new KnowledgeOccurrenceExtractor())->extract($document);
        throw new RuntimeException('Mark senza attributi accettato.');
    } catch (ApiException $error) {
        assertSameValue('occurrence_invalid_attributes', $error->errorCode);
    }
});

$suite->test('INV-OCC-15: associare un KnowledgeObject inesistente fallisce atomicamente', static function () use ($pdo, $service): void {
    $document = $service->create(['title' => 'Paste manipolato']);
    $occurrenceId = UuidV7::generate();
    $unknownId = UuidV7::generate();
    $json = documentOfParagraphs([occurrenceText('Sconosciuto', $occurrenceId, $unknownId)]);

    try {
        $service->update($document['id'], [
            'baseRevision' => 0, 'title' => 'Paste manipolato', 'documentJson' => $json,
            'occurrenceCreates' => [[
                'occurrenceId' => $occurrenceId, 'knowledgeObjectId' => $unknownId,
                'objectType' => 'concept', 'newObject' => false,
            ]],
        ]);
        throw new RuntimeException('KnowledgeObject inesistente accettato.');
    } catch (ApiException $error) {
        assertSameValue('knowledge_object_missing', $error->errorCode);
    }

    assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM knowledge_objects WHERE id = '{$unknownId}'")->fetchColumn());
    assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM knowledge_occurrences WHERE id = '{$occurrenceId}'")->fetchColumn());
    assertSameValue(0, (int) $pdo->query("SELECT revision FROM documents WHERE id = '{$document['id']}'")->fetchColumn());
});

$suite->test('INV-OCC-05: un salvataggio con occurrence frammentata non modifica nulla', static function () use ($pdo, $service): void {
    $document = $service->create(['title' => 'Frammentazione']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $json = documentOfParagraphs(
        [occurrenceText('primo', $occurrenceId, $conceptId)],
        [occurrenceText('secondo', $occurrenceId, $conceptId)],
    );

    try {
        $service->update($document['id'], [
            'baseRevision' => 0, 'title' => 'Frammentazione', 'documentJson' => $json,
            'occurrenceCreates' => [[
                'occurrenceId' => $occurrenceId, 'knowledgeObjectId' => $conceptId,
                'objectType' => 'concept', 'newObject' => true, 'name' => 'Frammento',
            ]],
        ]);
        throw new RuntimeException('Occurrence frammentata accettata.');
    } catch (ApiException $error) {
        assertSameValue('occurrence_split', $error->errorCode);
    }

    assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM knowledge_objects WHERE id = '{$conceptId}'")->fetchColumn());
    assertSameValue(0, (int) $pdo->query("SELECT revision FROM documents WHERE id = '{$document['id']}'")->fetchColumn());
});

$suite->test('INV-OCC-13: risalvare lo stesso documento non duplica le occurrence', static function () use ($pdo, $service): void {
    $document = $service->create(['title' => 'Reload stabile']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $json = documentOfParagraphs([occurrenceText('Backlog', $occurrenceId, $conceptId)]);
    $created = ['occurrenceId' => $occurrenceId, 'knowledgeObjectId' => $conceptId, 'objectType' => 'concept', 'newObject' => true, 'name' => 'Backlog'];

    $service->update($document['id'], ['baseRevision' => 0, 'title' => 'Reload stabile', 'documentJson' => $json, 'occurrenceCreates' => [$created]]);
    $saved = $service->update($document['id'], ['baseRevision' => 1, 'title' => 'Reload stabile', 'documentJson' => $json, 'occurrenceCreates' => []]);

    assertSameValue(2, $saved['revision']);
    assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM knowledge_occurrences WHERE id = '{$occurrenceId}'")->fetchColumn());
    assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM knowledge_objects WHERE id = '{$conceptId}'")->fetchColumn());
});

$suite->test('la risoluzione dei KnowledgeObject espone solo esistenza e discriminator', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $document = $service->create(['title' => 'Risoluzione']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $missingId = UuidV7::generate();
    $service->update($document['id'], [
        'baseRevision' => 0, 'title' => 'Risoluzione',
        'documentJson' => documentOfParagraphs([occurrenceText('Risolvibile', $occurrenceId, $conceptId)]),
        'occurrenceCreates' => [[
            'occurrenceId' => $occurrenceId, 'knowledgeObjectId' => $conceptId,
            'objectType' => 'concept', 'newObject' => true, 'name' => 'Risolvibile',
        ]],
    ]);

    $resolved = $knowledge->resolveObjects("{$conceptId},{$missingId}");
    assertSameValue([['id' => $conceptId, 'object_type' => 'concept']], $resolved);
    assertSameValue([], $knowledge->resolveObjects(''));

    try {
        $knowledge->resolveObjects('non-un-uuid');
        throw new RuntimeException('ID malformato accettato.');
    } catch (ApiException $error) {
        assertSameValue('invalid_id', $error->errorCode);
    }

    $tooMany = implode(',', array_map(static fn (): string => UuidV7::generate(), range(1, 201)));
    try {
        $knowledge->resolveObjects($tooMany);
        throw new RuntimeException('Richiesta senza limite accettata.');
    } catch (ApiException $error) {
        assertSameValue('invalid_request', $error->errorCode);
    }
});

/** Empty but valid Document content. */
function emptyDocument(): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
}

/** Saves one revision of a Document, optionally declaring occurrence creations. */
function saveRevision(DocumentService $service, string $documentId, int $baseRevision, array $json, array $creates = []): array
{
    return $service->update($documentId, [
        'baseRevision' => $baseRevision,
        'title' => 'Riconciliazione',
        'documentJson' => $json,
        'occurrenceCreates' => $creates,
    ]);
}

/** Declares a new Concept together with its first occurrence. */
function conceptCreate(string $occurrenceId, string $conceptId, string $name = 'Concetto'): array
{
    return [
        'occurrenceId' => $occurrenceId, 'knowledgeObjectId' => $conceptId,
        'objectType' => 'concept', 'newObject' => true, 'name' => $name,
    ];
}

function occurrenceStatus(PDO $pdo, string $occurrenceId): string
{
    $statement = $pdo->prepare('SELECT status FROM knowledge_occurrences WHERE id = :id');
    $statement->execute(['id' => $occurrenceId]);
    return (string) $statement->fetchColumn();
}

function conceptStatus(PDO $pdo, string $conceptId): string
{
    $statement = $pdo->prepare('SELECT status FROM concepts WHERE id = :id');
    $statement->execute(['id' => $conceptId]);
    return (string) $statement->fetchColumn();
}

function countRows(PDO $pdo, string $sql, array $parameters): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return (int) $statement->fetchColumn();
}

$suite->test('INV-OCC-08: il mark rimosso porta l’occurrence a detached e il Concept a orphan', static function () use ($pdo, $service): void {
    $document = $service->create(['title' => 'Riconciliazione']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $json = documentOfParagraphs([occurrenceText('Backlog', $occurrenceId, $conceptId)]);
    saveRevision($service, $document['id'], 0, $json, [conceptCreate($occurrenceId, $conceptId, 'Backlog')]);
    assertSameValue('active', occurrenceStatus($pdo, $occurrenceId));
    assertSameValue('active', conceptStatus($pdo, $conceptId));

    saveRevision($service, $document['id'], 1, emptyDocument());

    assertSameValue('detached', occurrenceStatus($pdo, $occurrenceId));
    assertSameValue('orphan', conceptStatus($pdo, $conceptId));
    assertSameValue(1, countRows($pdo, 'SELECT COUNT(*) FROM knowledge_objects WHERE id = :id', ['id' => $conceptId]));
    assertSameValue(1, countRows($pdo, 'SELECT COUNT(*) FROM knowledge_occurrences WHERE id = :id', ['id' => $occurrenceId]));
});

$suite->test('INV-OCC-09: il mark ripristinato riattiva lo stesso record e il Concept torna active', static function () use ($pdo, $service): void {
    $document = $service->create(['title' => 'Riconciliazione']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $json = documentOfParagraphs([occurrenceText('Backlog', $occurrenceId, $conceptId)]);
    saveRevision($service, $document['id'], 0, $json, [conceptCreate($occurrenceId, $conceptId, 'Backlog')]);
    saveRevision($service, $document['id'], 1, emptyDocument());
    assertSameValue('detached', occurrenceStatus($pdo, $occurrenceId));

    saveRevision($service, $document['id'], 2, $json);

    assertSameValue('active', occurrenceStatus($pdo, $occurrenceId));
    assertSameValue('active', conceptStatus($pdo, $conceptId));
    assertSameValue(1, countRows($pdo, 'SELECT COUNT(*) FROM knowledge_occurrences WHERE id = :id', ['id' => $occurrenceId]));
});

$suite->test('la riconciliazione è idempotente su salvataggi ripetuti', static function () use ($pdo, $service): void {
    $document = $service->create(['title' => 'Riconciliazione']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $json = documentOfParagraphs([occurrenceText('Backlog', $occurrenceId, $conceptId)]);
    saveRevision($service, $document['id'], 0, $json, [conceptCreate($occurrenceId, $conceptId, 'Backlog')]);
    saveRevision($service, $document['id'], 1, $json);
    assertSameValue('active', occurrenceStatus($pdo, $occurrenceId));

    saveRevision($service, $document['id'], 2, emptyDocument());
    saveRevision($service, $document['id'], 3, emptyDocument());

    assertSameValue('detached', occurrenceStatus($pdo, $occurrenceId));
    assertSameValue('orphan', conceptStatus($pdo, $conceptId));
    assertSameValue(1, countRows($pdo, 'SELECT COUNT(*) FROM knowledge_occurrences WHERE id = :id', ['id' => $occurrenceId]));
});

$suite->test('una Entity che perde l’ultima occurrence resta active e non viene cancellata', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $entityType = $knowledge->createEntityType(['name' => 'Agenzia spaziale']);
    $document = $service->create(['title' => 'Riconciliazione']);
    $occurrenceId = UuidV7::generate();
    $entityId = UuidV7::generate();
    $json = documentOfParagraphs([occurrenceText('Rocket Lab', $occurrenceId, $entityId, 'entity')]);
    saveRevision($service, $document['id'], 0, $json, [[
        'occurrenceId' => $occurrenceId, 'knowledgeObjectId' => $entityId, 'objectType' => 'entity',
        'newObject' => true, 'name' => 'Rocket Lab USA', 'entityTypeId' => $entityType['id'],
    ]]);

    saveRevision($service, $document['id'], 1, emptyDocument());

    assertSameValue('detached', occurrenceStatus($pdo, $occurrenceId));
    assertSameValue('active', (string) $pdo->query("SELECT status FROM entities WHERE id = '{$entityId}'")->fetchColumn());
    assertSameValue(1, countRows($pdo, 'SELECT COUNT(*) FROM knowledge_objects WHERE id = :id', ['id' => $entityId]));
});

$suite->test('un Concept con occurrence attive in un altro Document non diventa orphan', static function () use ($pdo, $service): void {
    $first = $service->create(['title' => 'Riconciliazione']);
    $second = $service->create(['title' => 'Riconciliazione']);
    $conceptId = UuidV7::generate();
    $firstOccurrence = UuidV7::generate();
    $secondOccurrence = UuidV7::generate();
    saveRevision($service, $first['id'], 0, documentOfParagraphs([occurrenceText('Backlog', $firstOccurrence, $conceptId)]), [
        conceptCreate($firstOccurrence, $conceptId, 'Backlog'),
    ]);
    saveRevision($service, $second['id'], 0, documentOfParagraphs([occurrenceText('Backlog', $secondOccurrence, $conceptId)]), [
        ['occurrenceId' => $secondOccurrence, 'knowledgeObjectId' => $conceptId, 'objectType' => 'concept', 'newObject' => false],
    ]);

    saveRevision($service, $first['id'], 1, emptyDocument());

    assertSameValue('detached', occurrenceStatus($pdo, $firstOccurrence));
    assertSameValue('active', occurrenceStatus($pdo, $secondOccurrence));
    assertSameValue('active', conceptStatus($pdo, $conceptId));
});

$suite->test('INV-OCC-17: una occurrence deleted non torna active e blocca il salvataggio', static function () use ($pdo, $service): void {
    $document = $service->create(['title' => 'Riconciliazione']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $json = documentOfParagraphs([occurrenceText('Backlog', $occurrenceId, $conceptId)]);
    saveRevision($service, $document['id'], 0, $json, [conceptCreate($occurrenceId, $conceptId, 'Backlog')]);
    executeStatement($pdo, "UPDATE knowledge_occurrences SET status = 'deleted' WHERE id = :id", ['id' => $occurrenceId]);

    try {
        saveRevision($service, $document['id'], 1, $json);
        throw new RuntimeException('Occurrence eliminata riattivata.');
    } catch (ApiException $error) {
        assertSameValue('occurrence_deleted', $error->errorCode);
    }

    assertSameValue('deleted', occurrenceStatus($pdo, $occurrenceId));
    assertSameValue(1, (int) $pdo->query("SELECT revision FROM documents WHERE id = '{$document['id']}'")->fetchColumn());
});

$suite->test('un Concept archiviato non diventa orphan perdendo l’ultima occurrence', static function () use ($pdo, $service): void {
    $document = $service->create(['title' => 'Riconciliazione']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $json = documentOfParagraphs([occurrenceText('Backlog', $occurrenceId, $conceptId)]);
    saveRevision($service, $document['id'], 0, $json, [conceptCreate($occurrenceId, $conceptId, 'Backlog')]);
    executeStatement($pdo, "UPDATE concepts SET status = 'archived' WHERE id = :id", ['id' => $conceptId]);

    saveRevision($service, $document['id'], 1, emptyDocument());

    assertSameValue('detached', occurrenceStatus($pdo, $occurrenceId));
    assertSameValue('archived', conceptStatus($pdo, $conceptId));
});

$suite->test('un conflitto di revisione non modifica lo stato delle occurrence', static function () use ($pdo, $service): void {
    $document = $service->create(['title' => 'Riconciliazione']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $json = documentOfParagraphs([occurrenceText('Backlog', $occurrenceId, $conceptId)]);
    saveRevision($service, $document['id'], 0, $json, [conceptCreate($occurrenceId, $conceptId, 'Backlog')]);

    try {
        saveRevision($service, $document['id'], 0, emptyDocument());
        throw new RuntimeException('Revisione obsoleta accettata.');
    } catch (ApiException $error) {
        assertSameValue('revision_conflict', $error->errorCode);
    }

    assertSameValue('active', occurrenceStatus($pdo, $occurrenceId));
    assertSameValue('active', conceptStatus($pdo, $conceptId));
});

$suite->test('l’inspector di un Concept espone stato e occurrence con testo letto dal Document', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $document = $service->create(['title' => 'Inspector']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    saveRevision($service, $document['id'], 0, documentOfParagraphs([occurrenceText('Backlog', $occurrenceId, $conceptId)]), [
        conceptCreate($occurrenceId, $conceptId, 'Backlog'),
    ]);

    $object = $knowledge->object($conceptId);

    assertSameValue('concept', $object['objectType']);
    assertSameValue('Backlog', $object['name']);
    assertSameValue('active', $object['status']);
    assertSameValue(null, $object['entityType']);
    assertSameValue(1, count($object['occurrences']));
    assertSameValue('Backlog', $object['occurrences'][0]['text']);
    assertSameValue($document['id'], $object['occurrences'][0]['documentId']);
    assertSameValue('active', $object['occurrences'][0]['status']);
});

$suite->test('il testo dell’occurrence segue il Document e non una copia persistita', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $document = $service->create(['title' => 'Inspector']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    saveRevision($service, $document['id'], 0, documentOfParagraphs([occurrenceText('Backlog', $occurrenceId, $conceptId)]), [
        conceptCreate($occurrenceId, $conceptId, 'Backlog'),
    ]);
    saveRevision($service, $document['id'], 1, documentOfParagraphs([occurrenceText('Backlog rivisto', $occurrenceId, $conceptId)]));

    assertSameValue('Backlog rivisto', $knowledge->object($conceptId)['occurrences'][0]['text']);

    saveRevision($service, $document['id'], 2, emptyDocument());
    $detached = $knowledge->object($conceptId)['occurrences'][0];
    assertSameValue('detached', $detached['status']);
    assertSameValue('', $detached['text']);
});

$suite->test('l’inspector di una Entity espone il proprio EntityType', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $entityType = $knowledge->createEntityType(['name' => 'Azienda spaziale']);
    $document = $service->create(['title' => 'Inspector']);
    $occurrenceId = UuidV7::generate();
    $entityId = UuidV7::generate();
    saveRevision($service, $document['id'], 0, documentOfParagraphs([occurrenceText('Rocket Lab', $occurrenceId, $entityId, 'entity')]), [[
        'occurrenceId' => $occurrenceId, 'knowledgeObjectId' => $entityId, 'objectType' => 'entity',
        'newObject' => true, 'name' => 'Rocket Lab USA', 'entityTypeId' => $entityType['id'],
    ]]);

    $object = $knowledge->object($entityId);

    assertSameValue('entity', $object['objectType']);
    assertSameValue('Rocket Lab USA', $object['name']);
    assertSameValue('Azienda spaziale', $object['entityType']['name']);
    assertSameValue('Rocket Lab', $object['occurrences'][0]['text']);
});

$suite->test('archive e restore di un Concept non cancellano nulla e rispettano le occurrence', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $document = $service->create(['title' => 'Inspector']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $json = documentOfParagraphs([occurrenceText('Backlog', $occurrenceId, $conceptId)]);
    saveRevision($service, $document['id'], 0, $json, [conceptCreate($occurrenceId, $conceptId, 'Backlog')]);

    assertSameValue('archived', $knowledge->archiveObject($conceptId)['status']);
    assertSameValue('active', occurrenceStatus($pdo, $occurrenceId));
    assertSameValue('active', $knowledge->restoreObject($conceptId)['status']);

    saveRevision($service, $document['id'], 1, emptyDocument());
    $knowledge->archiveObject($conceptId);
    assertSameValue('orphan', $knowledge->restoreObject($conceptId)['status']);
    assertSameValue(1, countRows($pdo, 'SELECT COUNT(*) FROM knowledge_occurrences WHERE id = :id', ['id' => $occurrenceId]));
});

$suite->test('un EntityType archiviato resta valido per le Entity esistenti e blocca solo le nuove', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $entityType = $knowledge->createEntityType(['name' => 'Tipo da archiviare']);
    $document = $service->create(['title' => 'Inspector']);
    $firstOccurrence = UuidV7::generate();
    $entityId = UuidV7::generate();
    saveRevision($service, $document['id'], 0, documentOfParagraphs([occurrenceText('Esistente', $firstOccurrence, $entityId, 'entity')]), [[
        'occurrenceId' => $firstOccurrence, 'knowledgeObjectId' => $entityId, 'objectType' => 'entity',
        'newObject' => true, 'name' => 'Entity esistente', 'entityTypeId' => $entityType['id'],
    ]]);

    assertSameValue('archived', $knowledge->archiveEntityType($entityType['id'])['status']);
    $existing = $knowledge->object($entityId);
    assertSameValue('active', $existing['status']);
    assertSameValue('archived', $existing['entityType']['status']);

    $secondOccurrence = UuidV7::generate();
    $newEntityId = UuidV7::generate();
    try {
        saveRevision($service, $document['id'], 1, documentOfParagraphs([occurrenceText('Nuova', $secondOccurrence, $newEntityId, 'entity')]), [[
            'occurrenceId' => $secondOccurrence, 'knowledgeObjectId' => $newEntityId, 'objectType' => 'entity',
            'newObject' => true, 'name' => 'Entity nuova', 'entityTypeId' => $entityType['id'],
        ]]);
        throw new RuntimeException('Nuova Entity creata con un EntityType archiviato.');
    } catch (ApiException $error) {
        assertSameValue('entity_type_archived', $error->errorCode);
    }

    assertSameValue('active', $knowledge->restoreEntityType($entityType['id'])['status']);
});

$suite->test('l’inspector di un KnowledgeObject inesistente risponde 404', static function () use ($pdo): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());

    try {
        $knowledge->object(UuidV7::generate());
        throw new RuntimeException('KnowledgeObject inesistente accettato.');
    } catch (ApiException $error) {
        assertSameValue('knowledge_object_not_found', $error->errorCode);
        assertSameValue(404, $error->status);
    }
});

/** Document with one Concept occurrence, saved and ready for the lifecycle tests. */
function documentWithConcept(DocumentService $service, string $occurrenceId, string $conceptId): array
{
    $document = $service->create(['title' => 'Lifecycle']);
    saveRevision($service, $document['id'], 0, documentOfParagraphs([occurrenceText('Backlog', $occurrenceId, $conceptId)]), [
        conceptCreate($occurrenceId, $conceptId, 'Backlog'),
    ]);
    return $service->get($document['id']);
}

$suite->test('archive e trash escludono il Document dalle liste predefinite senza toccare le occurrence', static function () use ($pdo, $service): void {
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $document = documentWithConcept($service, $occurrenceId, $conceptId);

    assertSameValue('archived', $service->archive($document['id'])['status']);
    assertTrue(!in_array($document['id'], array_column($service->list(), 'id'), true), 'Un Document archiviato non deve comparire fra gli attivi.');
    assertTrue(in_array($document['id'], array_column($service->list('archived'), 'id'), true), 'Un Document archiviato deve comparire con scope esplicito.');
    assertSameValue('active', occurrenceStatus($pdo, $occurrenceId));
    assertSameValue('active', conceptStatus($pdo, $conceptId));

    assertSameValue('trashed', $service->trash($document['id'])['status']);
    assertTrue(in_array($document['id'], array_column($service->list('trashed'), 'id'), true), 'Un Document nel cestino deve comparire nella vista di recupero.');
    assertSameValue('active', occurrenceStatus($pdo, $occurrenceId));

    assertSameValue('active', $service->restore($document['id'])['status']);
    assertSameValue('active', occurrenceStatus($pdo, $occurrenceId));
});

$suite->test('un Document archiviato o nel cestino è in sola lettura', static function () use ($service): void {
    $document = documentWithConcept($service, UuidV7::generate(), UuidV7::generate());
    $service->archive($document['id']);

    try {
        saveRevision($service, $document['id'], 1, emptyDocument());
        throw new RuntimeException('Salvataggio su Document archiviato accettato.');
    } catch (ApiException $error) {
        assertSameValue('document_read_only', $error->errorCode);
    }

    assertSameValue(1, $service->get($document['id'])['revision']);
    $service->restore($document['id']);
    assertSameValue(2, saveRevision($service, $document['id'], 1, emptyDocument())['revision']);
});

$suite->test('le transizioni di lifecycle non ammesse vengono rifiutate', static function () use ($service): void {
    $document = documentWithConcept($service, UuidV7::generate(), UuidV7::generate());
    $service->trash($document['id']);

    try {
        $service->archive($document['id']);
        throw new RuntimeException('Transizione non ammessa accettata.');
    } catch (ApiException $error) {
        assertSameValue('invalid_document_transition', $error->errorCode);
    }

    assertSameValue('trashed', $service->get($document['id'])['status']);
});

$suite->test('il purge rifiuta un Document non nel cestino e non modifica nulla', static function () use ($pdo, $service): void {
    $occurrenceId = UuidV7::generate();
    $document = documentWithConcept($service, $occurrenceId, UuidV7::generate());
    $purge = new DocumentPurgeService($pdo, new DocumentRepository($pdo), new KnowledgeRepository($pdo));

    $preview = $purge->preview($document['id']);
    assertSameValue(false, $preview['canPurge']);
    assertSameValue('document_not_trashed', $preview['blockers'][0]['reason']);

    try {
        $purge->purge($document['id'], sys_get_temp_dir() . '/nectrix-purge-test');
        throw new RuntimeException('Purge eseguito su un Document non nel cestino.');
    } catch (ApiException $error) {
        assertSameValue('purge_blocked', $error->errorCode);
    }

    assertSameValue('active', occurrenceStatus($pdo, $occurrenceId));
    assertSameValue(1, countRows($pdo, 'SELECT COUNT(*) FROM documents WHERE id = :id', ['id' => $document['id']]));
});

$suite->test('un riferimento entrante scoperto dallo schema blocca il purge', static function () use ($pdo, $service): void {
    $document = documentWithConcept($service, UuidV7::generate(), UuidV7::generate());
    $service->trash($document['id']);
    $pdo->exec('CREATE TABLE test_document_links (id TEXT PRIMARY KEY NOT NULL, document_id TEXT NOT NULL REFERENCES documents (id)) STRICT');
    executeStatement($pdo, 'INSERT INTO test_document_links (id, document_id) VALUES (:id, :document_id)', [
        'id' => UuidV7::generate(), 'document_id' => $document['id'],
    ]);
    $purge = new DocumentPurgeService($pdo, new DocumentRepository($pdo), new KnowledgeRepository($pdo));

    $preview = $purge->preview($document['id']);

    assertSameValue(false, $preview['canPurge']);
    assertSameValue('incoming_reference', $preview['blockers'][0]['reason']);
    assertSameValue('test_document_links.document_id: 1', $preview['blockers'][0]['detail']);

    $pdo->exec('DROP TABLE test_document_links');
    assertSameValue(true, $purge->preview($document['id'])['canPurge']);
});

$suite->test('il purge rimuove Document e occurrence con backup, senza toccare i KnowledgeObject', static function () use ($pdo, $service): void {
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    $document = documentWithConcept($service, $occurrenceId, $conceptId);
    $service->trash($document['id']);
    $backupDirectory = sys_get_temp_dir() . '/nectrix-purge-' . bin2hex(random_bytes(6));
    $purge = new DocumentPurgeService($pdo, new DocumentRepository($pdo), new KnowledgeRepository($pdo));

    $preview = $purge->preview($document['id']);
    assertSameValue(true, $preview['canPurge']);
    assertSameValue(1, $preview['occurrences']['active']);

    $result = $purge->purge($document['id'], $backupDirectory);

    assertTrue(is_file($result['backupPath']), 'Il purge deve lasciare un backup leggibile.');
    $backup = json_decode((string) file_get_contents($result['backupPath']), true, 16, JSON_THROW_ON_ERROR);
    assertSameValue($document['id'], $backup['document']['id']);
    assertSameValue(1, count($backup['occurrences']));

    assertSameValue(0, countRows($pdo, 'SELECT COUNT(*) FROM documents WHERE id = :id', ['id' => $document['id']]));
    assertSameValue(0, countRows($pdo, 'SELECT COUNT(*) FROM knowledge_occurrences WHERE id = :id', ['id' => $occurrenceId]));
    assertSameValue(1, countRows($pdo, 'SELECT COUNT(*) FROM knowledge_objects WHERE id = :id', ['id' => $conceptId]));
    assertSameValue('orphan', conceptStatus($pdo, $conceptId));

    unlink($result['backupPath']);
    rmdir($backupDirectory);
});

/** Concept created through the only real path: a Document with one occurrence. */
function conceptWithOccurrence(DocumentService $service, string $name): string
{
    $document = $service->create(['title' => 'Alias']);
    $occurrenceId = UuidV7::generate();
    $conceptId = UuidV7::generate();
    saveRevision($service, $document['id'], 0, documentOfParagraphs([occurrenceText($name, $occurrenceId, $conceptId)]), [
        conceptCreate($occurrenceId, $conceptId, $name),
    ]);
    return $conceptId;
}

function entityWithOccurrence(DocumentService $service, KnowledgeService $knowledge, string $name, string $typeName): string
{
    $entityType = $knowledge->createEntityType(['name' => $typeName]);
    $document = $service->create(['title' => 'Identifier']);
    $occurrenceId = UuidV7::generate();
    $entityId = UuidV7::generate();
    saveRevision($service, $document['id'], 0, documentOfParagraphs([occurrenceText($name, $occurrenceId, $entityId, 'entity')]), [[
        'occurrenceId' => $occurrenceId, 'knowledgeObjectId' => $entityId, 'objectType' => 'entity',
        'newObject' => true, 'name' => $name, 'entityTypeId' => $entityType['id'],
    ]]);
    return $entityId;
}

$suite->test('INV-ALS-01: aggiungere e rimuovere un alias non tocca le occurrence', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $conceptId = conceptWithOccurrence($service, 'Metodo scientifico');
    $before = $knowledge->object($conceptId)['occurrences'];

    $object = $knowledge->addAlias($conceptId, ['alias' => 'Metodo sperimentale']);

    assertSameValue(1, count($object['aliases']));
    assertSameValue('Metodo sperimentale', $object['aliases'][0]['alias']);
    assertSameValue($before, $object['occurrences']);

    $afterRemoval = $knowledge->removeAlias($object['aliases'][0]['id']);
    assertSameValue([], $afterRemoval['aliases']);
    assertSameValue($before, $afterRemoval['occurrences']);
});

$suite->test('lo stesso alias è ammesso su Concept diversi ma non due volte nello stesso', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $first = conceptWithOccurrence($service, 'Primo concetto');
    $second = conceptWithOccurrence($service, 'Secondo concetto');

    $knowledge->addAlias($first, ['alias' => 'Ambiguo']);
    $knowledge->addAlias($second, ['alias' => 'Ambiguo']);

    try {
        $knowledge->addAlias($first, ['alias' => 'ambiguo']);
        throw new RuntimeException('Alias duplicato accettato.');
    } catch (ApiException $error) {
        assertSameValue('alias_duplicate', $error->errorCode);
    }

    assertSameValue(1, count($knowledge->object($first)['aliases']));
});

$suite->test('la ricerca per alias mostra Concept distinti, una sola volta ciascuno', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $first = conceptWithOccurrence($service, 'Trasformata di Fourier');
    $second = conceptWithOccurrence($service, 'Analisi armonica');
    $knowledge->addAlias($first, ['alias' => 'Spettro']);
    $knowledge->addAlias($first, ['alias' => 'Spettro di frequenza']);
    $knowledge->addAlias($second, ['alias' => 'Spettro']);

    $results = $knowledge->search('Spettro');
    $ids = array_column($results, 'id');

    assertSameValue(2, count($ids));
    assertTrue(in_array($first, $ids, true) && in_array($second, $ids, true), 'Entrambi i Concept devono comparire.');
    assertSameValue(2, count(array_unique($ids)));
});

$suite->test('un alias su una Entity e un identificatore su un Concept vengono rifiutati', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $conceptId = conceptWithOccurrence($service, 'Concetto puro');
    $entityId = entityWithOccurrence($service, $knowledge, 'Entity pura', 'Tipo alias');

    try {
        $knowledge->addAlias($entityId, ['alias' => 'Non ammesso']);
        throw new RuntimeException('Alias su Entity accettato.');
    } catch (ApiException $error) {
        assertSameValue('alias_requires_concept', $error->errorCode);
    }

    try {
        $knowledge->addIdentifier($conceptId, ['scheme' => 'lei', 'value' => 'ABC']);
        throw new RuntimeException('Identifier su Concept accettato.');
    } catch (ApiException $error) {
        assertSameValue('identifier_requires_entity', $error->errorCode);
    }
});

$suite->test('INV-EID-02: la stessa identità normalizzata non si ripete nella stessa Entity', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $entityId = entityWithOccurrence($service, $knowledge, 'Apple', 'Azienda quotata');

    $added = $knowledge->addIdentifier($entityId, ['scheme' => 'cik', 'value' => '320193']);
    assertSameValue('0000320193', $added['object']['identifiers'][0]['normalized_value']);
    assertSameValue('320193', $added['object']['identifiers'][0]['value']);
    assertSameValue([], $added['duplicateCandidates']);

    try {
        $knowledge->addIdentifier($entityId, ['scheme' => 'CIK', 'value' => ' 0000320193 ']);
        throw new RuntimeException('Identificatore duplicato accettato.');
    } catch (ApiException $error) {
        assertSameValue('identifier_duplicate', $error->errorCode);
    }

    assertSameValue(1, count($knowledge->object($entityId)['identifiers']));
});

$suite->test('INV-EID-04: l’authority partecipa all’identità e quando serve è obbligatoria', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $entityId = entityWithOccurrence($service, $knowledge, 'Doppia quotazione', 'Azienda multipla');

    try {
        $knowledge->addIdentifier($entityId, ['scheme' => 'ticker', 'value' => 'RKLB']);
        throw new RuntimeException('Ticker senza authority accettato.');
    } catch (ApiException $error) {
        assertSameValue('identifier_authority_required', $error->errorCode);
    }

    $knowledge->addIdentifier($entityId, ['scheme' => 'ticker', 'value' => 'RKLB', 'authorityOrNamespace' => 'NASDAQ']);
    $object = $knowledge->addIdentifier($entityId, ['scheme' => 'ticker', 'value' => 'RKLB', 'authorityOrNamespace' => 'XETRA'])['object'];

    assertSameValue(2, count($object['identifiers']));
    assertSameValue(1, $object['identifiers'][0]['normalization_version']);
});

$suite->test('INV-EID-02: la stessa identità su Entity diverse produce candidati duplicati senza merge', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $first = entityWithOccurrence($service, $knowledge, 'Prima società', 'Azienda duplicata');
    $second = entityWithOccurrence($service, $knowledge, 'Seconda società', 'Azienda duplicata');
    $knowledge->addIdentifier($first, ['scheme' => 'lei', 'value' => '5493001KJTIIGC8Y1R12']);

    $added = $knowledge->addIdentifier($second, ['scheme' => 'lei', 'value' => '5493001kjtiigc8y1r12']);

    assertSameValue(1, count($added['duplicateCandidates']));
    assertSameValue($first, $added['duplicateCandidates'][0]['id']);
    assertSameValue('Prima società', $added['duplicateCandidates'][0]['name']);
    assertSameValue(1, count($knowledge->object($first)['identifiers']));
    assertSameValue(1, count($knowledge->object($second)['identifiers']));
    assertSameValue('active', $knowledge->object($first)['status']);
});

$suite->test('INV-EID-05: lo scheme è una chiave lowercase stabile con policy versionata', static function (): void {
    $normalizer = new IdentifierNormalizer();

    assertSameValue(1, $normalizer->version('ticker'));
    assertSameValue(1, $normalizer->version('scheme_non_dichiarato'));
    assertSameValue(false, $normalizer->requiresAuthority('lei'));
    assertSameValue(true, $normalizer->requiresAuthority('ticker'));
    assertSameValue('rklb', $normalizer->normalize('ticker', ' rk lb '));
    assertSameValue('id clinico 12', $normalizer->normalize('internal_clinical_id', '  ID   Clinico 12 '));

    assertThrows(static fn () => $normalizer->assertScheme('Ticker'), 'Uno scheme non lowercase deve essere rifiutato.');
    assertThrows(static fn () => $normalizer->assertScheme('2ticker'), 'Uno scheme non stabile deve essere rifiutato.');
});

$suite->test('rinominare un KnowledgeObject non tocca occurrence, alias e identificatori', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $conceptId = conceptWithOccurrence($service, 'Nome iniziale');
    $knowledge->addAlias($conceptId, ['alias' => 'Alias stabile']);
    $before = $knowledge->object($conceptId);

    $updated = $knowledge->updateObject($conceptId, ['name' => 'Nome corretto', 'description' => '  Una descrizione  ']);

    assertSameValue('Nome corretto', $updated['name']);
    assertSameValue('Una descrizione', $updated['description']);
    assertSameValue($before['occurrences'], $updated['occurrences']);
    assertSameValue($before['aliases'], $updated['aliases']);
    assertSameValue($before['status'], $updated['status']);

    $cleared = $knowledge->updateObject($conceptId, ['name' => 'Nome corretto', 'description' => '   ']);
    assertSameValue(null, $cleared['description']);
});

$suite->test('rinominare una Entity non cambia EntityType ne identificatori', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $entityId = entityWithOccurrence($service, $knowledge, 'Entity da rinominare', 'Tipo stabile');
    $knowledge->addIdentifier($entityId, ['scheme' => 'lei', 'value' => 'X1']);

    $updated = $knowledge->updateObject($entityId, ['name' => 'Entity rinominata']);

    assertSameValue('Entity rinominata', $updated['name']);
    assertSameValue('Tipo stabile', $updated['entityType']['name']);
    assertSameValue(1, count($updated['identifiers']));
});

$suite->test('un nome vuoto o un campo non previsto vengono rifiutati', static function () use ($pdo, $service): void {
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $conceptId = conceptWithOccurrence($service, 'Nome valido');

    try {
        $knowledge->updateObject($conceptId, ['name' => '   ']);
        throw new RuntimeException('Nome vuoto accettato.');
    } catch (ApiException $error) {
        assertSameValue('invalid_request', $error->errorCode);
    }

    try {
        $knowledge->updateObject($conceptId, ['name' => 'Valido', 'status' => 'archived']);
        throw new RuntimeException('Campo non previsto accettato.');
    } catch (ApiException $error) {
        assertSameValue('invalid_request', $error->errorCode);
    }

    assertSameValue('Nome valido', $knowledge->object($conceptId)['name']);
});

/** Context service sharing the test database. */
function contextService(PDO $pdo): ContextService
{
    return new ContextService(new ContextRepository($pdo), new DocumentRepository($pdo), new KnowledgeRepository($pdo));
}

$suite->test('i Context formano una gerarchia con breadcrumb derivato', static function () use ($pdo): void {
    $contexts = contextService($pdo);
    $root = $contexts->create(['name' => 'Università']);
    $child = $contexts->create(['name' => 'Psicologia', 'parentId' => $root['id']]);
    $grandChild = $contexts->create(['name' => 'Junghiana', 'parentId' => $child['id']]);

    $breadcrumb = array_column($contexts->breadcrumb($grandChild['id']), 'name');

    assertSameValue(['Università', 'Psicologia', 'Junghiana'], $breadcrumb);
    assertSameValue(3, count($contexts->selectedIds($root['id'], 'subtree')));
    assertSameValue(1, count($contexts->selectedIds($root['id'], 'exact')));
});

$suite->test('lo stesso nome è ammesso in rami diversi ma non fra fratelli', static function () use ($pdo): void {
    $contexts = contextService($pdo);
    $first = $contexts->create(['name' => 'Ramo primo']);
    $second = $contexts->create(['name' => 'Ramo secondo']);
    $contexts->create(['name' => 'Metodi', 'parentId' => $first['id']]);
    $contexts->create(['name' => 'Metodi', 'parentId' => $second['id']]);

    assertThrows(
        static fn () => $contexts->create(['name' => 'metodi', 'parentId' => $first['id']]),
        'Due fratelli omonimi devono essere rifiutati.',
    );
});

$suite->test('il move sposta un ramo intero e rifiuta i cicli', static function () use ($pdo): void {
    $contexts = contextService($pdo);
    $root = $contexts->create(['name' => 'Radice move']);
    $branch = $contexts->create(['name' => 'Ramo', 'parentId' => $root['id']]);
    $leaf = $contexts->create(['name' => 'Foglia', 'parentId' => $branch['id']]);
    $other = $contexts->create(['name' => 'Altra radice']);

    $contexts->move($branch['id'], ['parentId' => $other['id']]);
    assertSameValue(['Altra radice', 'Ramo', 'Foglia'], array_column($contexts->breadcrumb($leaf['id']), 'name'));

    try {
        $contexts->move($branch['id'], ['parentId' => $leaf['id']]);
        throw new RuntimeException('Ciclo accettato.');
    } catch (ApiException $error) {
        assertSameValue('context_cycle', $error->errorCode);
    }

    try {
        $contexts->move($branch['id'], ['parentId' => $branch['id']]);
        throw new RuntimeException('Auto-parenting accettato.');
    } catch (ApiException $error) {
        assertSameValue('context_cycle', $error->errorCode);
    }

    assertSameValue(['Altra radice', 'Ramo'], array_column($contexts->breadcrumb($branch['id']), 'name'));
});

$suite->test('la cancellazione di un Context con figli o Document richiede riassegnazione', static function () use ($pdo, $service): void {
    $contexts = contextService($pdo);
    $root = $contexts->create(['name' => 'Radice prudente']);
    $child = $contexts->create(['name' => 'Figlio', 'parentId' => $root['id']]);
    $document = $service->create(['title' => 'Assegnato']);
    $contexts->assignDocument($document['id'], ['contextId' => $child['id']]);

    try {
        $contexts->delete($root['id']);
        throw new RuntimeException('Context con figli eliminato.');
    } catch (ApiException $error) {
        assertSameValue('context_has_children', $error->errorCode);
    }

    try {
        $contexts->delete($child['id']);
        throw new RuntimeException('Context con Document eliminato.');
    } catch (ApiException $error) {
        assertSameValue('context_has_documents', $error->errorCode);
    }

    $contexts->assignDocument($document['id'], ['contextId' => null]);
    $contexts->delete($child['id']);
    $contexts->delete($root['id']);
    assertSameValue(null, $service->get($document['id'])['contextId']);
});

$suite->test('il filtro exact e subtree seleziona i Document del ramo', static function () use ($pdo, $service): void {
    $contexts = contextService($pdo);
    $root = $contexts->create(['name' => 'Corso']);
    $child = $contexts->create(['name' => 'Lezione', 'parentId' => $root['id']]);
    $rootDocument = $service->create(['title' => 'Documento del corso']);
    $childDocument = $service->create(['title' => 'Documento della lezione']);
    $contexts->assignDocument($rootDocument['id'], ['contextId' => $root['id']]);
    $contexts->assignDocument($childDocument['id'], ['contextId' => $child['id']]);

    $exact = array_column($service->list('active', $contexts->selectedIds($root['id'], 'exact')), 'id');
    $subtree = array_column($service->list('active', $contexts->selectedIds($root['id'], 'subtree')), 'id');

    assertSameValue([$rootDocument['id']], $exact);
    assertTrue(in_array($childDocument['id'], $subtree, true), 'Il subtree deve includere i Document dei discendenti.');
    assertSameValue(2, count($subtree));
});

$suite->test('lo stesso KnowledgeObject compare in più Context senza duplicazione', static function () use ($pdo, $service): void {
    $contexts = contextService($pdo);
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $root = $contexts->create(['name' => 'Ricerca']);
    $child = $contexts->create(['name' => 'Sotto ricerca', 'parentId' => $root['id']]);

    $conceptId = UuidV7::generate();
    $first = $service->create(['title' => 'Primo']);
    $second = $service->create(['title' => 'Secondo']);
    $firstOccurrence = UuidV7::generate();
    $secondOccurrence = UuidV7::generate();
    saveRevision($service, $first['id'], 0, documentOfParagraphs([occurrenceText('Idea', $firstOccurrence, $conceptId)]), [
        conceptCreate($firstOccurrence, $conceptId, 'Idea condivisa'),
    ]);
    saveRevision($service, $second['id'], 0, documentOfParagraphs([occurrenceText('Idea', $secondOccurrence, $conceptId)]), [
        ['occurrenceId' => $secondOccurrence, 'knowledgeObjectId' => $conceptId, 'objectType' => 'concept', 'newObject' => false],
    ]);
    $contexts->assignDocument($first['id'], ['contextId' => $root['id']]);
    $contexts->assignDocument($second['id'], ['contextId' => $child['id']]);

    $subtree = $contexts->knowledgeObjects($root['id'], 'subtree');
    $exact = $contexts->knowledgeObjects($root['id'], 'exact');

    assertSameValue(1, count($subtree));
    assertSameValue($conceptId, $subtree[0]['id']);
    assertSameValue('concept', $subtree[0]['object_type']);
    assertSameValue(1, count($exact));
    assertSameValue(1, count($contexts->knowledgeObjects($child['id'], 'exact')));
});

$suite->test('un Document non riceve un Context per effetto collaterale del salvataggio', static function () use ($pdo, $service): void {
    $contexts = contextService($pdo);
    $context = $contexts->create(['name' => 'Non assegnato automaticamente']);
    $document = $service->create(['title' => 'Senza Context']);

    saveRevision($service, $document['id'], 0, emptyDocument());

    assertSameValue(null, $service->get($document['id'])['contextId']);
    $contexts->assignDocument($document['id'], ['contextId' => $context['id']]);
    assertSameValue($context['id'], $service->get($document['id'])['contextId']);

    saveRevision($service, $document['id'], 1, emptyDocument());
    assertSameValue($context['id'], $service->get($document['id'])['contextId']);
});

function tagService(PDO $pdo): TagService
{
    return new TagService(new TagRepository($pdo), new DocumentRepository($pdo));
}

function queryService(PDO $pdo, DocumentService $service): QueryService
{
    return new QueryService(contextService($pdo), tagService($pdo), $service, new KnowledgeRepository($pdo));
}

$suite->test('i Tag hanno nome unico normalizzato e ignorano il cancelletto', static function () use ($pdo): void {
    $tags = tagService($pdo);

    $created = $tags->create(['name' => '  #Metodo  ']);
    assertSameValue('Metodo', $created['name']);

    try {
        $tags->create(['name' => 'metodo']);
        throw new RuntimeException('Tag duplicato accettato.');
    } catch (ApiException $error) {
        assertSameValue('tag_duplicate', $error->errorCode);
    }

    $renamed = $tags->rename($created['id'], ['name' => 'Metodologia']);
    assertSameValue('Metodologia', $renamed['name']);
});

$suite->test('l’assegnazione di un Tag è idempotente e la rimozione non elimina il Tag', static function () use ($pdo, $service): void {
    $tags = tagService($pdo);
    $tag = $tags->create(['name' => 'Da rileggere']);
    $document = $service->create(['title' => 'Con tag']);

    $tags->assign($document['id'], ['tagId' => $tag['id']]);
    $assigned = $tags->assign($document['id'], ['tagId' => $tag['id']]);
    assertSameValue(1, count($assigned));

    try {
        $tags->delete($tag['id']);
        throw new RuntimeException('Tag assegnato eliminato.');
    } catch (ApiException $error) {
        assertSameValue('tag_assigned', $error->errorCode);
    }

    assertSameValue([], $tags->unassign($document['id'], $tag['id']));
    assertSameValue(1, count(array_filter($tags->list(), static fn (array $row): bool => $row['id'] === $tag['id'])));
    $tags->delete($tag['id']);
});

$suite->test('il filtro combinato richiede tutti i Tag chiesti e rispetta il Context', static function () use ($pdo, $service): void {
    $tags = tagService($pdo);
    $contexts = contextService($pdo);
    $query = queryService($pdo, $service);
    $context = $contexts->create(['name' => 'Corso combinato']);
    $other = $contexts->create(['name' => 'Fuori corso']);
    $primo = $tags->create(['name' => 'Primo tag']);
    $secondo = $tags->create(['name' => 'Secondo tag']);

    $entrambi = $service->create(['title' => 'Con entrambi']);
    $soloPrimo = $service->create(['title' => 'Con il primo']);
    $fuori = $service->create(['title' => 'Fuori contesto']);
    foreach ([[$entrambi, [$primo, $secondo], $context], [$soloPrimo, [$primo], $context], [$fuori, [$primo, $secondo], $other]] as [$document, $applied, $where]) {
        foreach ($applied as $tag) {
            $tags->assign($document['id'], ['tagId' => $tag['id']]);
        }
        $contexts->assignDocument($document['id'], ['contextId' => $where['id']]);
    }

    $byTags = array_column($query->documents('active', null, 'subtree', "{$primo['id']},{$secondo['id']}"), 'id');
    assertSameValue(2, count($byTags));
    assertTrue(!in_array($soloPrimo['id'], $byTags, true), 'Il filtro deve richiedere tutti i Tag chiesti.');

    $combined = array_column($query->documents('active', $context['id'], 'subtree', "{$primo['id']},{$secondo['id']}"), 'id');
    assertSameValue([$entrambi['id']], $combined);

    $onlyContext = array_column($query->documents('active', $context['id'], 'subtree', ''), 'id');
    assertSameValue(2, count($onlyContext));
});

$suite->test('KnowledgeObject × Context × Tag deriva Concept ed Entity senza duplicarli', static function () use ($pdo, $service): void {
    $tags = tagService($pdo);
    $contexts = contextService($pdo);
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $query = queryService($pdo, $service);
    $context = $contexts->create(['name' => 'Ricerca combinata']);
    $tag = $tags->create(['name' => 'Rilevante']);
    $entityType = $knowledge->createEntityType(['name' => 'Autore']);

    $conceptId = UuidV7::generate();
    $entityId = UuidV7::generate();
    $first = $service->create(['title' => 'Primo combinato']);
    $second = $service->create(['title' => 'Secondo combinato']);
    $firstOccurrence = UuidV7::generate();
    $secondOccurrence = UuidV7::generate();
    $entityOccurrence = UuidV7::generate();

    saveRevision($service, $first['id'], 0, documentOfParagraphs([occurrenceText('Idea', $firstOccurrence, $conceptId)]), [
        conceptCreate($firstOccurrence, $conceptId, 'Idea ricorrente'),
    ]);
    saveRevision($service, $second['id'], 0, documentOfParagraphs([
        occurrenceText('Idea', $secondOccurrence, $conceptId),
        ['type' => 'text', 'text' => ' e '],
        occurrenceText('Jung', $entityOccurrence, $entityId, 'entity'),
    ]), [
        ['occurrenceId' => $secondOccurrence, 'knowledgeObjectId' => $conceptId, 'objectType' => 'concept', 'newObject' => false],
        ['occurrenceId' => $entityOccurrence, 'knowledgeObjectId' => $entityId, 'objectType' => 'entity', 'newObject' => true, 'name' => 'Carl Jung', 'entityTypeId' => $entityType['id']],
    ]);
    foreach ([$first, $second] as $document) {
        $contexts->assignDocument($document['id'], ['contextId' => $context['id']]);
        $tags->assign($document['id'], ['tagId' => $tag['id']]);
    }

    $objects = $query->knowledgeObjects('active', $context['id'], 'subtree', $tag['id']);
    $ids = array_column($objects, 'id');

    assertSameValue(2, count($ids));
    assertSameValue(2, count(array_unique($ids)));
    assertTrue(in_array($conceptId, $ids, true) && in_array($entityId, $ids, true), 'Devono comparire entrambi i sottotipi.');
    assertSameValue(1, count($query->knowledgeObjects('active', null, 'subtree', $tag['id'])) - 1);
});

$suite->test('lo stesso nome in dimensioni diverse resta cose diverse', static function () use ($pdo, $service): void {
    $tags = tagService($pdo);
    $contexts = contextService($pdo);
    $knowledge = new KnowledgeService(new KnowledgeRepository($pdo), new OccurrenceTextExtractor());
    $query = queryService($pdo, $service);

    $tag = $tags->create(['name' => 'Omonimo']);
    $context = $contexts->create(['name' => 'Omonimo']);
    $conceptId = conceptWithOccurrence($service, 'Omonimo');

    // Il Concept omonimo vive in un Document senza quel Tag e senza quel Context.
    assertSameValue([], $query->knowledgeObjects('active', null, 'subtree', $tag['id']));
    assertSameValue([], $query->knowledgeObjects('active', $context['id'], 'subtree', ''));
    assertSameValue('Omonimo', $knowledge->object($conceptId)['name']);
    assertSameValue('Omonimo', $tag['name']);
    assertSameValue('Omonimo', $context['name']);
});

$suite->test('un Document archiviato non accetta assegnazioni di Tag', static function () use ($pdo, $service): void {
    $tags = tagService($pdo);
    $tag = $tags->create(['name' => 'Su archiviato']);
    $document = $service->create(['title' => 'Archiviato per tag']);
    $service->archive($document['id']);

    try {
        $tags->assign($document['id'], ['tagId' => $tag['id']]);
        throw new RuntimeException('Tag assegnato a un Document archiviato.');
    } catch (ApiException $error) {
        assertSameValue('document_read_only', $error->errorCode);
    }
});

$suite->test('lo schema finale non contiene violazioni di foreign key', static function () use ($pdo): void {
    assertSameValue([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
    assertSameValue('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
});

$suite->finish();

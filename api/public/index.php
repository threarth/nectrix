<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Nectrix\ApiException;
use Nectrix\ContextRepository;
use Nectrix\ContextService;
use Nectrix\Database;
use Nectrix\DocumentRepository;
use Nectrix\DocumentService;
use Nectrix\DocumentValidator;
use Nectrix\Migrator;
use Nectrix\KnowledgeOccurrenceExtractor;
use Nectrix\KnowledgeRepository;
use Nectrix\KnowledgeService;
use Nectrix\OccurrenceTextExtractor;
use Nectrix\PlainTextExtractor;
use Nectrix\ReferenceExtractor;
use Nectrix\ReferenceRepository;
use Nectrix\RelationRepository;
use Nectrix\RelationService;
use Nectrix\EvidenceRepository;
use Nectrix\EvidenceService;
use Nectrix\CompareRepository;
use Nectrix\CompareService;
use Nectrix\FieldFilterCompiler;
use Nectrix\MatrixRepository;
use Nectrix\MatrixService;
use Nectrix\QueryService;
use Nectrix\FieldValueValidator;
use Nectrix\SearchRepository;
use Nectrix\SearchService;
use Nectrix\TagRepository;
use Nectrix\TagService;
use Nectrix\SemanticBlockRepository;
use Nectrix\SemanticBlockService;
use Nectrix\TemplateRepository;
use Nectrix\TemplateService;
use Nectrix\StructuredQueryRepository;
use Nectrix\StructuredQueryService;

require dirname(__DIR__) . '/bootstrap.php';

const MAX_BODY_BYTES = 2_097_152;

/** @param mixed $payload */
function respond(int $status, mixed $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** @return array<string, mixed> */
function requestBody(): array
{
    $body = file_get_contents('php://input');
    if ($body === false || strlen($body) > MAX_BODY_BYTES) {
        throw new ApiException(413, 'request_too_large', 'Il body supera il limite consentito.');
    }
    try {
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ApiException(400, 'invalid_json', 'Il body non contiene JSON valido.');
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new ApiException(400, 'invalid_json', 'Il body deve essere un oggetto JSON.');
    }
    return $decoded;
}

try {
    $databasePath = getenv('NECTRIX_DB_PATH') ?: dirname(__DIR__, 2) . '/data/nectrix.sqlite';
    $pdo = Database::connect($databasePath);
    (new Migrator($pdo, dirname(__DIR__) . '/migrations'))->migrate();
    $knowledgeRepository = new KnowledgeRepository($pdo);
    $service = new DocumentService(
        new DocumentRepository($pdo),
        new DocumentValidator(),
        new PlainTextExtractor(),
        new KnowledgeOccurrenceExtractor(),
        $knowledgeRepository,
        new ReferenceExtractor(),
        new ReferenceRepository($pdo),
    );
    $knowledge = new KnowledgeService($knowledgeRepository, new OccurrenceTextExtractor());
    $documentRepository = new DocumentRepository($pdo);
    $contextRepository = new ContextRepository($pdo);
    $contexts = new ContextService($contextRepository, $documentRepository, $knowledgeRepository);
    $tags = new TagService(new TagRepository($pdo), $documentRepository);
    $query = new QueryService($contexts, $tags, $service, $knowledgeRepository);
    $search = new SearchService(new SearchRepository($pdo), $contextRepository);
    $templateRepository = new TemplateRepository($pdo);
    $fieldFilters = new FieldFilterCompiler($templateRepository);
    $blockRepository = new SemanticBlockRepository($pdo);
    $fieldValidator = new FieldValueValidator();
    $templates = new TemplateService($templateRepository, $blockRepository, $fieldValidator);
    $semanticBlocks = new SemanticBlockService($blockRepository, $templateRepository, $fieldValidator);
    $structured = new StructuredQueryService(new StructuredQueryRepository($pdo), $fieldFilters, $query);
    $relationRepository = new RelationRepository($pdo);
    $relations = new RelationService($relationRepository, $knowledgeRepository);
    $evidence = new EvidenceService(new EvidenceRepository($pdo), $relationRepository);
    $compare = new CompareService(new CompareRepository($pdo), $knowledge, $relations, $templateRepository);
    $matrix = new MatrixService(new MatrixRepository($pdo), $contextRepository, $fieldFilters);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if ($method === 'GET' && $path === '/api/health') {
        respond(200, ['status' => 'ok']);
    }
    if ($method === 'GET' && $path === '/api/documents') {
        respond(200, ['documents' => $query->documents(
            (string) ($_GET['scope'] ?? 'active'),
            isset($_GET['contextId']) ? (string) $_GET['contextId'] : null,
            (string) ($_GET['contextMode'] ?? 'subtree'),
            (string) ($_GET['tagIds'] ?? ''),
        )]);
    }
    if ($method === 'GET' && $path === '/api/knowledge-objects/derived') {
        respond(200, ['objects' => $query->knowledgeObjects(
            (string) ($_GET['scope'] ?? 'active'),
            isset($_GET['contextId']) ? (string) $_GET['contextId'] : null,
            (string) ($_GET['contextMode'] ?? 'subtree'),
            (string) ($_GET['tagIds'] ?? ''),
        )]);
    }
    if ($method === 'POST' && $path === '/api/search/structured') {
        respond(200, $structured->search(requestBody()));
    }
    if ($method === 'GET' && $path === '/api/search/fields') {
        respond(200, ['fields' => $structured->fields((string) ($_GET['q'] ?? ''))]);
    }
    if ($method === 'GET' && $path === '/api/references') {
        $split = static fn (string $value): array => array_values(array_filter(explode(',', $value), static fn (string $id): bool => $id !== ''));
        respond(200, (new ReferenceRepository($pdo))->resolve(
            $split((string) ($_GET['entities'] ?? '')),
            $split((string) ($_GET['blocks'] ?? '')),
        ));
    }
    if ($method === 'GET' && $path === '/api/templates') {
        respond(200, ['templates' => $templates->list()]);
    }
    if ($method === 'POST' && $path === '/api/templates') {
        respond(201, ['template' => $templates->create(requestBody())]);
    }
    if (preg_match('#^/api/templates/([^/]+)$#', (string) $path, $matches) === 1 && $method === 'PUT') {
        respond(200, ['template' => $templates->update(rawurldecode($matches[1]), requestBody())]);
    }
    if ($method === 'POST' && preg_match('#^/api/templates/([^/]+)/(archive|restore)$#', (string) $path, $matches) === 1) {
        respond(200, ['template' => $templates->setArchived(rawurldecode($matches[1]), $matches[2] === 'archive')]);
    }
    if ($method === 'POST' && preg_match('#^/api/templates/([^/]+)/fields$#', (string) $path, $matches) === 1) {
        respond(201, ['template' => $templates->addField(rawurldecode($matches[1]), requestBody())]);
    }
    if ($method === 'POST' && preg_match('#^/api/templates/([^/]+)/fields/order$#', (string) $path, $matches) === 1) {
        respond(200, ['template' => $templates->reorderFields(rawurldecode($matches[1]), requestBody())]);
    }
    if (preg_match('#^/api/template-fields/([^/]+)$#', (string) $path, $matches) === 1) {
        $fieldId = rawurldecode($matches[1]);
        if ($method === 'PUT') {
            respond(200, ['template' => $templates->updateField($fieldId, requestBody())]);
        }
        if ($method === 'DELETE') {
            respond(200, ['template' => $templates->deleteField($fieldId)]);
        }
    }
    if ($method === 'POST' && preg_match('#^/api/template-fields/([^/]+)/type$#', (string) $path, $matches) === 1) {
        respond(200, $templates->migrateFieldType(rawurldecode($matches[1]), requestBody()));
    }
    if (preg_match('#^/api/entity-types/([^/]+)/templates$#', (string) $path, $matches) === 1) {
        $entityTypeId = rawurldecode($matches[1]);
        if ($method === 'GET') {
            respond(200, ['templates' => $templates->recommendations($entityTypeId)]);
        }
        if ($method === 'POST') {
            respond(200, ['templates' => $templates->recommend($entityTypeId, requestBody())]);
        }
    }
    if ($method === 'DELETE' && preg_match('#^/api/entity-types/([^/]+)/templates/([^/]+)$#', (string) $path, $matches) === 1) {
        respond(200, ['templates' => $templates->unrecommend(rawurldecode($matches[1]), rawurldecode($matches[2]))]);
    }
    if (preg_match('#^/api/knowledge-objects/([^/]+)/blocks$#', (string) $path, $matches) === 1) {
        $objectId = rawurldecode($matches[1]);
        if ($method === 'GET') {
            respond(200, ['blocks' => $semanticBlocks->blocksOf($objectId)]);
        }
        if ($method === 'POST') {
            respond(201, ['blocks' => $semanticBlocks->addBlock($objectId, requestBody())]);
        }
    }
    if (preg_match('#^/api/semantic-blocks/([^/]+)$#', (string) $path, $matches) === 1 && $method === 'DELETE') {
        respond(200, ['blocks' => $semanticBlocks->deleteBlock(rawurldecode($matches[1]))]);
    }
    if ($method === 'POST' && preg_match('#^/api/semantic-blocks/([^/]+)/values$#', (string) $path, $matches) === 1) {
        respond(200, ['blocks' => $semanticBlocks->setValues(rawurldecode($matches[1]), requestBody())]);
    }
    if ($method === 'GET' && $path === '/api/tags') {
        respond(200, ['tags' => $tags->list()]);
    }
    if ($method === 'POST' && $path === '/api/tags') {
        respond(201, ['tag' => $tags->create(requestBody())]);
    }
    if (preg_match('#^/api/tags/([^/]+)$#', (string) $path, $matches) === 1) {
        $tagId = rawurldecode($matches[1]);
        if ($method === 'PUT') {
            respond(200, ['tag' => $tags->rename($tagId, requestBody())]);
        }
        if ($method === 'DELETE') {
            $tags->delete($tagId);
            respond(200, ['deleted' => true]);
        }
    }
    if (preg_match('#^/api/documents/([^/]+)/tags$#', (string) $path, $matches) === 1) {
        $documentId = rawurldecode($matches[1]);
        if ($method === 'GET') {
            respond(200, ['tags' => $tags->documentTags($documentId)]);
        }
        if ($method === 'POST') {
            respond(200, ['tags' => $tags->assign($documentId, requestBody())]);
        }
    }
    if ($method === 'DELETE' && preg_match('#^/api/documents/([^/]+)/tags/([^/]+)$#', (string) $path, $matches) === 1) {
        respond(200, ['tags' => $tags->unassign(rawurldecode($matches[1]), rawurldecode($matches[2]))]);
    }
    if ($method === 'GET' && $path === '/api/contexts') {
        respond(200, ['contexts' => $contexts->list()]);
    }
    if ($method === 'POST' && $path === '/api/contexts') {
        respond(201, ['context' => $contexts->create(requestBody())]);
    }
    if (preg_match('#^/api/contexts/([^/]+)$#', (string) $path, $matches) === 1) {
        $contextId = rawurldecode($matches[1]);
        if ($method === 'PUT') {
            respond(200, ['context' => $contexts->rename($contextId, requestBody())]);
        }
        if ($method === 'DELETE') {
            $contexts->delete($contextId);
            respond(200, ['deleted' => true]);
        }
    }
    if ($method === 'POST' && preg_match('#^/api/contexts/([^/]+)/move$#', (string) $path, $matches) === 1) {
        respond(200, ['context' => $contexts->move(rawurldecode($matches[1]), requestBody())]);
    }
    if ($method === 'GET' && preg_match('#^/api/contexts/([^/]+)/breadcrumb$#', (string) $path, $matches) === 1) {
        respond(200, ['breadcrumb' => $contexts->breadcrumb(rawurldecode($matches[1]))]);
    }
    if ($method === 'GET' && preg_match('#^/api/contexts/([^/]+)/knowledge-objects$#', (string) $path, $matches) === 1) {
        respond(200, [
            'objects' => $contexts->knowledgeObjects(rawurldecode($matches[1]), (string) ($_GET['mode'] ?? 'subtree')),
        ]);
    }
    if ($method === 'POST' && preg_match('#^/api/documents/([^/]+)/context$#', (string) $path, $matches) === 1) {
        respond(200, ['document' => $contexts->assignDocument(rawurldecode($matches[1]), requestBody())]);
    }
    if ($method === 'POST' && preg_match('#^/api/documents/([^/]+)/(archive|trash|restore)$#', (string) $path, $matches) === 1) {
        $documentId = rawurldecode($matches[1]);
        $document = match ($matches[2]) {
            'archive' => $service->archive($documentId),
            'trash' => $service->trash($documentId),
            default => $service->restore($documentId),
        };
        respond(200, ['document' => $document]);
    }
    if ($method === 'GET' && $path === '/api/search') {
        $objectId = isset($_GET['objectId']) ? (string) $_GET['objectId'] : '';
        respond(200, $objectId === ''
            ? $search->search((string) ($_GET['q'] ?? ''))
            : $search->byObject($objectId));
    }
    if ($method === 'POST' && $path === '/api/search/rebuild') {
        $search->rebuildIndex();
        respond(200, ['rebuilt' => true]);
    }
    if ($method === 'GET' && $path === '/api/knowledge/search') {
        respond(200, ['results' => $knowledge->search((string) ($_GET['q'] ?? ''))]);
    }
    if ($method === 'GET' && $path === '/api/knowledge-objects') {
        respond(200, ['objects' => $knowledge->resolveObjects((string) ($_GET['ids'] ?? ''))]);
    }
    if ($method === 'GET' && $path === '/api/entity-types') {
        respond(200, ['entityTypes' => $knowledge->entityTypes()]);
    }
    if ($method === 'POST' && $path === '/api/entity-types') {
        respond(201, ['entityType' => $knowledge->createEntityType(requestBody())]);
    }
    if ($method === 'POST' && $path === '/api/documents') {
        respond(201, ['document' => $service->create(requestBody())]);
    }
    if ($method === 'GET' && preg_match('#^/api/knowledge-objects/([^/]+)$#', (string) $path, $matches) === 1) {
        respond(200, ['object' => $knowledge->object(rawurldecode($matches[1]))]);
    }
    if ($method === 'PUT' && preg_match('#^/api/knowledge-objects/([^/]+)$#', (string) $path, $matches) === 1) {
        respond(200, ['object' => $knowledge->updateObject(rawurldecode($matches[1]), requestBody())]);
    }
    if (preg_match('#^/api/(relations|field-values)/([^/]+)/evidence$#', (string) $path, $matches) === 1) {
        $subject = $matches[1] === 'relations' ? 'relation' : 'field_value';
        $subjectId = rawurldecode($matches[2]);
        if ($method === 'GET') {
            respond(200, ['evidence' => $evidence->of($subject, $subjectId)]);
        }
        if ($method === 'POST') {
            respond(201, ['evidence' => $evidence->add($subject, $subjectId, requestBody())]);
        }
    }
    if ($method === 'DELETE' && preg_match('#^/api/(relations|field-values)/([^/]+)/evidence/([^/]+)/([^/]+)$#', (string) $path, $matches) === 1) {
        respond(200, ['evidence' => $evidence->remove(
            $matches[1] === 'relations' ? 'relation' : 'field_value',
            rawurldecode($matches[2]),
            rawurldecode($matches[3]),
            rawurldecode($matches[4]),
        )]);
    }
    if ($method === 'POST' && $path === '/api/compare') {
        respond(200, $compare->compare(requestBody()));
    }
    if ($method === 'POST' && $path === '/api/matrix') {
        respond(200, $matrix->matrix(requestBody()));
    }
    if ($method === 'POST' && $path === '/api/matrix/cell') {
        respond(200, $matrix->cell(requestBody()));
    }
    if ($method === 'GET' && $path === '/api/relation-types') {
        respond(200, ['types' => $relations->types()]);
    }
    if (preg_match('#^/api/knowledge-objects/([^/]+)/relations$#', (string) $path, $matches) === 1) {
        $objectId = rawurldecode($matches[1]);
        if ($method === 'GET') {
            respond(200, ['relations' => $relations->of($objectId)]);
        }
        if ($method === 'POST') {
            respond(201, ['relations' => $relations->create($objectId, requestBody())]);
        }
    }
    if ($method === 'DELETE' && preg_match('#^/api/knowledge-objects/([^/]+)/relations/([^/]+)$#', (string) $path, $matches) === 1) {
        respond(200, ['relations' => $relations->delete(rawurldecode($matches[2]), rawurldecode($matches[1]))]);
    }
    if ($method === 'POST' && preg_match('#^/api/knowledge-objects/([^/]+)/aliases$#', (string) $path, $matches) === 1) {
        respond(201, ['object' => $knowledge->addAlias(rawurldecode($matches[1]), requestBody())]);
    }
    if ($method === 'DELETE' && preg_match('#^/api/concept-aliases/([^/]+)$#', (string) $path, $matches) === 1) {
        respond(200, ['object' => $knowledge->removeAlias(rawurldecode($matches[1]))]);
    }
    if ($method === 'POST' && preg_match('#^/api/knowledge-objects/([^/]+)/identifiers$#', (string) $path, $matches) === 1) {
        respond(201, $knowledge->addIdentifier(rawurldecode($matches[1]), requestBody()));
    }
    if ($method === 'DELETE' && preg_match('#^/api/entity-identifiers/([^/]+)$#', (string) $path, $matches) === 1) {
        respond(200, ['object' => $knowledge->removeIdentifier(rawurldecode($matches[1]))]);
    }
    if ($method === 'POST' && preg_match('#^/api/knowledge-objects/([^/]+)/(archive|restore)$#', (string) $path, $matches) === 1) {
        $objectId = rawurldecode($matches[1]);
        $object = $matches[2] === 'archive' ? $knowledge->archiveObject($objectId) : $knowledge->restoreObject($objectId);
        respond(200, ['object' => $object]);
    }
    if ($method === 'POST' && preg_match('#^/api/entity-types/([^/]+)/(archive|restore)$#', (string) $path, $matches) === 1) {
        $entityTypeId = rawurldecode($matches[1]);
        $entityType = $matches[2] === 'archive'
            ? $knowledge->archiveEntityType($entityTypeId)
            : $knowledge->restoreEntityType($entityTypeId);
        respond(200, ['entityType' => $entityType]);
    }
    if (preg_match('#^/api/documents/([^/]+)$#', (string) $path, $matches) === 1) {
        $id = rawurldecode($matches[1]);
        if ($method === 'GET') {
            respond(200, ['document' => $service->get($id)]);
        }
        if ($method === 'PUT') {
            respond(200, ['document' => $service->update($id, requestBody())]);
        }
    }

    throw new ApiException(404, 'route_not_found', 'Endpoint non trovato.');
} catch (ApiException $error) {
    respond($error->status, [
        'error' => [
            'code' => $error->errorCode,
            'message' => $error->getMessage(),
            'details' => $error->details,
        ],
    ]);
} catch (Throwable $error) {
    error_log((string) $error);
    respond(500, ['error' => ['code' => 'internal_error', 'message' => 'Errore interno.']]);
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Nectrix\ApiException;
use Nectrix\Database;
use Nectrix\DocumentRepository;
use Nectrix\DocumentService;
use Nectrix\DocumentValidator;
use Nectrix\Migrator;
use Nectrix\KnowledgeOccurrenceExtractor;
use Nectrix\KnowledgeRepository;
use Nectrix\KnowledgeService;
use Nectrix\PlainTextExtractor;

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
    );
    $knowledge = new KnowledgeService($knowledgeRepository);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if ($method === 'GET' && $path === '/api/health') {
        respond(200, ['status' => 'ok']);
    }
    if ($method === 'GET' && $path === '/api/documents') {
        respond(200, ['documents' => $service->list()]);
    }
    if ($method === 'GET' && $path === '/api/knowledge/search') {
        respond(200, ['results' => $knowledge->search((string) ($_GET['q'] ?? ''))]);
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

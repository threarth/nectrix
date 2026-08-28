<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use JsonException;

final class KnowledgeService
{
    private const MAX_RESOLVE_IDS = 200;
    private const MAX_QUERY_LENGTH = 200;
    private const MAX_JSON_DEPTH = 64;

    public function __construct(
        private readonly KnowledgeRepository $repository,
        private readonly OccurrenceTextExtractor $occurrenceTextExtractor,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function search(string $query): array
    {
        return $this->repository->search(substr(trim($query), 0, self::MAX_QUERY_LENGTH));
    }

    /** @return list<array<string, mixed>> */
    public function entityTypes(): array { return $this->repository->entityTypes(); }

    /**
     * Resolves a comma separated list of KnowledgeObject IDs. Missing IDs are simply absent from
     * the answer: the client uses it to drop pasted marks it cannot trust.
     *
     * @return list<array<string, mixed>>
     */
    public function resolveObjects(string $ids): array
    {
        $requested = array_values(array_filter(array_map('trim', explode(',', $ids)), static fn (string $id): bool => $id !== ''));
        if (count($requested) > self::MAX_RESOLVE_IDS) {
            throw new ApiException(422, 'invalid_request', 'Troppi ID KnowledgeObject in una sola richiesta.');
        }
        foreach ($requested as $id) {
            $this->assertId($id);
        }
        return $this->repository->resolveObjects(array_values(array_unique($requested)));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createEntityType(array $input): array
    {
        if (array_keys($input) !== ['name'] || !is_string($input['name']) || trim($input['name']) === '') {
            throw new ApiException(422, 'invalid_request', 'EntityType richiede solo un name non vuoto.');
        }
        return $this->repository->createEntityType(trim($input['name']));
    }

    /**
     * Everything the inspector of a Concept or of an Entity shows, including the occurrences with
     * their current text read from the Documents.
     *
     * @return array<string, mixed>
     */
    public function object(string $objectId): array
    {
        return $this->present($this->requireObject($objectId));
    }

    /** @return array<string, mixed> */
    public function archiveObject(string $objectId): array
    {
        $row = $this->requireObject($objectId);
        $this->repository->archiveObject($objectId, (string) $row['object_type']);
        return $this->object($objectId);
    }

    /** @return array<string, mixed> */
    public function restoreObject(string $objectId): array
    {
        $row = $this->requireObject($objectId);
        $this->repository->restoreObject($objectId, (string) $row['object_type']);
        return $this->object($objectId);
    }

    /** @return array<string, mixed> */
    public function archiveEntityType(string $entityTypeId): array
    {
        return $this->changeEntityTypeStatus($entityTypeId, 'archived');
    }

    /** @return array<string, mixed> */
    public function restoreEntityType(string $entityTypeId): array
    {
        return $this->changeEntityTypeStatus($entityTypeId, 'active');
    }

    /** @return array<string, mixed> */
    private function changeEntityTypeStatus(string $entityTypeId, string $status): array
    {
        $this->assertId($entityTypeId);
        if ($this->repository->findEntityType($entityTypeId) === null) {
            throw new ApiException(404, 'entity_type_not_found', 'EntityType non trovato.');
        }
        $this->repository->setEntityTypeStatus($entityTypeId, $status);
        return $this->repository->findEntityType($entityTypeId) ?? [];
    }

    /** @return array<string, mixed> */
    private function requireObject(string $objectId): array
    {
        $this->assertId($objectId);
        $row = $this->repository->objectDetail($objectId);
        if ($row === null) {
            throw new ApiException(404, 'knowledge_object_not_found', 'KnowledgeObject non trovato.');
        }
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        $isConcept = $row['object_type'] === 'concept';
        $entityType = $isConcept ? null : [
            'id' => $row['entity_type_id'],
            'name' => $row['entity_type_name'],
            'status' => $row['entity_type_status'],
        ];
        return [
            'id' => $row['id'],
            'objectType' => $row['object_type'],
            'name' => $isConcept ? $row['canonical_name'] : $row['entity_name'],
            'description' => $isConcept ? $row['concept_description'] : $row['entity_description'],
            'status' => $isConcept ? $row['concept_status'] : $row['entity_status'],
            'entityType' => $entityType,
            'occurrences' => $this->occurrences((string) $row['id']),
        ];
    }

    /**
     * INV-OCC-04 and INV-OCC-19: the text of every occurrence is read from the Document content of
     * the current revision, never from a copy kept next to the record.
     *
     * @return list<array<string, mixed>>
     */
    private function occurrences(string $objectId): array
    {
        $occurrences = [];
        foreach ($this->repository->objectOccurrences($objectId) as $row) {
            $document = $this->decodeDocument((string) $row['document_json']);
            $occurrences[] = [
                'id' => $row['id'],
                'documentId' => $row['document_id'],
                'documentTitle' => $row['document_title'],
                'status' => $row['status'],
                'text' => $this->occurrenceTextExtractor->extract($document, (string) $row['id']),
            ];
        }
        return $occurrences;
    }

    /** @return array<string, mixed> */
    private function decodeDocument(string $json): array
    {
        try {
            $decoded = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            error_log('Document non decodificabile: ' . $error->getMessage());
            throw new ApiException(500, 'document_unreadable', 'Il contenuto di un Document non è leggibile.');
        }
        if (!is_array($decoded)) {
            throw new ApiException(500, 'document_unreadable', 'Il contenuto di un Document non è leggibile.');
        }
        return $decoded;
    }

    private function assertId(string $id): void
    {
        if (!UuidV7::isValid($id)) {
            throw new ApiException(422, 'invalid_id', 'ID non valido.');
        }
    }
}

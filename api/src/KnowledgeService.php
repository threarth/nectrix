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
    private const MAX_TEXT_LENGTH = 200;
    private const MAX_DESCRIPTION_LENGTH = 4000;

    public function __construct(
        private readonly KnowledgeRepository $repository,
        private readonly OccurrenceTextExtractor $occurrenceTextExtractor,
        private readonly IdentifierNormalizer $identifierNormalizer = new IdentifierNormalizer(),
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function search(string $query): array
    {
        return $this->repository->search(substr(trim($query), 0, self::MAX_QUERY_LENGTH));
    }

    /**
     * Concept, Entity and Context in one answer: marking a fragment is one decision, not three.
     *
     * @return list<array<string, mixed>>
     */
    public function searchIndex(string $query): array
    {
        return $this->repository->searchIndex(substr(trim($query), 0, self::MAX_QUERY_LENGTH));
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

    /**
     * INV-ALS-01 and INV-ALS-03: an alias is added only by an explicit command and touches no
     * occurrence. The same alias may belong to different Concept.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addAlias(string $objectId, array $input): array
    {
        $object = $this->requireObject($objectId);
        if ($object['object_type'] !== 'concept') {
            throw new ApiException(422, 'alias_requires_concept', 'Solo un Concept può avere ConceptAlias.');
        }
        $this->repository->addConceptAlias($objectId, $this->text($input['alias'] ?? null, 'alias'));
        return $this->object($objectId);
    }

    /** @return array<string, mixed> */
    public function removeAlias(string $aliasId): array
    {
        $this->assertId($aliasId);
        $alias = $this->repository->findConceptAlias($aliasId);
        if ($alias === null) {
            throw new ApiException(404, 'alias_not_found', 'ConceptAlias non trovato.');
        }
        $this->repository->removeConceptAlias($aliasId);
        return $this->object((string) $alias['concept_id']);
    }

    /**
     * INV-EID-03, INV-EID-04 and INV-EID-05: an identifier is declared explicitly, its scheme
     * carries a versioned normalisation policy and the authority takes part in the identity when
     * the scheme requires it. The same identity on another Entity is reported as a duplicate
     * candidate, never merged.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addIdentifier(string $objectId, array $input): array
    {
        $object = $this->requireObject($objectId);
        if ($object['object_type'] !== 'entity') {
            throw new ApiException(422, 'identifier_requires_entity', 'Solo una Entity può avere EntityIdentifier.');
        }
        $scheme = Text::lower($this->text($input['scheme'] ?? null, 'scheme'));
        $this->identifierNormalizer->assertScheme($scheme);
        $value = $this->text($input['value'] ?? null, 'value');
        $authority = $this->authority($input['authorityOrNamespace'] ?? null, $scheme);
        $normalized = $this->identifierNormalizer->normalize($scheme, $value);

        $this->repository->addEntityIdentifier(
            $objectId,
            $scheme,
            $value,
            $normalized,
            $authority,
            $this->identifierNormalizer->version($scheme),
        );

        return [
            'object' => $this->object($objectId),
            'duplicateCandidates' => $this->repository->duplicateIdentifierCandidates($scheme, $normalized, $authority, $objectId),
        ];
    }

    /** @return array<string, mixed> */
    public function removeIdentifier(string $identifierId): array
    {
        $this->assertId($identifierId);
        $identifier = $this->repository->findEntityIdentifier($identifierId);
        if ($identifier === null) {
            throw new ApiException(404, 'identifier_not_found', 'EntityIdentifier non trovato.');
        }
        $this->repository->removeEntityIdentifier($identifierId);
        return $this->object((string) $identifier['entity_id']);
    }

    /** INV-EID-05: an absent authority is NULL, never an empty string. */
    private function authority(mixed $value, string $scheme): ?string
    {
        $authority = is_string($value) ? trim($value) : '';
        if ($authority === '') {
            if ($this->identifierNormalizer->requiresAuthority($scheme)) {
                throw new ApiException(422, 'identifier_authority_required', "Lo scheme {$scheme} richiede un'authority o namespace.");
            }
            return null;
        }
        return $authority;
    }

    private function text(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || Text::length(trim($value)) > self::MAX_TEXT_LENGTH) {
            throw new ApiException(422, 'invalid_request', "Il campo {$field} è obbligatorio e non può superare i limiti.");
        }
        return trim($value);
    }

    /**
     * Updates the presentation fields of a Concept or an Entity. Nothing else moves: occurrence,
     * alias, identificatori e stato restano quelli di prima.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateObject(string $objectId, array $input): array
    {
        $object = $this->requireObject($objectId);
        foreach (array_keys($input) as $key) {
            if (!in_array($key, ['name', 'description'], true)) {
                throw new ApiException(422, 'invalid_request', "Campo non supportato: {$key}.");
            }
        }
        $name = $this->text($input['name'] ?? null, 'name');
        $description = $input['description'] ?? null;
        if ($description !== null && (!is_string($description) || Text::length($description) > self::MAX_DESCRIPTION_LENGTH)) {
            throw new ApiException(422, 'invalid_request', 'La descrizione supera il limite consentito.');
        }
        $trimmed = is_string($description) && trim($description) !== '' ? trim($description) : null;

        $this->repository->updateObject($objectId, (string) $object['object_type'], $name, $trimmed);
        return $this->object($objectId);
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
            'aliases' => $isConcept ? $this->repository->conceptAliases((string) $row['id']) : [],
            'identifiers' => $isConcept ? [] : $this->repository->entityIdentifiers((string) $row['id']),
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

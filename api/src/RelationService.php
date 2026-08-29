<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

/**
 * KnowledgeRelation: directed arcs declared by the user between Concept and Entity in any
 * combination. Nothing creates them implicitly: appearing in the same Document, sharing a Context
 * or a Tag, or being referenced by a FieldValue are not relations.
 */
final class RelationService
{
    /** Suggestions only: a custom predicate is equally valid. */
    private const SUGGESTED_TYPES = [
        'è un tipo di', 'fa parte di', 'si oppone a', 'deriva da',
        'riguarda', 'è studiato da', 'usa', 'ha prodotto',
    ];

    private const MAX_TYPE_LENGTH = 100;
    private const MAX_DESCRIPTION_LENGTH = 1000;

    public function __construct(
        private readonly RelationRepository $repository,
        private readonly KnowledgeRepository $knowledge,
    ) {
    }

    /**
     * Arcs of a KnowledgeObject, each declaring its direction relative to it.
     *
     * @return list<array<string, mixed>>
     */
    public function of(string $objectId): array
    {
        $this->requireObject($objectId);
        $relations = [];
        foreach ($this->repository->of($objectId) as $row) {
            $outgoing = $row['source_knowledge_object_id'] === $objectId;
            $relations[] = [
                'id' => $row['id'],
                'relationType' => $row['relation_type'],
                'description' => $row['description'],
                'direction' => $outgoing ? 'outgoing' : 'incoming',
                'otherId' => $outgoing ? $row['target_knowledge_object_id'] : $row['source_knowledge_object_id'],
                'otherType' => $outgoing ? $row['target_object_type'] : $row['source_object_type'],
                'otherName' => $outgoing ? $row['target_name'] : $row['source_name'],
            ];
        }
        return $relations;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_values(array_unique([...self::SUGGESTED_TYPES, ...$this->repository->usedTypes()]));
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    public function create(string $sourceId, array $input): array
    {
        $this->assertOnlyKeys($input, ['targetId', 'relationType', 'description']);
        $source = $this->requireObject($sourceId);
        $targetId = (string) ($input['targetId'] ?? '');
        if ($targetId === $sourceId) {
            throw new ApiException(422, 'relation_self', 'Un oggetto non si collega a sé stesso.');
        }
        $target = $this->requireObject($targetId);
        $relationType = $this->relationType($input['relationType'] ?? null);

        if ($this->repository->exists($sourceId, $targetId, $relationType)) {
            throw new ApiException(422, 'relation_duplicate', 'Questa relazione esiste già in questa direzione.');
        }

        $this->repository->create(
            $sourceId,
            (string) $source['object_type'],
            $targetId,
            (string) $target['object_type'],
            $relationType,
            $this->description($input['description'] ?? null),
        );
        return $this->of($sourceId);
    }

    /** @return list<array<string, mixed>> */
    public function delete(string $relationId, string $fromObjectId): array
    {
        if (!UuidV7::isValid($relationId)) {
            throw new ApiException(422, 'invalid_id', 'ID non valido.');
        }
        if ($this->repository->find($relationId) === null) {
            throw new ApiException(404, 'relation_not_found', 'Relazione non trovata.');
        }
        $this->repository->delete($relationId);
        return $this->of($fromObjectId);
    }

    /** @return array<string, mixed> */
    private function requireObject(string $objectId): array
    {
        if (!UuidV7::isValid($objectId)) {
            throw new ApiException(422, 'invalid_id', 'ID non valido.');
        }
        $object = $this->knowledge->objectDetail($objectId);
        if ($object === null) {
            throw new ApiException(404, 'knowledge_object_not_found', 'KnowledgeObject non trovato.');
        }
        return $object;
    }

    private function relationType(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || Text::length(trim($value)) > self::MAX_TYPE_LENGTH) {
            throw new ApiException(422, 'invalid_request', 'Il predicato della relazione è obbligatorio.');
        }
        return trim($value);
    }

    private function description(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value) || Text::length($value) > self::MAX_DESCRIPTION_LENGTH) {
            throw new ApiException(422, 'invalid_request', 'La descrizione supera il limite consentito.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $input @param list<string> $allowed */
    private function assertOnlyKeys(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new ApiException(422, 'invalid_request', "Campo non supportato: {$key}.");
            }
        }
    }
}

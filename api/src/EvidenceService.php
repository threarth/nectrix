<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

/**
 * Provenance of a KnowledgeRelation or of a derived FieldValue. Evidence points only at data that
 * already exists in the system and is verified before being written; Source and SourceLocator
 * extend these paths from FASE 16 and are not anticipated here.
 */
final class EvidenceService
{
    private const SUBJECTS = ['relation' => 'relation_id', 'field_value' => 'field_value_id'];
    private const MAX_NOTE_LENGTH = 1000;

    public function __construct(
        private readonly EvidenceRepository $repository,
        private readonly RelationRepository $relations,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function of(string $subject, string $subjectId): array
    {
        $this->requireSubject($subject, $subjectId);
        return $this->repository->of(self::SUBJECTS[$subject], $subjectId);
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    public function add(string $subject, string $subjectId, array $input): array
    {
        $this->assertOnlyKeys($input, ['family', 'destinationId', 'note']);
        $this->requireSubject($subject, $subjectId);

        $family = (string) ($input['family'] ?? '');
        if (!isset(EvidenceRepository::FAMILIES[$family])) {
            throw new ApiException(422, 'invalid_request', 'Famiglia di evidence non supportata.', [
                'families' => array_keys(EvidenceRepository::FAMILIES),
            ]);
        }
        $destinationId = (string) ($input['destinationId'] ?? '');
        if (!UuidV7::isValid($destinationId)) {
            throw new ApiException(422, 'invalid_id', 'ID della destinazione non valido.');
        }
        if (!$this->repository->destinationExists($family, $destinationId)) {
            throw new ApiException(422, 'evidence_not_found', 'L’evidence indicata non esiste o non è di quel tipo.');
        }

        $this->repository->add($family, self::SUBJECTS[$subject], $subjectId, $destinationId, $this->note($input['note'] ?? null));
        return $this->of($subject, $subjectId);
    }

    /** @return list<array<string, mixed>> */
    public function remove(string $subject, string $subjectId, string $family, string $evidenceId): array
    {
        $this->requireSubject($subject, $subjectId);
        if (!isset(EvidenceRepository::FAMILIES[$family]) || !UuidV7::isValid($evidenceId)) {
            throw new ApiException(422, 'invalid_request', 'Evidence non valida.');
        }
        if (!$this->repository->exists($family, $evidenceId)) {
            throw new ApiException(404, 'evidence_not_found', 'Evidence non trovata.');
        }
        $this->repository->remove($family, $evidenceId);
        return $this->of($subject, $subjectId);
    }

    private function requireSubject(string $subject, string $subjectId): void
    {
        if (!isset(self::SUBJECTS[$subject]) || !UuidV7::isValid($subjectId)) {
            throw new ApiException(422, 'invalid_request', 'Soggetto della provenance non valido.');
        }
        if ($subject === 'relation' && $this->relations->find($subjectId) === null) {
            throw new ApiException(404, 'relation_not_found', 'Relazione non trovata.');
        }
    }

    private function note(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value) || Text::length($value) > self::MAX_NOTE_LENGTH) {
            throw new ApiException(422, 'invalid_request', 'La nota supera il limite consentito.');
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

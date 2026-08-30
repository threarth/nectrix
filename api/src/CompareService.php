<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

/**
 * Side by side comparison of Concept or of Entity. The two modes stay separate because they
 * compare different things, and everything shown is persisted knowledge: no text is generated.
 */
final class CompareService
{
    private const MIN_SUBJECTS = 2;
    private const MAX_SUBJECTS = 5;

    public function __construct(
        private readonly CompareRepository $repository,
        private readonly KnowledgeService $knowledge,
        private readonly RelationService $relations,
        private readonly TemplateRepository $templates,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function compare(array $input): array
    {
        $ids = $input['objectIds'] ?? null;
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new ApiException(422, 'invalid_request', 'Il confronto richiede un elenco di oggetti.');
        }
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if (count($ids) < self::MIN_SUBJECTS || count($ids) > self::MAX_SUBJECTS) {
            throw new ApiException(422, 'invalid_request', 'Il confronto accetta da due a cinque oggetti.');
        }

        $subjects = [];
        foreach ($ids as $objectId) {
            $subjects[] = $this->knowledge->object($objectId);
        }
        $types = array_unique(array_column($subjects, 'objectType'));
        if (count($types) > 1) {
            throw new ApiException(422, 'compare_mixed_mode', 'Concept ed Entity si confrontano separatamente.', [
                'types' => array_values($types),
            ]);
        }

        $mode = $types[array_key_first($types)] === 'concept' ? 'concepts' : 'entities';
        return [
            'mode' => $mode,
            'subjects' => array_map(
                static fn (array $subject): array => ['id' => $subject['id'], 'name' => $subject['name']],
                $subjects,
            ),
            'rows' => $mode === 'concepts' ? $this->conceptRows($subjects) : $this->entityRows($subjects),
        ];
    }

    /** @param list<array<string, mixed>> $subjects @return list<array<string, mixed>> */
    private function conceptRows(array $subjects): array
    {
        return [
            $this->row('Descrizione', $subjects, fn (array $s): array => [$s['description'] ?? '']),
            $this->row('Stato', $subjects, fn (array $s): array => [$s['status']]),
            $this->row('Alias', $subjects, fn (array $s): array => array_column($s['aliases'], 'alias')),
            ...$this->sharedRows($subjects),
        ];
    }

    /** @param list<array<string, mixed>> $subjects @return list<array<string, mixed>> */
    private function entityRows(array $subjects): array
    {
        $rows = [
            $this->row('EntityType', $subjects, fn (array $s): array => [$s['entityType']['name'] ?? '']),
            $this->row('Descrizione', $subjects, fn (array $s): array => [$s['description'] ?? '']),
            $this->row('Stato', $subjects, fn (array $s): array => [$s['status']]),
            $this->row('Identificatori', $subjects, fn (array $s): array => array_map(
                static fn (array $i): string => trim("{$i['scheme']} {$i['value']} " . ($i['authority_or_namespace'] ?? '')),
                $s['identifiers'],
            )),
            ...$this->sharedRows($subjects),
        ];

        // Solo un Template applicato a tutte allinea le colonne su un TemplateField stabile.
        $entityIds = array_column($subjects, 'id');
        foreach ($this->repository->sharedTemplates($entityIds) as $template) {
            $values = [];
            foreach ($entityIds as $entityId) {
                $values[$entityId] = $this->repository->valuesOf($entityId, (string) $template['id']);
            }
            foreach ($this->templates->fields((string) $template['id']) as $field) {
                $rows[] = [
                    'label' => "{$template['name']} · {$field['name']}",
                    'path' => 'field_value',
                    'cells' => array_map(
                        fn (string $entityId): array => $this->fieldCell($values[$entityId], (string) $field['id']),
                        $entityIds,
                    ),
                ];
            }
        }
        return $rows;
    }

    /** Rows shared by both modes: everything derived through the occurrence. */
    private function sharedRows(array $subjects): array
    {
        return [
            $this->row('Context', $subjects, fn (array $s): array => array_column(
                $this->repository->contextsOf((string) $s['id']),
                'name',
            ), 'derived'),
            $this->row('Occurrence', $subjects, fn (array $s): array => array_map(
                static fn (array $o): string => "{$o['title']} · {$o['status']}",
                $this->repository->occurrencesOf((string) $s['id']),
            ), 'derived'),
            $this->row('Relazioni', $subjects, fn (array $s): array => array_map(
                static fn (array $r): string => ($r['direction'] === 'outgoing' ? '→ ' : '← ') . "{$r['relationType']} {$r['otherName']}",
                $this->relations->of((string) $s['id']),
            )),
        ];
    }

    /**
     * @param list<array<string, mixed>> $subjects
     * @param callable(array<string, mixed>): list<string> $extract
     * @return array<string, mixed>
     */
    private function row(string $label, array $subjects, callable $extract, string $path = 'persisted'): array
    {
        return [
            'label' => $label,
            'path' => $path,
            'cells' => array_map(
                static fn (array $subject): array => array_values(array_filter(
                    array_map('strval', $extract($subject)),
                    static fn (string $value): bool => trim($value) !== '',
                )),
                $subjects,
            ),
        ];
    }

    /** @param list<array<string, mixed>> $values @return list<string> */
    private function fieldCell(array $values, string $fieldId): array
    {
        $cell = [];
        foreach ($values as $value) {
            if ($value['template_field_id'] !== $fieldId) {
                continue;
            }
            $cell[] = match ((string) $value['field_type']) {
                'boolean' => (int) $value['boolean_value'] === 1 ? 'sì' : 'no',
                'number', 'percentage' => (string) $value['number_value'],
                'measurement' => trim("{$value['number_value']} {$value['unit']}"),
                'currency' => trim("{$value['number_value']} {$value['currency_code']}"),
                'date' => (string) $value['date_value'],
                'entity_reference', 'multi_entity_reference' => (string) $value['entity_reference_id'],
                'concept_reference', 'multi_concept_reference' => (string) $value['concept_reference_id'],
                default => (string) $value['text_value'],
            };
        }
        return $cell;
    }
}

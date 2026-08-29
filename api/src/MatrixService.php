<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

/**
 * Matrices KnowledgeObject x Context. The columns are the Context, the rows are the subject of the
 * chosen axis, and every cell declares the path that produced the match: a cell is never a number
 * without provenance. The count is the number of distinct active KnowledgeOccurrence, so the
 * drill-down of a cell returns exactly the rows the cell declares.
 */
final class MatrixService
{
    /** Path declared by each axis when no FieldValue filter is applied. */
    private const PATHS = [
        'concept' => 'occurrence',
        'entity' => 'occurrence',
        'entity_type' => 'occurrence_entity_type',
        'template' => 'semantic_block',
    ];

    private const FILTERED_PATH = 'field_value';

    private const MODES = ['exact', 'subtree'];

    /** Rows returned at most: a matrix has to stay readable and comparable with the queries. */
    private const ROW_LIMIT = 100;

    public function __construct(
        private readonly MatrixRepository $repository,
        private readonly ContextRepository $contexts,
        private readonly FieldFilterCompiler $compiler,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function matrix(array $input): array
    {
        $axis = $this->axis($input);
        $mode = $this->mode($input);
        [$filter, $parameters, $field] = $this->filter($axis, $input);
        $path = $filter === null ? self::PATHS[$axis] : self::FILTERED_PATH;

        $contexts = $this->contexts->list();
        $rows = $this->aggregate($this->repository->counts($axis, $filter, $parameters), $contexts, $mode, $path);
        $total = count($rows);

        return [
            'axis' => $axis,
            'mode' => $mode,
            'path' => $path,
            'field' => $field,
            'columns' => $this->columns($contexts),
            'rows' => array_slice($rows, 0, self::ROW_LIMIT),
            'truncated' => $total > self::ROW_LIMIT,
            'counts' => ['rows' => $total, 'contexts' => count($contexts)],
        ];
    }

    /**
     * Occurrence, Document and co-occurring KnowledgeObject behind one cell. Source will extend this
     * drill-down in the FASE 16: it is not simulated here.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function cell(array $input): array
    {
        $axis = $this->axis($input);
        $mode = $this->mode($input);
        [$filter, $parameters, $field] = $this->filter($axis, $input);
        $rowId = $input['rowId'] ?? null;
        if (!is_string($rowId) || !UuidV7::isValid($rowId)) {
            throw new ApiException(422, 'invalid_id', 'Il drill-down richiede la riga della matrice.');
        }

        $contextIds = $this->cellContexts($input, $mode);
        $occurrences = $this->repository->cell($axis, $rowId, $contextIds, $filter, $parameters);
        $coObjects = $this->coObjects($occurrences, $rowId);

        return [
            'axis' => $axis,
            'mode' => $mode,
            'path' => $filter === null ? self::PATHS[$axis] : self::FILTERED_PATH,
            'field' => $field,
            'rowId' => $rowId,
            'contextId' => $input['contextId'] ?? null,
            'occurrences' => $this->describe($occurrences, $coObjects),
            'counts' => ['occurrences' => count($occurrences)],
        ];
    }

    /**
     * Leaf counts folded onto the columns. Each occurrence belongs to exactly one Document, so a
     * Context of the subtree adds its own leaf count to every ancestor without double counting.
     *
     * @param list<array<string, mixed>> $counts
     * @param list<array<string, mixed>> $contexts
     * @return list<array<string, mixed>>
     */
    private function aggregate(array $counts, array $contexts, string $mode, string $path): array
    {
        $parents = [];
        foreach ($contexts as $context) {
            $parents[(string) $context['id']] = $context['parent_id'] === null ? null : (string) $context['parent_id'];
        }

        $labels = [];
        $cells = [];
        $totals = [];
        foreach ($counts as $count) {
            $rowId = (string) $count['row_id'];
            $labels[$rowId] = (string) $count['row_label'];
            // The total counts each occurrence once: the leaf, not the columns it also feeds.
            $totals[$rowId] = ($totals[$rowId] ?? 0) + (int) $count['matches'];
            foreach ($this->columnsOf($count['context_id'], $parents, $mode) as $columnKey) {
                $cells[$rowId][$columnKey] = ($cells[$rowId][$columnKey] ?? 0) + (int) $count['matches'];
            }
        }

        $rows = [];
        foreach ($labels as $rowId => $label) {
            $rows[] = $this->row($rowId, $label, $cells[$rowId] ?? [], $path, $totals[$rowId] ?? 0);
        }
        usort($rows, static fn (array $first, array $second): int
            => [$second['total'], $first['label']] <=> [$first['total'], $second['label']]);
        return $rows;
    }

    /**
     * Columns one leaf Context contributes to: itself in `exact`, itself and its ancestors in
     * `subtree`. The Document without a Context contributes to its own column only.
     *
     * @param array<string, string|null> $parents
     * @return list<string>
     */
    private function columnsOf(mixed $contextId, array $parents, string $mode): array
    {
        if ($contextId === null) {
            return [''];
        }
        $columns = [(string) $contextId];
        if ($mode === 'exact') {
            return $columns;
        }
        $current = $parents[(string) $contextId] ?? null;
        while ($current !== null && !in_array($current, $columns, true)) {
            $columns[] = $current;
            $current = $parents[$current] ?? null;
        }
        return $columns;
    }

    /**
     * @param array<string, int> $cells
     * @param int $total distinct occurrence of the row, counted on the leaf Context only
     * @return array<string, mixed>
     */
    private function row(string $rowId, string $label, array $cells, string $path, int $total): array
    {
        $list = [];
        foreach ($cells as $columnKey => $matches) {
            $list[] = [
                'contextId' => $columnKey === '' ? null : $columnKey,
                'matches' => $matches,
                'path' => $path,
            ];
        }
        return ['id' => $rowId, 'label' => $label, 'cells' => $list, 'total' => $total];
    }

    /**
     * @param list<array<string, mixed>> $contexts
     * @return list<array<string, mixed>>
     */
    private function columns(array $contexts): array
    {
        $columns = [['id' => null, 'parentId' => null, 'name' => null]];
        foreach ($contexts as $context) {
            $columns[] = [
                'id' => (string) $context['id'],
                'parentId' => $context['parent_id'] === null ? null : (string) $context['parent_id'],
                'name' => (string) $context['name'],
            ];
        }
        return $columns;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<string>|null null selects the Document without a Context
     */
    private function cellContexts(array $input, string $mode): ?array
    {
        $contextId = $input['contextId'] ?? null;
        if ($contextId === null || $contextId === '') {
            return null;
        }
        if (!is_string($contextId) || !UuidV7::isValid($contextId)) {
            throw new ApiException(422, 'invalid_id', 'Il Context della cella non è valido.');
        }
        if ($this->contexts->find($contextId) === null) {
            throw new ApiException(404, 'context_not_found', 'Context non trovato.');
        }
        return $mode === 'subtree' ? $this->contexts->subtreeIds($contextId) : [$contextId];
    }

    /**
     * @param list<array<string, mixed>> $occurrences
     * @return array<string, list<array<string, mixed>>>
     */
    private function coObjects(array $occurrences, string $rowId): array
    {
        $documentIds = array_values(array_unique(array_column($occurrences, 'document_id')));
        $subjects = array_column($occurrences, 'knowledge_object_id');
        $byDocument = [];
        foreach ($this->repository->coObjects($documentIds) as $row) {
            $id = (string) $row['id'];
            if ($id === $rowId || in_array($id, $subjects, true)) {
                continue;
            }
            $byDocument[(string) $row['document_id']][] = [
                'id' => $id,
                'objectType' => (string) $row['object_type'],
                'label' => (string) $row['label'],
            ];
        }
        return $byDocument;
    }

    /**
     * @param list<array<string, mixed>> $occurrences
     * @param array<string, list<array<string, mixed>>> $coObjects
     * @return list<array<string, mixed>>
     */
    private function describe(array $occurrences, array $coObjects): array
    {
        $described = [];
        foreach ($occurrences as $occurrence) {
            $documentId = (string) $occurrence['document_id'];
            $described[] = [
                'occurrenceId' => (string) $occurrence['occurrence_id'],
                'status' => (string) $occurrence['status'],
                'objectId' => (string) $occurrence['knowledge_object_id'],
                'objectType' => (string) $occurrence['object_type'],
                'documentId' => $documentId,
                'documentTitle' => (string) $occurrence['title'],
                'contextId' => $occurrence['context_id'] === null ? null : (string) $occurrence['context_id'],
                'coObjects' => $coObjects[$documentId] ?? [],
            ];
        }
        return $described;
    }

    /** @param array<string, mixed> $input */
    private function axis(array $input): string
    {
        $axis = (string) ($input['axis'] ?? '');
        if (!MatrixRepository::knowsAxis($axis)) {
            throw new ApiException(422, 'invalid_matrix_axis', 'Asse della matrice non supportato.', [
                'allowed' => array_keys(self::PATHS),
            ]);
        }
        return $axis;
    }

    /** @param array<string, mixed> $input */
    private function mode(array $input): string
    {
        $mode = (string) ($input['mode'] ?? 'subtree');
        if (!in_array($mode, self::MODES, true)) {
            throw new ApiException(422, 'invalid_context_mode', 'Modalità del Context non supportata.', [
                'allowed' => self::MODES,
            ]);
        }
        return $mode;
    }

    /**
     * The FieldValue filter. It only applies where the axis reaches an Entity: a Concept has no
     * SemanticBlock, so asking for one is refused instead of being silently ignored.
     *
     * @param array<string, mixed> $input
     * @return array{0: string|null, 1: array<string, mixed>, 2: array<string, mixed>|null}
     */
    private function filter(string $axis, array $input): array
    {
        $filter = $input['fieldFilter'] ?? null;
        if ($filter === null) {
            return [null, [], null];
        }
        if ($axis === 'concept') {
            throw new ApiException(422, 'matrix_filter_not_applicable', 'Un Concept non ha FieldValue da filtrare.', [
                'axis' => $axis,
            ]);
        }
        [$field, $condition, $parameters] = $this->compiler->compile($filter);
        return [
            $condition,
            [...$parameters, 'field_id' => (string) $field['id']],
            [
                'id' => (string) $field['id'],
                'name' => (string) $field['name'],
                'fieldType' => (string) $field['field_type'],
                'template' => $field['template_name'] ?? null,
            ],
        ];
    }
}

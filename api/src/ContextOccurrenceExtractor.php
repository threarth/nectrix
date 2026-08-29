<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

/**
 * Context marks of a document and the containment they produce.
 *
 * A ContextOccurrence is a contiguous range that may cross several textblocks: the Context is what
 * gives meaning to a fragment, and a thought rarely stops at the end of a paragraph. Contiguity is
 * still verified, so a single identity can never cover disjoint pieces of the document.
 *
 * Containment is total: a Concept or Entity belongs to a Context only when the whole fragment is
 * covered. A partial overlap declares nothing, because it would not be a fact about that fragment.
 */
final class ContextOccurrenceExtractor
{
    private const MARK = 'contextOccurrence';

    private const ATTRIBUTES = ['occurrenceId', 'contextId'];

    /**
     * @param array<string, mixed> $document
     * @return array{
     *     marks: array<string, array<string, string>>,
     *     memberships: list<array{contextOccurrenceId: string, knowledgeOccurrenceId: string}>
     * }
     */
    public function extract(array $document): array
    {
        $blocks = [];
        $this->collectTextblocks($document, $blocks);

        $marks = [];
        $runs = [];
        $covered = [];
        $sizes = [];
        foreach ($blocks as $index => $children) {
            $this->scanTextblock($children, $index, $marks, $runs, $covered, $sizes);
        }
        $this->assertContiguous($runs);

        return ['marks' => $marks, 'memberships' => $this->memberships($covered, $sizes)];
    }

    /**
     * Textblocks in document order: every node whose direct children are text nodes.
     *
     * @param array<string, mixed> $node
     * @param list<array<int, mixed>> $blocks
     */
    private function collectTextblocks(array $node, array &$blocks): void
    {
        $content = $node['content'] ?? [];
        if (!is_array($content)) {
            return;
        }
        foreach ($content as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'text') {
                $blocks[] = $content;
                return;
            }
        }
        foreach ($content as $child) {
            if (is_array($child)) {
                $this->collectTextblocks($child, $blocks);
            }
        }
    }

    /**
     * Reads one textblock: the runs of each Context inside it, and how much of each knowledge
     * fragment those runs cover.
     *
     * @param array<int, mixed> $children
     * @param array<string, array<string, string>> $marks
     * @param array<string, list<array{block: int, fromStart: bool, toEnd: bool}>> $runs
     * @param array<string, array<string, int>> $covered
     * @param array<string, int> $sizes
     */
    private function scanTextblock(array $children, int $index, array &$marks, array &$runs, array &$covered, array &$sizes): void
    {
        $texts = array_values(array_filter(
            $children,
            static fn (mixed $child): bool => is_array($child) && ($child['type'] ?? null) === 'text',
        ));
        $last = count($texts) - 1;
        $open = null;
        foreach ($texts as $position => $child) {
            $attributes = $this->contextAttributes($child);
            $id = $attributes['occurrenceId'] ?? null;
            if ($id !== $open) {
                if ($id !== null) {
                    $runs[$id][] = ['block' => $index, 'fromStart' => $position === 0, 'toEnd' => false];
                }
                $open = $id;
            }
            if ($id !== null) {
                $marks = $this->remember($marks, $attributes);
                $runs[$id][count($runs[$id]) - 1]['toEnd'] = $position === $last;
            }
            $this->countCoverage($child, $id, $covered, $sizes);
        }
    }

    /**
     * Counts the text nodes of each knowledge fragment and how many of them a Context covers.
     *
     * @param array<string, mixed> $child
     * @param array<string, array<string, int>> $covered
     * @param array<string, int> $sizes
     */
    private function countCoverage(array $child, ?string $contextOccurrenceId, array &$covered, array &$sizes): void
    {
        $knowledgeId = null;
        foreach ($child['marks'] ?? [] as $mark) {
            if (is_array($mark) && ($mark['type'] ?? null) === 'knowledgeOccurrence') {
                $candidate = $mark['attrs']['occurrenceId'] ?? null;
                $knowledgeId = is_string($candidate) ? $candidate : null;
            }
        }
        if ($knowledgeId === null) {
            return;
        }
        $sizes[$knowledgeId] = ($sizes[$knowledgeId] ?? 0) + 1;
        if ($contextOccurrenceId !== null) {
            $covered[$knowledgeId][$contextOccurrenceId] = ($covered[$knowledgeId][$contextOccurrenceId] ?? 0) + 1;
        }
    }

    /**
     * INV-CTX-02: one identity, one contiguous range. The range may cross textblocks, but only
     * consecutive ones, entering each new block at its start and leaving the previous at its end.
     *
     * @param array<string, list<array{block: int, fromStart: bool, toEnd: bool}>> $runs
     */
    private function assertContiguous(array $runs): void
    {
        foreach ($runs as $occurrenceId => $sequence) {
            $previous = null;
            foreach ($sequence as $run) {
                if ($previous === null) {
                    $previous = $run;
                    continue;
                }
                if ($run['block'] !== $previous['block'] + 1 || !$previous['toEnd'] || !$run['fromStart']) {
                    throw new ApiException(
                        422,
                        'context_occurrence_split',
                        'Lo stesso Context copre intervalli disgiunti del documento.',
                        ['occurrenceId' => $occurrenceId],
                    );
                }
                $previous = $run;
            }
        }
    }

    /**
     * @param array<string, array<string, int>> $covered
     * @param array<string, int> $sizes
     * @return list<array{contextOccurrenceId: string, knowledgeOccurrenceId: string}>
     */
    private function memberships(array $covered, array $sizes): array
    {
        $memberships = [];
        foreach ($covered as $knowledgeId => $contexts) {
            foreach ($contexts as $contextOccurrenceId => $count) {
                if ($count !== ($sizes[$knowledgeId] ?? 0)) {
                    continue;
                }
                $memberships[] = [
                    'contextOccurrenceId' => (string) $contextOccurrenceId,
                    'knowledgeOccurrenceId' => (string) $knowledgeId,
                ];
            }
        }
        return $memberships;
    }

    /**
     * @param array<string, array<string, string>> $marks
     * @param array<string, string> $attributes
     * @return array<string, array<string, string>>
     */
    private function remember(array $marks, array $attributes): array
    {
        $id = $attributes['occurrenceId'];
        if (isset($marks[$id]) && $marks[$id] !== $attributes) {
            throw new ApiException(422, 'context_occurrence_duplicate', 'ID di ContextOccurrence duplicato nel documento.');
        }
        $marks[$id] = $attributes;
        return $marks;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, string>|null
     */
    private function contextAttributes(array $node): ?array
    {
        foreach ($node['marks'] ?? [] as $mark) {
            if (!is_array($mark) || ($mark['type'] ?? null) !== self::MARK) {
                continue;
            }
            $attributes = $mark['attrs'] ?? null;
            $canonical = [];
            foreach (self::ATTRIBUTES as $key) {
                if (!is_array($attributes) || !isset($attributes[$key]) || !is_string($attributes[$key])) {
                    throw new ApiException(422, 'context_occurrence_invalid_attributes', 'Il mark contextOccurrence richiede attributi completi.');
                }
                $canonical[$key] = $attributes[$key];
            }
            return $canonical;
        }
        return null;
    }
}

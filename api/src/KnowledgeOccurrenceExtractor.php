<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

final class KnowledgeOccurrenceExtractor
{
    private const OCCURRENCE_ATTRIBUTES = ['occurrenceId', 'knowledgeObjectId', 'objectType'];

    /**
     * Occurrence marks of a document, keyed by occurrenceId.
     *
     * @param array<string, mixed> $document
     * @return array<string, array<string, string>>
     */
    public function extract(array $document): array
    {
        $found = [];
        $closed = [];
        $this->visitInlineContainers($document, $found, $closed);
        return $found;
    }

    /**
     * Visits every inline container, that is every node whose direct children are text nodes.
     *
     * @param array<string, mixed> $node
     * @param array<string, array<string, string>> $found
     * @param array<string, bool> $closed
     */
    private function visitInlineContainers(array $node, array &$found, array &$closed): void
    {
        $content = $node['content'] ?? [];
        if (!is_array($content)) {
            return;
        }
        foreach ($content as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'text') {
                $this->scanInlineContainer($content, $found, $closed);
                return;
            }
        }
        foreach ($content as $child) {
            if (is_array($child)) {
                $this->visitInlineContainers($child, $found, $closed);
            }
        }
    }

    /**
     * INV-OCC-05: an occurrenceId covers one run of adjacent text nodes inside a single textblock.
     * Reopening a run that was already closed means disjoint intervals or several textblocks.
     *
     * @param array<int, mixed> $children
     * @param array<string, array<string, string>> $found
     * @param array<string, bool> $closed
     */
    private function scanInlineContainer(array $children, array &$found, array &$closed): void
    {
        $openId = null;
        foreach ($children as $child) {
            $attributes = is_array($child) ? $this->occurrenceAttributes($child) : null;
            $id = $attributes['occurrenceId'] ?? null;
            if ($id !== $openId) {
                if ($openId !== null) {
                    $closed[$openId] = true;
                }
                $openId = $id;
            }
            if ($attributes === null) {
                continue;
            }
            if (isset($closed[$id])) {
                throw new ApiException(422, 'occurrence_split', 'La stessa occurrence copre intervalli disgiunti o più textblock.');
            }
            if (isset($found[$id]) && $found[$id] !== $attributes) {
                throw new ApiException(422, 'occurrence_duplicate', 'Occurrence ID duplicato nel documento.');
            }
            $found[$id] = $attributes;
        }
        if ($openId !== null) {
            $closed[$openId] = true;
        }
    }

    /**
     * Canonically ordered occurrence attributes of a text node, or null when it carries no mark.
     *
     * @param array<string, mixed> $node
     * @return array<string, string>|null
     */
    private function occurrenceAttributes(array $node): ?array
    {
        foreach ($node['marks'] ?? [] as $mark) {
            if (!is_array($mark) || ($mark['type'] ?? null) !== 'knowledgeOccurrence') {
                continue;
            }
            $attributes = $mark['attrs'] ?? null;
            $canonical = [];
            foreach (self::OCCURRENCE_ATTRIBUTES as $key) {
                if (!is_array($attributes) || !isset($attributes[$key]) || !is_string($attributes[$key])) {
                    throw new ApiException(422, 'occurrence_invalid_attributes', 'Il mark knowledgeOccurrence richiede attributi completi.');
                }
                $canonical[$key] = $attributes[$key];
            }
            return $canonical;
        }
        return null;
    }
}

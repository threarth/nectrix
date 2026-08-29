<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

/**
 * Removes from a document the traces of something that is being deleted: occurrence marks, Context
 * marks and editorial references. The text itself is never touched — the words of the user stay,
 * only the index over them disappears — because `document_json` is the authority and a deletion
 * that left a dangling mark there would be a lie told to the next save.
 */
final class DocumentPruner
{
    /**
     * @param array<string, mixed> $document
     * @param list<string> $occurrenceIds knowledgeOccurrence marks to remove
     * @param list<string> $contextOccurrenceIds contextOccurrence marks to remove
     * @param list<string> $referenceDestinations reference nodes pointing here are removed whole
     * @return array{0: array<string, mixed>, 1: bool} the pruned document and whether it changed
     */
    public function prune(array $document, array $occurrenceIds, array $contextOccurrenceIds, array $referenceDestinations): array
    {
        $changed = false;
        $pruned = $this->node(
            $document,
            array_flip($occurrenceIds),
            array_flip($contextOccurrenceIds),
            array_flip($referenceDestinations),
            $changed,
        );
        return [$pruned, $changed];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, int> $occurrences
     * @param array<string, int> $contexts
     * @param array<string, int> $destinations
     * @return array<string, mixed>
     */
    private function node(array $node, array $occurrences, array $contexts, array $destinations, bool &$changed): array
    {
        if (isset($node['marks']) && is_array($node['marks'])) {
            $kept = $this->keepMarks($node['marks'], $occurrences, $contexts, $changed);
            if ($kept === []) {
                unset($node['marks']);
            } else {
                $node['marks'] = $kept;
            }
        }

        $content = $node['content'] ?? null;
        if (!is_array($content)) {
            return $node;
        }
        $children = [];
        foreach ($content as $child) {
            if (!is_array($child)) {
                $children[] = $child;
                continue;
            }
            if ($this->isDeletedReference($child, $destinations)) {
                $changed = true;
                continue;
            }
            $children[] = $this->node($child, $occurrences, $contexts, $destinations, $changed);
        }
        $node['content'] = $children;
        return $node;
    }

    /**
     * @param array<int, mixed> $marks
     * @param array<string, int> $occurrences
     * @param array<string, int> $contexts
     * @return list<mixed>
     */
    private function keepMarks(array $marks, array $occurrences, array $contexts, bool &$changed): array
    {
        $kept = [];
        foreach ($marks as $mark) {
            if (!is_array($mark)) {
                $kept[] = $mark;
                continue;
            }
            $type = $mark['type'] ?? null;
            $id = $mark['attrs']['occurrenceId'] ?? null;
            $removed = is_string($id) && (
                ($type === 'knowledgeOccurrence' && isset($occurrences[$id]))
                || ($type === 'contextOccurrence' && isset($contexts[$id]))
            );
            if ($removed) {
                $changed = true;
                continue;
            }
            $kept[] = $mark;
        }
        return $kept;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, int> $destinations
     */
    private function isDeletedReference(array $node, array $destinations): bool
    {
        $type = $node['type'] ?? null;
        if (!is_string($type) || !isset(ReferenceExtractor::KINDS[$type])) {
            return false;
        }
        $destination = $node['attrs'][ReferenceExtractor::KINDS[$type]] ?? null;
        return is_string($destination) && isset($destinations[$destination]);
    }
}

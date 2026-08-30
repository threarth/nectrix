<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

/**
 * Reads the current text of an occurrence from the authoritative Document content.
 * Nothing is cached: INV-OCC-04 and INV-OCC-19 forbid an authoritative copy in the database.
 */
final class OccurrenceTextExtractor
{
    /** @param array<string, mixed> $document */
    public function extract(array $document, string $occurrenceId): string
    {
        $text = '';
        $this->collect($document, $occurrenceId, $text);
        return $text;
    }

    /** @param array<string, mixed> $node */
    private function collect(array $node, string $occurrenceId, string &$text): void
    {
        if (($node['type'] ?? null) === 'text' && $this->carries($node, $occurrenceId)) {
            $text .= is_string($node['text'] ?? null) ? $node['text'] : '';
            return;
        }
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $this->collect($child, $occurrenceId, $text);
            }
        }
    }

    /** @param array<string, mixed> $node */
    private function carries(array $node, string $occurrenceId): bool
    {
        foreach ($node['marks'] ?? [] as $mark) {
            if (!is_array($mark) || ($mark['type'] ?? null) !== 'knowledgeOccurrence') {
                continue;
            }
            if (($mark['attrs']['occurrenceId'] ?? null) === $occurrenceId) {
                return true;
            }
        }
        return false;
    }
}

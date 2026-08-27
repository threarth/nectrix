<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

final class KnowledgeOccurrenceExtractor
{
    /** @param array<string, mixed> $document @return array<string, array<string, string>> */
    public function extract(array $document): array
    {
        $found = [];
        $walk = function (array $node) use (&$walk, &$found): void {
            if (($node['type'] ?? null) === 'text') {
                foreach ($node['marks'] ?? [] as $mark) {
                    if (($mark['type'] ?? null) !== 'knowledgeOccurrence') continue;
                    $attrs = $mark['attrs'];
                    $id = $attrs['occurrenceId'];
                    if (isset($found[$id]) && $found[$id] !== $attrs) {
                        throw new ApiException(422, 'occurrence_duplicate', 'Occurrence ID duplicato nel documento.');
                    }
                    $found[$id] = $attrs;
                }
            }
            foreach ($node['content'] ?? [] as $child) $walk($child);
        };
        $walk($document);
        return $found;
    }
}

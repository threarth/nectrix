<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

final class PlainTextExtractor
{
    /** @param array<string, mixed> $document */
    public function extract(array $document): string
    {
        $lines = [];
        foreach ($document['content'] as $node) {
            $this->collectBlocks($node, $lines);
        }
        return rtrim(implode("\n", $lines), "\n");
    }

    /** @param array<string, mixed> $node @param list<string> $lines */
    private function collectBlocks(array $node, array &$lines): void
    {
        if ($node['type'] === 'paragraph' || $node['type'] === 'heading') {
            $text = '';
            foreach ($node['content'] ?? [] as $child) {
                $text .= $child['text'];
            }
            $lines[] = $text;
            return;
        }

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $this->collectBlocks($child, $lines);
            }
        }
    }
}

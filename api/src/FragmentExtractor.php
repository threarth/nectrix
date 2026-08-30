<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

/**
 * Reads the marked fragments of a Document together with the words around them.
 *
 * A fragment without its surroundings is unreadable: «Sé» tells nothing, «il Sé in Jung non
 * coincide con l'Io» tells everything. Nothing is cached — the text is read from the authoritative
 * content every time, as INV-OCC-04 requires.
 */
final class FragmentExtractor
{
    /** Longest marked text shown before it is cut. */
    private const MAX_FRAGMENT = 240;

    /** Words kept on each side of the fragment, in characters. */
    private const AROUND = 70;

    private const ELLIPSIS = '…';

    /**
     * @param array<string, mixed> $document
     * @param string $markType `knowledgeOccurrence` or `contextOccurrence`
     * @return array<string, array{text: string, before: string, after: string}> by occurrenceId
     */
    public function extract(array $document, string $markType): array
    {
        $blocks = [];
        $this->collectTextblocks($document, $blocks);

        $fragments = [];
        foreach ($blocks as $children) {
            $this->scanTextblock($children, $markType, $fragments);
        }
        return array_map($this->trim(...), $fragments);
    }

    /**
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
     * Reads one textblock: the plain text of the block, and where each marked run sits inside it.
     *
     * @param array<int, mixed> $children
     * @param array<string, array{text: string, before: string, after: string}> $fragments
     */
    private function scanTextblock(array $children, string $markType, array &$fragments): void
    {
        $plain = '';
        $spans = [];
        foreach ($children as $child) {
            if (!is_array($child) || ($child['type'] ?? null) !== 'text') {
                continue;
            }
            $piece = is_string($child['text'] ?? null) ? $child['text'] : '';
            $id = $this->occurrenceId($child, $markType);
            if ($id !== null) {
                $spans[$id][] = [strlen($plain), strlen($piece)];
            }
            $plain .= $piece;
        }

        foreach ($spans as $id => $pieces) {
            $start = $pieces[0][0];
            $end = $pieces[count($pieces) - 1][0] + $pieces[count($pieces) - 1][1];
            $found = [
                'text' => substr($plain, $start, $end - $start),
                'before' => substr($plain, max(0, $start - self::AROUND), min($start, self::AROUND)),
                'after' => substr($plain, $end, self::AROUND),
            ];
            // Un range che attraversa piu blocchi si legge di seguito: il testo si somma, il
            // contorno resta quello del primo e dell'ultimo blocco toccati.
            $fragments[(string) $id] = isset($fragments[(string) $id])
                ? [
                    'text' => $fragments[(string) $id]['text'] . ' ' . $found['text'],
                    'before' => $fragments[(string) $id]['before'],
                    'after' => $found['after'],
                ]
                : $found;
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function occurrenceId(array $node, string $markType): ?string
    {
        foreach ($node['marks'] ?? [] as $mark) {
            if (!is_array($mark) || ($mark['type'] ?? null) !== $markType) {
                continue;
            }
            $id = $mark['attrs']['occurrenceId'] ?? null;
            return is_string($id) ? $id : null;
        }
        return null;
    }

    /**
     * @param array{text: string, before: string, after: string} $fragment
     * @return array{text: string, before: string, after: string}
     */
    private function trim(array $fragment): array
    {
        $text = $fragment['text'];
        if (Text::length($text) > self::MAX_FRAGMENT) {
            $text = substr($text, 0, self::MAX_FRAGMENT) . self::ELLIPSIS;
        }
        return ['text' => $text, 'before' => $fragment['before'], 'after' => $fragment['after']];
    }
}

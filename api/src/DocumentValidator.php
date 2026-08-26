<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

final class DocumentValidator
{
    private const MAX_DEPTH = 32;
    private const MARKS = ['bold', 'italic', 'underline'];
    private const BLOCKS = ['paragraph', 'heading', 'blockquote', 'bulletList', 'orderedList'];

    /** @param array<string, mixed> $document */
    public function validate(array $document): void
    {
        $this->assertKeys($document, ['type', 'content'], ['type', 'content'], '$');
        $this->assertSame('doc', $document['type'], '$.type');
        $content = $this->assertList($document['content'], '$.content', false);

        foreach ($content as $index => $node) {
            $this->validateBlock($this->assertObject($node, "$.content[{$index}]"), "$.content[{$index}]", 1);
        }
    }

    /** @param array<string, mixed> $node */
    private function validateBlock(array $node, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->invalid($path, 'Profondità massima del documento superata.');
        }
        $type = $node['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::BLOCKS, true)) {
            $this->invalid($path . '.type', 'Nodo block non supportato.');
        }

        match ($type) {
            'paragraph' => $this->validateTextBlock($node, $path, false),
            'heading' => $this->validateTextBlock($node, $path, true),
            'blockquote' => $this->validateBlockquote($node, $path, $depth),
            'bulletList' => $this->validateList($node, $path, $depth, false),
            'orderedList' => $this->validateList($node, $path, $depth, true),
        };
    }

    /** @param array<string, mixed> $node */
    private function validateTextBlock(array $node, string $path, bool $heading): void
    {
        $allowed = $heading ? ['type', 'attrs', 'content'] : ['type', 'content'];
        $required = $heading ? ['type', 'attrs'] : ['type'];
        $this->assertKeys($node, $allowed, $required, $path);

        if ($heading) {
            $attrs = $this->assertObject($node['attrs'], $path . '.attrs');
            $this->assertKeys($attrs, ['level'], ['level'], $path . '.attrs');
            if (!is_int($attrs['level']) || $attrs['level'] < 1 || $attrs['level'] > 6) {
                $this->invalid($path . '.attrs.level', 'Il livello heading deve essere compreso tra 1 e 6.');
            }
        }

        if (!array_key_exists('content', $node)) {
            return;
        }
        $content = $this->assertList($node['content'], $path . '.content', true);
        foreach ($content as $index => $child) {
            $this->validateText($this->assertObject($child, "{$path}.content[{$index}]"), "{$path}.content[{$index}]");
        }
    }

    /** @param array<string, mixed> $node */
    private function validateText(array $node, string $path): void
    {
        $this->assertKeys($node, ['type', 'text', 'marks'], ['type', 'text'], $path);
        $this->assertSame('text', $node['type'], $path . '.type');
        if (!is_string($node['text']) || $node['text'] === '') {
            $this->invalid($path . '.text', 'Un nodo text deve contenere una stringa non vuota.');
        }
        if (!array_key_exists('marks', $node)) {
            return;
        }

        $marks = $this->assertList($node['marks'], $path . '.marks', true);
        $seen = [];
        foreach ($marks as $index => $markValue) {
            $markPath = "{$path}.marks[{$index}]";
            $mark = $this->assertObject($markValue, $markPath);
            $this->assertKeys($mark, ['type'], ['type'], $markPath);
            $type = $mark['type'];
            if (!is_string($type) || !in_array($type, self::MARKS, true)) {
                $this->invalid($markPath . '.type', 'Mark non supportato.');
            }
            if (isset($seen[$type])) {
                $this->invalid($markPath, 'Mark duplicato sullo stesso nodo text.');
            }
            $seen[$type] = true;
        }
    }

    /** @param array<string, mixed> $node */
    private function validateBlockquote(array $node, string $path, int $depth): void
    {
        $this->assertKeys($node, ['type', 'content'], ['type', 'content'], $path);
        $content = $this->assertList($node['content'], $path . '.content', false);
        foreach ($content as $index => $child) {
            $this->validateBlock($this->assertObject($child, "{$path}.content[{$index}]"), "{$path}.content[{$index}]", $depth + 1);
        }
    }

    /** @param array<string, mixed> $node */
    private function validateList(array $node, string $path, int $depth, bool $ordered): void
    {
        $allowed = $ordered ? ['type', 'attrs', 'content'] : ['type', 'content'];
        $required = $ordered ? ['type', 'attrs', 'content'] : ['type', 'content'];
        $this->assertKeys($node, $allowed, $required, $path);

        if ($ordered) {
            $attrs = $this->assertObject($node['attrs'], $path . '.attrs');
            $this->assertKeys($attrs, ['start', 'type'], ['start', 'type'], $path . '.attrs');
            if (!is_int($attrs['start']) || $attrs['start'] < 1 || $attrs['type'] !== null) {
                $this->invalid($path . '.attrs', 'La lista ordinata richiede start positivo e type null.');
            }
        }

        $items = $this->assertList($node['content'], $path . '.content', false);
        foreach ($items as $index => $itemValue) {
            $itemPath = "{$path}.content[{$index}]";
            $item = $this->assertObject($itemValue, $itemPath);
            $this->assertKeys($item, ['type', 'content'], ['type', 'content'], $itemPath);
            $this->assertSame('listItem', $item['type'], $itemPath . '.type');
            $children = $this->assertList($item['content'], $itemPath . '.content', false);
            foreach ($children as $childIndex => $childValue) {
                $childPath = "{$itemPath}.content[{$childIndex}]";
                $child = $this->assertObject($childValue, $childPath);
                if ($childIndex === 0 && ($child['type'] ?? null) !== 'paragraph') {
                    $this->invalid($childPath . '.type', 'Il primo nodo di un listItem deve essere paragraph.');
                }
                $this->validateBlock($child, $childPath, $depth + 1);
            }
        }
    }

    /** @param array<string, mixed> $value @param list<string> $allowed @param list<string> $required */
    private function assertKeys(array $value, array $allowed, array $required, string $path): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                $this->invalid($path, "Campo obbligatorio mancante: {$key}.");
            }
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                $this->invalid($path . '.' . $key, 'Campo non supportato.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function assertObject(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid($path, 'È atteso un oggetto JSON.');
        }
        return $value;
    }

    /** @return list<mixed> */
    private function assertList(mixed $value, string $path, bool $emptyAllowed): array
    {
        if (!is_array($value) || !array_is_list($value) || (!$emptyAllowed && $value === [])) {
            $this->invalid($path, $emptyAllowed ? 'È atteso un array JSON.' : 'È atteso un array JSON non vuoto.');
        }
        return $value;
    }

    private function assertSame(string $expected, mixed $actual, string $path): void
    {
        if ($actual !== $expected) {
            $this->invalid($path, "Valore atteso: {$expected}.");
        }
    }

    private function invalid(string $path, string $message): never
    {
        throw new ApiException(422, 'invalid_document', $message, ['path' => $path]);
    }
}

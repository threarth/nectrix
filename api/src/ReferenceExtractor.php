<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Chaorganix;

/**
 * Editorial references present in a Document. Each placement has its own referenceId, so the same
 * ID twice is a corrupted document, not two placements of the same reference.
 */
final class ReferenceExtractor
{
    public const KINDS = [
        'entityReference' => 'entityId',
        'semanticBlockReference' => 'semanticBlockId',
    ];

    /**
     * @param array<string, mixed> $document
     * @return array<string, array<string, string>> kind => (referenceId => destinationId)
     */
    public function extract(array $document): array
    {
        $found = ['entityReference' => [], 'semanticBlockReference' => []];
        $seen = [];
        $this->walk($document, $found, $seen);
        return $found;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, string>> $found
     * @param array<string, bool> $seen
     */
    private function walk(array $node, array &$found, array &$seen): void
    {
        $type = $node['type'] ?? null;
        if (is_string($type) && isset(self::KINDS[$type])) {
            $referenceId = $node['attrs']['referenceId'] ?? null;
            $destination = $node['attrs'][self::KINDS[$type]] ?? null;
            if (!is_string($referenceId) || !is_string($destination)) {
                throw new ApiException(422, 'reference_invalid', 'Un riferimento richiede referenceId e destinazione.');
            }
            if (isset($seen[$referenceId])) {
                throw new ApiException(422, 'reference_duplicate', 'Lo stesso referenceId compare più volte nel documento.');
            }
            $seen[$referenceId] = true;
            $found[$type][$referenceId] = $destination;
            return;
        }
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $this->walk($child, $found, $seen);
            }
        }
    }
}

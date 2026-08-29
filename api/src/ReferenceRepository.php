<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;

/**
 * Verification and resolution of editorial references. The label of a destination is read here and
 * never written into the Document: the content keeps only the IDs.
 */
final class ReferenceRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Refuses a save whose references point at something that does not exist. Nothing is created:
     * a manipulated document cannot bring an Entity or a SemanticBlock into being.
     *
     * @param array<string, array<string, string>> $references
     */
    public function assertDestinationsExist(array $references): void
    {
        foreach ($references['entityReference'] ?? [] as $destination) {
            if (!$this->exists('SELECT 1 FROM entities WHERE id = :id', $destination)) {
                throw new ApiException(422, 'reference_not_found', 'Il riferimento punta a una Entity inesistente.');
            }
        }
        foreach ($references['semanticBlockReference'] ?? [] as $destination) {
            if (!$this->exists('SELECT 1 FROM semantic_blocks WHERE id = :id', $destination)) {
                throw new ApiException(422, 'reference_not_found', 'Il riferimento punta a un SemanticBlock inesistente.');
            }
        }
    }

    /**
     * Labels of the destinations, for the editor and for the exporters. Derived at read time.
     *
     * @param list<string> $entityIds
     * @param list<string> $blockIds
     * @return array<string, mixed>
     */
    public function resolve(array $entityIds, array $blockIds): array
    {
        return [
            'entities' => $this->labels(
                'SELECT e.id, e.name AS label, t.name AS detail FROM entities e ' .
                'JOIN entity_types t ON t.id = e.entity_type_id WHERE e.id IN (%s)',
                $entityIds,
            ),
            'semanticBlocks' => $this->labels(
                'SELECT b.id, t.name AS label, e.name AS detail FROM semantic_blocks b ' .
                'JOIN templates t ON t.id = b.template_id JOIN entities e ON e.id = b.entity_id ' .
                'WHERE b.id IN (%s)',
                $blockIds,
            ),
        ];
    }

    /** @param list<string> $ids @return list<array<string, mixed>> */
    private function labels(string $sql, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(sprintf($sql, $placeholders));
        $statement->execute($ids);
        return $statement->fetchAll();
    }

    private function exists(string $sql, string $id): bool
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        return $statement->fetch() !== false;
    }
}

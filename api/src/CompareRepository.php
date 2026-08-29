<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Nectrix;

use PDO;

/** Reads for the comparison. Everything comes from persisted knowledge: nothing is generated. */
final class CompareRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Context reached from the object through its active occurrence and the Document they live in.
     *
     * @return list<array<string, mixed>>
     */
    public function contextsOf(string $objectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT c.id, c.name FROM knowledge_occurrences k ' .
            'JOIN documents d ON d.id = k.document_id ' .
            'JOIN contexts c ON c.id = d.context_id ' .
            "WHERE k.knowledge_object_id = :id AND k.status = 'active' " .
            'ORDER BY c.name COLLATE NOCASE'
        );
        $statement->execute(['id' => $objectId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function occurrencesOf(string $objectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT k.id, k.status, d.id AS document_id, d.title FROM knowledge_occurrences k ' .
            'JOIN documents d ON d.id = k.document_id WHERE k.knowledge_object_id = :id ' .
            'ORDER BY d.updated_at DESC'
        );
        $statement->execute(['id' => $objectId]);
        return $statement->fetchAll();
    }

    /**
     * Templates applied to every one of the given Entity: only a shared Template can align the
     * columns of the comparison on a stable TemplateField.
     *
     * @param list<string> $entityIds
     * @return list<array<string, mixed>>
     */
    public function sharedTemplates(array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT b.template_id AS id, t.name FROM semantic_blocks b ' .
            'JOIN templates t ON t.id = b.template_id ' .
            "WHERE b.entity_id IN ({$placeholders}) " .
            'GROUP BY b.template_id, t.name HAVING COUNT(DISTINCT b.entity_id) = ? ' .
            'ORDER BY t.name COLLATE NOCASE'
        );
        $position = 1;
        foreach ($entityIds as $entityId) {
            $statement->bindValue($position++, $entityId, PDO::PARAM_STR);
        }
        $statement->bindValue($position, count($entityIds), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /**
     * Values of one Entity for one Template, keyed by TemplateField.
     *
     * @return list<array<string, mixed>>
     */
    public function valuesOf(string $entityId, string $templateId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT v.template_field_id, v.field_type, v.ordinal, v.text_value, v.number_value, ' .
            'v.boolean_value, v.date_value, v.unit, v.currency_code, ' .
            'v.entity_reference_id, v.concept_reference_id ' .
            'FROM field_values v JOIN semantic_blocks b ON b.id = v.semantic_block_id ' .
            'WHERE b.entity_id = :entity_id AND b.template_id = :template_id ORDER BY v.ordinal'
        );
        $statement->execute(['entity_id' => $entityId, 'template_id' => $templateId]);
        return $statement->fetchAll();
    }
}

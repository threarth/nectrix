-- SPDX-License-Identifier: AGPL-3.0-or-later
-- FASE 12: provenance verificabile. Una tabella per famiglia di destinazione, con foreign key vere:
-- niente coppie generiche target_type/target_id che SQLite non potrebbe verificare.
-- Il soggetto e una KnowledgeRelation oppure un FieldValue derivato: entrambe colonne con FK reale,
-- e il CHECK impone che ne sia valorizzata esattamente una.

CREATE TABLE evidence_documents (
    id TEXT PRIMARY KEY NOT NULL,
    relation_id TEXT REFERENCES knowledge_relations (id) ON DELETE RESTRICT,
    field_value_id TEXT REFERENCES field_values (id) ON DELETE RESTRICT,
    document_id TEXT NOT NULL REFERENCES documents (id) ON DELETE RESTRICT,
    note TEXT,
    created_at TEXT NOT NULL,
    CHECK ((relation_id IS NOT NULL) + (field_value_id IS NOT NULL) = 1),
    UNIQUE (relation_id, field_value_id, document_id)
) STRICT;

CREATE TABLE evidence_occurrences (
    id TEXT PRIMARY KEY NOT NULL,
    relation_id TEXT REFERENCES knowledge_relations (id) ON DELETE RESTRICT,
    field_value_id TEXT REFERENCES field_values (id) ON DELETE RESTRICT,
    knowledge_occurrence_id TEXT NOT NULL REFERENCES knowledge_occurrences (id) ON DELETE RESTRICT,
    note TEXT,
    created_at TEXT NOT NULL,
    CHECK ((relation_id IS NOT NULL) + (field_value_id IS NOT NULL) = 1),
    UNIQUE (relation_id, field_value_id, knowledge_occurrence_id)
) STRICT;

CREATE TABLE evidence_semantic_blocks (
    id TEXT PRIMARY KEY NOT NULL,
    relation_id TEXT REFERENCES knowledge_relations (id) ON DELETE RESTRICT,
    field_value_id TEXT REFERENCES field_values (id) ON DELETE RESTRICT,
    semantic_block_id TEXT NOT NULL REFERENCES semantic_blocks (id) ON DELETE RESTRICT,
    note TEXT,
    created_at TEXT NOT NULL,
    CHECK ((relation_id IS NOT NULL) + (field_value_id IS NOT NULL) = 1),
    UNIQUE (relation_id, field_value_id, semantic_block_id)
) STRICT;

CREATE TABLE evidence_field_values (
    id TEXT PRIMARY KEY NOT NULL,
    relation_id TEXT REFERENCES knowledge_relations (id) ON DELETE RESTRICT,
    field_value_id TEXT REFERENCES field_values (id) ON DELETE RESTRICT,
    target_field_value_id TEXT NOT NULL REFERENCES field_values (id) ON DELETE RESTRICT,
    note TEXT,
    created_at TEXT NOT NULL,
    CHECK ((relation_id IS NOT NULL) + (field_value_id IS NOT NULL) = 1),
    UNIQUE (relation_id, field_value_id, target_field_value_id)
) STRICT;

CREATE INDEX evidence_documents_relation_idx ON evidence_documents (relation_id);
CREATE INDEX evidence_occurrences_relation_idx ON evidence_occurrences (relation_id);
CREATE INDEX evidence_semantic_blocks_relation_idx ON evidence_semantic_blocks (relation_id);
CREATE INDEX evidence_field_values_relation_idx ON evidence_field_values (relation_id);

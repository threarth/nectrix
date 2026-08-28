-- SPDX-License-Identifier: AGPL-3.0-or-later
-- FASE 9: Tag come metadata libero dei Document. Non hanno canonical name, alias, occurrence ne
-- relazioni semantiche: restano una dimensione separata da Concept, Entity ed EntityType.

CREATE TABLE tags (
    id TEXT PRIMARY KEY NOT NULL,
    name TEXT NOT NULL CHECK (length(trim(name)) > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;

CREATE UNIQUE INDEX tags_name_idx ON tags (name COLLATE NOCASE);

CREATE TABLE document_tags (
    document_id TEXT NOT NULL REFERENCES documents (id) ON DELETE RESTRICT,
    tag_id TEXT NOT NULL REFERENCES tags (id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL,
    PRIMARY KEY (document_id, tag_id)
) STRICT;

CREATE INDEX document_tags_tag_idx ON document_tags (tag_id, document_id);

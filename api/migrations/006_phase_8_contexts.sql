-- SPDX-License-Identifier: AGPL-3.0-or-later
-- FASE 8: Context gerarchici. Un sub-context e semplicemente un Context con parent_id.
-- Il percorso e la profondita si calcolano dalla gerarchia e non sono identita persistite.

CREATE TABLE contexts (
    id TEXT PRIMARY KEY NOT NULL,
    parent_id TEXT REFERENCES contexts (id) ON DELETE RESTRICT,
    name TEXT NOT NULL CHECK (length(trim(name)) > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (parent_id IS NULL OR parent_id <> id)
) STRICT;

-- ifnull rende confrontabile la radice: senza, due radici omonime sarebbero ammesse.
CREATE UNIQUE INDEX contexts_sibling_name_idx
    ON contexts (ifnull(parent_id, ''), name COLLATE NOCASE);

CREATE INDEX contexts_parent_idx ON contexts (parent_id);

-- Il Document riceve un Context solo con un comando esplicito, mai per effetto collaterale.
ALTER TABLE documents ADD COLUMN context_id TEXT REFERENCES contexts (id) ON DELETE RESTRICT;

CREATE INDEX documents_context_idx ON documents (context_id, status);

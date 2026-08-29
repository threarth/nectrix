-- SPDX-License-Identifier: AGPL-3.0-or-later
-- FASE 14.1: il Context organizza frammenti di testo, non Document.
-- Un Document e un contenitore disordinato e non e consapevole del Context: l'indice che l'utente
-- costruisce sui frammenti e cio che rende comparabili appunti scritti in punti e momenti diversi.

-- Un range di testo marcato con un Context. Identita e lifecycle sono quelli delle
-- KnowledgeOccurrence: nulla viene eliminato dal normale salvataggio.
CREATE TABLE context_occurrences (
    id TEXT PRIMARY KEY NOT NULL,
    context_id TEXT NOT NULL REFERENCES contexts (id) ON DELETE RESTRICT,
    document_id TEXT NOT NULL REFERENCES documents (id) ON DELETE RESTRICT,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'detached', 'deleted')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;

CREATE INDEX context_occurrences_context_idx
    ON context_occurrences (context_id, status, document_id);
CREATE INDEX context_occurrences_document_idx
    ON context_occurrences (document_id, status);

-- Appartenenza derivata dal contenimento nel document_json, che resta l'unica autorita.
-- La tabella e interamente ricostruibile: nessun dato autorevole vive qui.
CREATE TABLE context_memberships (
    context_occurrence_id TEXT NOT NULL REFERENCES context_occurrences (id) ON DELETE RESTRICT,
    knowledge_occurrence_id TEXT NOT NULL REFERENCES knowledge_occurrences (id) ON DELETE RESTRICT,
    context_id TEXT NOT NULL REFERENCES contexts (id) ON DELETE RESTRICT,
    knowledge_object_id TEXT NOT NULL,
    object_type TEXT NOT NULL CHECK (object_type IN ('concept', 'entity')),
    document_id TEXT NOT NULL REFERENCES documents (id) ON DELETE RESTRICT,
    FOREIGN KEY (knowledge_object_id, object_type)
        REFERENCES knowledge_objects (id, object_type) ON DELETE RESTRICT,
    PRIMARY KEY (context_occurrence_id, knowledge_occurrence_id)
) STRICT;

CREATE INDEX context_memberships_context_idx
    ON context_memberships (context_id, object_type, knowledge_object_id);
CREATE INDEX context_memberships_document_idx
    ON context_memberships (document_id);
CREATE INDEX context_memberships_occurrence_idx
    ON context_memberships (knowledge_occurrence_id);

-- Il Document smette di possedere un Context: il legame vive sul frammento.
DROP INDEX documents_context_idx;
ALTER TABLE documents DROP COLUMN context_id;

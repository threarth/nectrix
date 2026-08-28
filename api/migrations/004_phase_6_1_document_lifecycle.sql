-- SPDX-License-Identifier: AGPL-3.0-or-later
-- FASE 6.1: lifecycle non distruttivo dei Document. Archive e trash sono reversibili, non
-- eliminano contenuto o conoscenza collegata e non cambiano lo stato delle KnowledgeOccurrence.

ALTER TABLE documents
    ADD COLUMN status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived', 'trashed'));

CREATE INDEX documents_status_updated_at_idx ON documents (status, updated_at DESC, id);

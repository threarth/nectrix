-- SPDX-License-Identifier: AGPL-3.0-or-later
-- FASE 7: EntityIdentifier come modello distinto da ConceptAlias e da FieldValue.
-- L'identita normalizzata e unica nella stessa Entity; fra Entity differenti resta ammessa e
-- produce un candidato duplicato, mai un merge automatico.

CREATE TABLE entity_identifiers (
    id TEXT PRIMARY KEY NOT NULL,
    entity_id TEXT NOT NULL REFERENCES entities (id) ON DELETE RESTRICT,
    scheme TEXT NOT NULL CHECK (scheme = lower(scheme) AND length(trim(scheme)) > 0),
    value TEXT NOT NULL CHECK (length(trim(value)) > 0),
    normalized_value TEXT NOT NULL CHECK (length(normalized_value) > 0),
    authority_or_namespace TEXT CHECK (authority_or_namespace IS NULL OR length(trim(authority_or_namespace)) > 0),
    normalization_version INTEGER NOT NULL CHECK (normalization_version >= 1),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;

-- ifnull rende l'authority assente parte dell'identita: senza, SQLite considererebbe
-- distinti due NULL e ammetterebbe duplicati nella stessa Entity.
CREATE UNIQUE INDEX entity_identifiers_identity_idx
    ON entity_identifiers (entity_id, scheme, normalized_value, ifnull(authority_or_namespace, ''));

CREATE INDEX entity_identifiers_lookup_idx
    ON entity_identifiers (scheme, normalized_value, ifnull(authority_or_namespace, ''));

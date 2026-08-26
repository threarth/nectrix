-- SPDX-License-Identifier: AGPL-3.0-or-later

CREATE TABLE documents (
    id TEXT PRIMARY KEY NOT NULL,
    title TEXT NOT NULL,
    document_json TEXT NOT NULL CHECK (json_valid(document_json)),
    plain_text TEXT NOT NULL,
    revision INTEGER NOT NULL DEFAULT 0 CHECK (revision >= 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;

CREATE INDEX documents_updated_at_idx ON documents (updated_at DESC, id);

-- SPDX-License-Identifier: AGPL-3.0-or-later
-- FASE 10: indice full text su titolo e plain_text derivato del Document.
-- Il contenuto autorevole resta in documents: l'indice e external content e si ricostruisce
-- interamente dai dati con INSERT INTO documents_fts(documents_fts) VALUES('rebuild').

CREATE VIRTUAL TABLE documents_fts USING fts5(
    title,
    plain_text,
    content='documents',
    content_rowid='rowid'
);

CREATE TRIGGER documents_fts_insert AFTER INSERT ON documents BEGIN
    INSERT INTO documents_fts (rowid, title, plain_text) VALUES (new.rowid, new.title, new.plain_text);
END;

CREATE TRIGGER documents_fts_delete AFTER DELETE ON documents BEGIN
    INSERT INTO documents_fts (documents_fts, rowid, title, plain_text)
    VALUES ('delete', old.rowid, old.title, old.plain_text);
END;

CREATE TRIGGER documents_fts_update AFTER UPDATE OF title, plain_text ON documents BEGIN
    INSERT INTO documents_fts (documents_fts, rowid, title, plain_text)
    VALUES ('delete', old.rowid, old.title, old.plain_text);
    INSERT INTO documents_fts (rowid, title, plain_text) VALUES (new.rowid, new.title, new.plain_text);
END;

-- Popola l'indice con i Document gia esistenti.
INSERT INTO documents_fts (documents_fts) VALUES ('rebuild');

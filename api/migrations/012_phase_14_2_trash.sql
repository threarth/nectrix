-- SPDX-License-Identifier: AGPL-3.0-or-later
-- FASE 14.2: cestino dei tre organizzatori. Cestinare non distrugge nulla: l'oggetto sparisce dagli
-- elenchi e dalle ricerche, ma le sue occurrence e i mark nel testo restano intatti finche non si
-- decide di eliminarlo davvero. Il cestino e additivo: non tocca lo status, che continua a
-- descrivere il lifecycle semantico (active, orphan, archived).

CREATE TABLE knowledge_object_trash (
    knowledge_object_id TEXT PRIMARY KEY NOT NULL
        REFERENCES knowledge_objects (id) ON DELETE RESTRICT,
    trashed_at TEXT NOT NULL
) STRICT;

CREATE TABLE context_trash (
    context_id TEXT PRIMARY KEY NOT NULL REFERENCES contexts (id) ON DELETE RESTRICT,
    trashed_at TEXT NOT NULL
) STRICT;

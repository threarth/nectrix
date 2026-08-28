-- SPDX-License-Identifier: AGPL-3.0-or-later
-- FASE 6: archive e restore espliciti degli EntityType. Un tipo archiviato resta valido per le
-- Entity che lo referenziano gia e non viene mai eliminato.

ALTER TABLE entity_types
    ADD COLUMN status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived'));

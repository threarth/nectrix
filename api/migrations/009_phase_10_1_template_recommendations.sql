-- SPDX-License-Identifier: AGPL-3.0-or-later
-- FASE 10.1: raccomandazioni ordinate EntityType <-> Template. Guidano la UI, non vincolano:
-- un Template non raccomandato resta applicabile con una scelta esplicita.

CREATE TABLE entity_type_templates (
    entity_type_id TEXT NOT NULL REFERENCES entity_types (id) ON DELETE RESTRICT,
    template_id TEXT NOT NULL REFERENCES templates (id) ON DELETE RESTRICT,
    sort_order INTEGER NOT NULL CHECK (sort_order >= 0),
    created_at TEXT NOT NULL,
    PRIMARY KEY (entity_type_id, template_id)
) STRICT;

CREATE INDEX entity_type_templates_order_idx
    ON entity_type_templates (entity_type_id, sort_order);

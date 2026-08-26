-- SPDX-License-Identifier: AGPL-3.0-or-later

CREATE TABLE entity_types (
    id TEXT PRIMARY KEY NOT NULL,
    name TEXT NOT NULL CHECK (length(trim(name)) > 0),
    description TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;

CREATE UNIQUE INDEX entity_types_name_idx ON entity_types (name COLLATE NOCASE);

CREATE TABLE knowledge_objects (
    id TEXT PRIMARY KEY NOT NULL,
    object_type TEXT NOT NULL CHECK (object_type IN ('concept', 'entity')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (id, object_type)
) STRICT;

CREATE TABLE concepts (
    id TEXT PRIMARY KEY NOT NULL,
    object_type TEXT NOT NULL DEFAULT 'concept' CHECK (object_type = 'concept'),
    canonical_name TEXT NOT NULL CHECK (length(trim(canonical_name)) > 0),
    description TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'orphan', 'archived')),
    FOREIGN KEY (id, object_type) REFERENCES knowledge_objects (id, object_type) ON DELETE RESTRICT
) STRICT;

CREATE TABLE concept_aliases (
    id TEXT PRIMARY KEY NOT NULL,
    concept_id TEXT NOT NULL REFERENCES concepts (id) ON DELETE RESTRICT,
    alias TEXT NOT NULL CHECK (length(trim(alias)) > 0)
) STRICT;

CREATE UNIQUE INDEX concept_aliases_concept_alias_idx
    ON concept_aliases (concept_id, alias COLLATE NOCASE);

CREATE TABLE entities (
    id TEXT PRIMARY KEY NOT NULL,
    object_type TEXT NOT NULL DEFAULT 'entity' CHECK (object_type = 'entity'),
    entity_type_id TEXT NOT NULL REFERENCES entity_types (id) ON DELETE RESTRICT,
    name TEXT NOT NULL CHECK (length(trim(name)) > 0),
    description TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
    FOREIGN KEY (id, object_type) REFERENCES knowledge_objects (id, object_type) ON DELETE RESTRICT
) STRICT;

CREATE INDEX entities_type_name_idx ON entities (entity_type_id, name COLLATE NOCASE);

CREATE TABLE knowledge_occurrences (
    id TEXT PRIMARY KEY NOT NULL,
    knowledge_object_id TEXT NOT NULL,
    object_type TEXT NOT NULL CHECK (object_type IN ('concept', 'entity')),
    document_id TEXT NOT NULL REFERENCES documents (id) ON DELETE RESTRICT,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'detached', 'deleted')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (knowledge_object_id, object_type)
        REFERENCES knowledge_objects (id, object_type) ON DELETE RESTRICT
) STRICT;

CREATE INDEX knowledge_occurrences_object_idx
    ON knowledge_occurrences (knowledge_object_id, status, document_id);
CREATE INDEX knowledge_occurrences_document_idx
    ON knowledge_occurrences (document_id, status);

CREATE TRIGGER knowledge_occurrences_subtype_insert
BEFORE INSERT ON knowledge_occurrences
BEGIN
    SELECT CASE
        WHEN NEW.object_type = 'concept'
            AND NOT EXISTS (SELECT 1 FROM concepts WHERE id = NEW.knowledge_object_id)
            THEN RAISE(ABORT, 'knowledge occurrence concept subtype missing')
        WHEN NEW.object_type = 'entity'
            AND NOT EXISTS (SELECT 1 FROM entities WHERE id = NEW.knowledge_object_id)
            THEN RAISE(ABORT, 'knowledge occurrence entity subtype missing')
    END;
END;

CREATE TRIGGER knowledge_occurrences_subtype_update
BEFORE UPDATE OF knowledge_object_id, object_type ON knowledge_occurrences
BEGIN
    SELECT CASE
        WHEN NEW.object_type = 'concept'
            AND NOT EXISTS (SELECT 1 FROM concepts WHERE id = NEW.knowledge_object_id)
            THEN RAISE(ABORT, 'knowledge occurrence concept subtype missing')
        WHEN NEW.object_type = 'entity'
            AND NOT EXISTS (SELECT 1 FROM entities WHERE id = NEW.knowledge_object_id)
            THEN RAISE(ABORT, 'knowledge occurrence entity subtype missing')
    END;
END;

CREATE TABLE knowledge_relations (
    id TEXT PRIMARY KEY NOT NULL,
    source_knowledge_object_id TEXT NOT NULL,
    source_object_type TEXT NOT NULL CHECK (source_object_type IN ('concept', 'entity')),
    target_knowledge_object_id TEXT NOT NULL,
    target_object_type TEXT NOT NULL CHECK (target_object_type IN ('concept', 'entity')),
    relation_type TEXT NOT NULL CHECK (length(trim(relation_type)) > 0),
    description TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (source_knowledge_object_id, source_object_type)
        REFERENCES knowledge_objects (id, object_type) ON DELETE RESTRICT,
    FOREIGN KEY (target_knowledge_object_id, target_object_type)
        REFERENCES knowledge_objects (id, object_type) ON DELETE RESTRICT
) STRICT;

CREATE INDEX knowledge_relations_source_idx
    ON knowledge_relations (source_knowledge_object_id, relation_type);
CREATE INDEX knowledge_relations_target_idx
    ON knowledge_relations (target_knowledge_object_id, relation_type);

CREATE TRIGGER knowledge_relations_subtypes_insert
BEFORE INSERT ON knowledge_relations
BEGIN
    SELECT CASE
        WHEN NEW.source_object_type = 'concept'
            AND NOT EXISTS (SELECT 1 FROM concepts WHERE id = NEW.source_knowledge_object_id)
            THEN RAISE(ABORT, 'knowledge relation source concept subtype missing')
        WHEN NEW.source_object_type = 'entity'
            AND NOT EXISTS (SELECT 1 FROM entities WHERE id = NEW.source_knowledge_object_id)
            THEN RAISE(ABORT, 'knowledge relation source entity subtype missing')
        WHEN NEW.target_object_type = 'concept'
            AND NOT EXISTS (SELECT 1 FROM concepts WHERE id = NEW.target_knowledge_object_id)
            THEN RAISE(ABORT, 'knowledge relation target concept subtype missing')
        WHEN NEW.target_object_type = 'entity'
            AND NOT EXISTS (SELECT 1 FROM entities WHERE id = NEW.target_knowledge_object_id)
            THEN RAISE(ABORT, 'knowledge relation target entity subtype missing')
    END;
END;

CREATE TRIGGER knowledge_relations_subtypes_update
BEFORE UPDATE OF source_knowledge_object_id, source_object_type,
    target_knowledge_object_id, target_object_type ON knowledge_relations
BEGIN
    SELECT CASE
        WHEN NEW.source_object_type = 'concept'
            AND NOT EXISTS (SELECT 1 FROM concepts WHERE id = NEW.source_knowledge_object_id)
            THEN RAISE(ABORT, 'knowledge relation source concept subtype missing')
        WHEN NEW.source_object_type = 'entity'
            AND NOT EXISTS (SELECT 1 FROM entities WHERE id = NEW.source_knowledge_object_id)
            THEN RAISE(ABORT, 'knowledge relation source entity subtype missing')
        WHEN NEW.target_object_type = 'concept'
            AND NOT EXISTS (SELECT 1 FROM concepts WHERE id = NEW.target_knowledge_object_id)
            THEN RAISE(ABORT, 'knowledge relation target concept subtype missing')
        WHEN NEW.target_object_type = 'entity'
            AND NOT EXISTS (SELECT 1 FROM entities WHERE id = NEW.target_knowledge_object_id)
            THEN RAISE(ABORT, 'knowledge relation target entity subtype missing')
    END;
END;

CREATE TABLE templates (
    id TEXT PRIMARY KEY NOT NULL,
    name TEXT NOT NULL CHECK (length(trim(name)) > 0),
    description TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;

CREATE UNIQUE INDEX templates_name_idx ON templates (name COLLATE NOCASE);

CREATE TABLE template_fields (
    id TEXT PRIMARY KEY NOT NULL,
    template_id TEXT NOT NULL REFERENCES templates (id) ON DELETE RESTRICT,
    name TEXT NOT NULL CHECK (length(trim(name)) > 0),
    field_type TEXT NOT NULL CHECK (field_type IN (
        'text', 'rich_text', 'number', 'boolean', 'enum', 'date',
        'measurement', 'currency', 'percentage', 'entity_reference',
        'concept_reference', 'url', 'source_reference', 'multi_enum',
        'multi_entity_reference', 'multi_concept_reference'
    )),
    is_required INTEGER NOT NULL DEFAULT 0 CHECK (is_required IN (0, 1)),
    is_searchable INTEGER NOT NULL DEFAULT 0 CHECK (is_searchable IN (0, 1)),
    is_indexed INTEGER NOT NULL DEFAULT 0 CHECK (is_indexed IN (0, 1)),
    sort_order INTEGER NOT NULL CHECK (sort_order >= 0),
    options_json TEXT CHECK (options_json IS NULL OR json_valid(options_json)),
    default_value_json TEXT CHECK (default_value_json IS NULL OR json_valid(default_value_json)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (template_id, sort_order),
    UNIQUE (id, template_id, field_type)
) STRICT;

CREATE TABLE semantic_blocks (
    id TEXT PRIMARY KEY NOT NULL,
    entity_id TEXT NOT NULL REFERENCES entities (id) ON DELETE RESTRICT,
    template_id TEXT NOT NULL REFERENCES templates (id) ON DELETE RESTRICT,
    sort_order INTEGER NOT NULL DEFAULT 0 CHECK (sort_order >= 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (id, template_id),
    UNIQUE (entity_id, sort_order)
) STRICT;

CREATE INDEX semantic_blocks_entity_template_idx
    ON semantic_blocks (entity_id, template_id);

CREATE TABLE field_values (
    id TEXT PRIMARY KEY NOT NULL,
    semantic_block_id TEXT NOT NULL,
    template_id TEXT NOT NULL,
    template_field_id TEXT NOT NULL,
    field_type TEXT NOT NULL,
    ordinal INTEGER NOT NULL DEFAULT 0 CHECK (ordinal >= 0),
    text_value TEXT,
    rich_text_json TEXT CHECK (rich_text_json IS NULL OR json_valid(rich_text_json)),
    number_value REAL,
    boolean_value INTEGER CHECK (boolean_value IS NULL OR boolean_value IN (0, 1)),
    date_value TEXT,
    unit TEXT,
    currency_code TEXT,
    entity_reference_id TEXT REFERENCES entities (id) ON DELETE RESTRICT,
    concept_reference_id TEXT REFERENCES concepts (id) ON DELETE RESTRICT,
    linked_concept_id TEXT REFERENCES concepts (id) ON DELETE RESTRICT,
    origin TEXT NOT NULL DEFAULT 'manual'
        CHECK (origin IN ('manual', 'provider', 'derived', 'ai_suggested')),
    provider_id TEXT,
    retrieved_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (semantic_block_id, template_id)
        REFERENCES semantic_blocks (id, template_id) ON DELETE RESTRICT,
    FOREIGN KEY (template_field_id, template_id, field_type)
        REFERENCES template_fields (id, template_id, field_type) ON DELETE RESTRICT,
    UNIQUE (semantic_block_id, template_field_id, ordinal),
    CHECK (
        (field_type IN ('multi_enum', 'multi_entity_reference', 'multi_concept_reference'))
        OR ordinal = 0
    ),
    CHECK (
        (field_type IN ('text', 'enum', 'multi_enum', 'url')
            AND text_value IS NOT NULL
            AND rich_text_json IS NULL AND number_value IS NULL AND boolean_value IS NULL
            AND date_value IS NULL AND unit IS NULL AND currency_code IS NULL
            AND entity_reference_id IS NULL AND concept_reference_id IS NULL)
        OR
        (field_type = 'rich_text'
            AND text_value IS NULL AND rich_text_json IS NOT NULL
            AND number_value IS NULL AND boolean_value IS NULL AND date_value IS NULL
            AND unit IS NULL AND currency_code IS NULL
            AND entity_reference_id IS NULL AND concept_reference_id IS NULL)
        OR
        (field_type IN ('number', 'percentage')
            AND text_value IS NULL AND rich_text_json IS NULL AND number_value IS NOT NULL
            AND boolean_value IS NULL AND date_value IS NULL AND unit IS NULL
            AND currency_code IS NULL AND entity_reference_id IS NULL
            AND concept_reference_id IS NULL)
        OR
        (field_type = 'boolean'
            AND text_value IS NULL AND rich_text_json IS NULL AND number_value IS NULL
            AND boolean_value IS NOT NULL AND date_value IS NULL AND unit IS NULL
            AND currency_code IS NULL AND entity_reference_id IS NULL
            AND concept_reference_id IS NULL)
        OR
        (field_type = 'date'
            AND text_value IS NULL AND rich_text_json IS NULL AND number_value IS NULL
            AND boolean_value IS NULL AND date_value IS NOT NULL AND unit IS NULL
            AND currency_code IS NULL AND entity_reference_id IS NULL
            AND concept_reference_id IS NULL)
        OR
        (field_type = 'measurement'
            AND text_value IS NULL AND rich_text_json IS NULL AND number_value IS NOT NULL
            AND boolean_value IS NULL AND date_value IS NULL AND unit IS NOT NULL
            AND currency_code IS NULL AND entity_reference_id IS NULL
            AND concept_reference_id IS NULL)
        OR
        (field_type = 'currency'
            AND text_value IS NULL AND rich_text_json IS NULL AND number_value IS NOT NULL
            AND boolean_value IS NULL AND date_value IS NULL AND unit IS NULL
            AND currency_code IS NOT NULL AND entity_reference_id IS NULL
            AND concept_reference_id IS NULL)
        OR
        (field_type IN ('entity_reference', 'multi_entity_reference')
            AND text_value IS NULL AND rich_text_json IS NULL AND number_value IS NULL
            AND boolean_value IS NULL AND date_value IS NULL AND unit IS NULL
            AND currency_code IS NULL AND entity_reference_id IS NOT NULL
            AND concept_reference_id IS NULL)
        OR
        (field_type IN ('concept_reference', 'multi_concept_reference')
            AND text_value IS NULL AND rich_text_json IS NULL AND number_value IS NULL
            AND boolean_value IS NULL AND date_value IS NULL AND unit IS NULL
            AND currency_code IS NULL AND entity_reference_id IS NULL
            AND concept_reference_id IS NOT NULL)
    ),
    CHECK (origin <> 'provider' OR (provider_id IS NOT NULL AND retrieved_at IS NOT NULL))
) STRICT;

CREATE INDEX field_values_text_idx ON field_values (template_field_id, text_value);
CREATE INDEX field_values_number_idx ON field_values (template_field_id, number_value);
CREATE INDEX field_values_boolean_idx ON field_values (template_field_id, boolean_value);
CREATE INDEX field_values_date_idx ON field_values (template_field_id, date_value);
CREATE INDEX field_values_entity_reference_idx ON field_values (entity_reference_id);
CREATE INDEX field_values_concept_reference_idx ON field_values (concept_reference_id);
CREATE INDEX field_values_linked_concept_idx ON field_values (linked_concept_id);

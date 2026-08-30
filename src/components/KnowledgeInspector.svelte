<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { onDestroy } from 'svelte'

  import type {
    DuplicateCandidate,
    EntityIdentifierInput,
    KnowledgeObjectDetail,
    EvidenceView,
    KnowledgeOccurrenceView,
    RelationView,
    SemanticBlock,
    Template,
  } from '../lib/api'
  import { compareStrings, evidenceStrings, inspectorStrings, previewStrings, relationStrings } from '../lib/strings'
  import ConceptInspector from './ConceptInspector.svelte'
  import EntityInspector from './EntityInspector.svelte'

  let {
    object,
    busy = false,
    duplicateCandidates = [],
    onClose,
    onToggleArchived,
    onToggleEntityTypeArchived,
    onOpenOccurrence,
    onAddAlias,
    onRemoveAlias,
    onAddIdentifier,
    onRemoveIdentifier,
    onRename,
    blocks,
    templates,
    onAddBlock,
    onRemoveBlock,
    onSetValues,
    relations,
    onAddRelation,
    onRemoveRelation,
    onOpenRelated,
    evidence,
    canAddDocumentEvidence,
    onShowEvidence,
    onAddDocumentEvidence,
    onRemoveEvidence,
    onAddToCompare,
    onPreview,
    onDelete,
  }: {
    object: KnowledgeObjectDetail
    busy?: boolean
    /** Other Entity with the same identity, reported after the last identifier was added. */
    duplicateCandidates?: DuplicateCandidate[]
    onClose: () => void
    onToggleArchived: () => void
    onToggleEntityTypeArchived: () => void
    onOpenOccurrence: (occurrence: KnowledgeOccurrenceView) => void
    onAddAlias: (alias: string) => void
    onRemoveAlias: (aliasId: string) => void
    onAddIdentifier: (input: EntityIdentifierInput) => void
    onRemoveIdentifier: (identifierId: string) => void
    onRename: (name: string, description: string | null) => void
    blocks: SemanticBlock[]
    templates: Template[]
    onAddBlock: (templateId: string) => void
    onRemoveBlock: (blockId: string) => void
    onSetValues: (blockId: string, fieldId: string, values: unknown[]) => void
    /** Declared arcs towards other Concept or Entity, in both directions. */
    relations: RelationView[]
    onAddRelation: () => void
    onRemoveRelation: (relationId: string) => void
    onOpenRelated: (objectId: string) => void
    /** Evidence of the relation currently expanded, if any. */
    evidence: { relationId: string; items: EvidenceView[] } | null
    canAddDocumentEvidence: boolean
    onShowEvidence: (relationId: string) => void
    onAddDocumentEvidence: (relationId: string) => void
    onRemoveEvidence: (relationId: string, family: EvidenceView['family'], evidenceId: string) => void
    onAddToCompare: () => void
    /** Shows the Documents that contain it, as thumbnails. */
    onPreview: () => void
    /** Moves the object to the trash: the marks stay in the text. */
    onDelete: () => void
  } = $props()

  function startEditing(): void {
    draftName = object.name
    draftDescription = object.description ?? ''
    editing = true
  }

  function confirmEditing(): void {
    const name = draftName.trim()
    if (name === '' || busy) return
    onRename(name, draftDescription.trim() === '' ? null : draftDescription.trim())
    editing = false
  }

  let editing = $state(false)
  let draftName = $state('')
  let draftDescription = $state('')

  /** How long the trash button stays armed. */
  const ARMED_MS = 4000

  const archived = $derived(object.status === 'archived')

  let armed = $state(false)
  let armedTimer: ReturnType<typeof setTimeout> | undefined

  onDestroy(() => clearTimeout(armedTimer))

  /** Two presses instead of a dialog: the first says what will happen, the second does it. */
  function arm(): void {
    if (!armed) {
      armed = true
      clearTimeout(armedTimer)
      armedTimer = setTimeout(() => (armed = false), ARMED_MS)
      return
    }
    clearTimeout(armedTimer)
    armed = false
    onDelete()
  }
  const lifecycleAction = $derived(archived ? inspectorStrings.restore : inspectorStrings.archive)
</script>

<aside class="inspector" aria-label={inspectorStrings.panelLabel}>
  <header class="inspector-header">
    <span class="inspector-kind">{inspectorStrings.kind(object.objectType)}</span>
    <button
      type="button"
      class="inspector-close"
      aria-label={inspectorStrings.close.ariaLabel}
      title={inspectorStrings.close.description}
      onclick={onClose}
    >{inspectorStrings.close.label}</button>
  </header>

  {#if editing}
    <form
      class="inspector-edit"
      onsubmit={(event) => {
        event.preventDefault()
        confirmEditing()
      }}
    >
      <label class="dialog-field">
        {inspectorStrings.edit.nameLabel}
        <input bind:value={draftName} />
      </label>
      <label class="dialog-field">
        {inspectorStrings.edit.descriptionLabel}
        <textarea bind:value={draftDescription} rows="3"></textarea>
      </label>
      <div class="inspector-edit-actions">
        <button type="button" class="dialog-cancel" onclick={() => (editing = false)}>
          {inspectorStrings.edit.cancel}
        </button>
        <button type="submit" class="dialog-confirm" disabled={draftName.trim() === '' || busy}>
          {inspectorStrings.edit.save}
        </button>
      </div>
    </form>
  {:else}
    <h2 class="inspector-name">{object.name}</h2>
    <p class="inspector-status">
      {inspectorStrings.statusLabel}: {inspectorStrings.objectStatus[object.status] ?? object.status}
      <button
        type="button"
        class="inspector-edit-open"
        disabled={busy}
        title={inspectorStrings.edit.description}
        onclick={startEditing}
      >{inspectorStrings.edit.label}</button>
    </p>
  {/if}

  {#if object.objectType === 'concept'}
    <ConceptInspector {object} {busy} {onAddAlias} {onRemoveAlias} />
  {:else}
    <EntityInspector
      {object}
      {busy}
      {duplicateCandidates}
      {onToggleEntityTypeArchived}
      {onAddIdentifier}
      {onRemoveIdentifier}
      {blocks}
      {templates}
      {onAddBlock}
      {onRemoveBlock}
      {onSetValues}
    />
  {/if}

  <section class="inspector-list" aria-label={relationStrings.label} title={relationStrings.description}>
    <h3>{relationStrings.label} ({relations.length})</h3>
    {#if relations.length === 0}
      <p class="muted">{relationStrings.empty}</p>
    {:else}
      <ul>
        {#each relations as relation (relation.id)}
          <li>
            <button type="button" class="relation-open" onclick={() => onOpenRelated(relation.otherId)}>
              <span class="relation-direction">
                {relation.direction === 'outgoing' ? relationStrings.outgoing : relationStrings.incoming}
              </span>
              {relation.relationType}
              <strong>{relation.otherName}</strong>
              <small>{relation.otherType === 'concept' ? 'Concept' : 'Entity'}</small>
            </button>
            <button
              type="button"
              class="relation-evidence-toggle"
              disabled={busy}
              title={evidenceStrings.show.description}
              onclick={() => onShowEvidence(relation.id)}
            >{evidenceStrings.show.label}</button>
            <button
              type="button"
              disabled={busy}
              aria-label={`${relationStrings.remove.description}: ${relation.relationType} ${relation.otherName}`}
              title={relationStrings.remove.description}
              onclick={() => onRemoveRelation(relation.id)}
            >{relationStrings.remove.label}</button>
          </li>

          {#if evidence !== null && evidence.relationId === relation.id}
            <li class="relation-evidence" aria-label={evidenceStrings.label} title={evidenceStrings.description}>
              {#if evidence.items.length === 0}
                <span class="muted">{evidenceStrings.empty}</span>
              {:else}
                <ul>
                  {#each evidence.items as item (item.id)}
                    <li>
                      <span>
                        <small>{evidenceStrings.families[item.family] ?? item.family}</small>
                        {item.label}
                        <small>{evidenceStrings.states[item.state] ?? item.state}</small>
                      </span>
                      <button
                        type="button"
                        disabled={busy}
                        aria-label={`${evidenceStrings.remove.description}: ${item.label}`}
                        title={evidenceStrings.remove.description}
                        onclick={() => onRemoveEvidence(relation.id, item.family, item.id)}
                      >{evidenceStrings.remove.label}</button>
                    </li>
                  {/each}
                </ul>
              {/if}
              {#if canAddDocumentEvidence}
                <button
                  type="button"
                  class="inspector-secondary"
                  disabled={busy}
                  title={evidenceStrings.addDocument.description}
                  onclick={() => onAddDocumentEvidence(relation.id)}
                >{evidenceStrings.addDocument.label}</button>
              {/if}
            </li>
          {/if}
        {/each}
      </ul>
    {/if}
    <button
      type="button"
      class="inspector-secondary"
      disabled={busy}
      title={relationStrings.add.description}
      onclick={onAddRelation}
    >{relationStrings.add.label}</button>
  </section>

  <section class="inspector-occurrences" aria-label={inspectorStrings.occurrences.label}>
    <h3>{inspectorStrings.occurrences.label} ({object.occurrences.length})</h3>
    {#if object.occurrences.length === 0}
      <p class="muted">{inspectorStrings.occurrences.empty}</p>
    {:else}
      <ul>
        {#each object.occurrences as occurrence (occurrence.id)}
          <li>
            <button
              type="button"
              title={inspectorStrings.occurrences.description}
              onclick={() => onOpenOccurrence(occurrence)}
            >
              <span class="occurrence-text" class:muted={occurrence.text === ''}>
                {occurrence.text === '' ? inspectorStrings.occurrences.missingText : occurrence.text}
              </span>
              <small>
                {occurrence.documentTitle}
                · {inspectorStrings.occurrences.status[occurrence.status] ?? occurrence.status}
              </small>
            </button>
          </li>
        {/each}
      </ul>
    {/if}
  </section>

  <div class="inspector-footer">
    <button
      type="button"
      class="inspector-lifecycle"
      disabled={busy}
      title={lifecycleAction.description}
      onclick={onToggleArchived}
    >{lifecycleAction.label}</button>
    <button
      type="button"
      class="inspector-secondary"
      disabled={busy}
      title={previewStrings.showLabel.description}
      onclick={onPreview}
    >{previewStrings.showLabel.label}</button>
    <button
      type="button"
      class="inspector-secondary"
      disabled={busy}
      title={compareStrings.add.description}
      onclick={onAddToCompare}
    >{compareStrings.add.label}</button>
    <button
      type="button"
      class="inspector-danger"
      class:armed={armed}
      disabled={busy}
      title={armed ? inspectorStrings.remove.confirm : inspectorStrings.remove.description}
      onclick={arm}
    >{armed ? inspectorStrings.remove.confirmLabel : inspectorStrings.remove.label}</button>
  </div>
</aside>

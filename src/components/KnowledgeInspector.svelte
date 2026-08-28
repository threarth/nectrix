<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type {
    DuplicateCandidate,
    EntityIdentifierInput,
    KnowledgeObjectDetail,
    KnowledgeOccurrenceView,
  } from '../lib/api'
  import { inspectorStrings } from '../lib/strings'
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

  const archived = $derived(object.status === 'archived')
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
    />
  {/if}

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

  <button
    type="button"
    class="inspector-lifecycle"
    disabled={busy}
    title={lifecycleAction.description}
    onclick={onToggleArchived}
  >{lifecycleAction.label}</button>
</aside>

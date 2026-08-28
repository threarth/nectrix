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
  } = $props()

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

  <h2 class="inspector-name">{object.name}</h2>
  <p class="inspector-status">
    {inspectorStrings.statusLabel}: {inspectorStrings.objectStatus[object.status] ?? object.status}
  </p>

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

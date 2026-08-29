<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type {
    DuplicateCandidate,
    EntityIdentifierInput,
    KnowledgeObjectDetail,
    SemanticBlock,
    Template,
  } from '../lib/api'
  import { identifierStrings, inspectorStrings } from '../lib/strings'
  import StructuredData from './StructuredData.svelte'

  let {
    object,
    busy,
    duplicateCandidates = [],
    onToggleEntityTypeArchived,
    onAddIdentifier,
    onRemoveIdentifier,
    blocks,
    templates,
    onAddBlock,
    onRemoveBlock,
    onSetValues,
  }: {
    object: KnowledgeObjectDetail
    busy: boolean
    duplicateCandidates?: DuplicateCandidate[]
    onToggleEntityTypeArchived: () => void
    onAddIdentifier: (input: EntityIdentifierInput) => void
    onRemoveIdentifier: (identifierId: string) => void
    /** Structured data of the Entity: blocks of a Template with their typed values. */
    blocks: SemanticBlock[]
    templates: Template[]
    onAddBlock: (templateId: string) => void
    onRemoveBlock: (blockId: string) => void
    onSetValues: (blockId: string, fieldId: string, values: unknown[]) => void
  } = $props()

  let scheme = $state('')
  let value = $state('')
  let authority = $state('')

  const entityTypeArchived = $derived(object.entityType?.status === 'archived')
  const entityTypeAction = $derived(
    entityTypeArchived ? inspectorStrings.restoreEntityType : inspectorStrings.archiveEntityType,
  )

  function submit(): void {
    if (scheme.trim() === '' || value.trim() === '' || busy) return
    const input: EntityIdentifierInput = { scheme: scheme.trim(), value: value.trim() }
    if (authority.trim() !== '') input.authorityOrNamespace = authority.trim()
    onAddIdentifier(input)
    scheme = ''
    value = ''
    authority = ''
  }
</script>

<dl class="inspector-fields">
  <dt>{inspectorStrings.entityType.label}</dt>
  <dd>
    {object.entityType?.name ?? ''}
    {#if entityTypeArchived}
      <span class="inspector-badge">{inspectorStrings.entityType.archived}</span>
    {/if}
  </dd>
  <dt>{inspectorStrings.description.label}</dt>
  <dd class:muted={object.description === null}>
    {object.description ?? inspectorStrings.description.empty}
  </dd>
</dl>

{#if object.entityType}
  <button
    type="button"
    class="inspector-secondary"
    disabled={busy}
    title={entityTypeAction.description}
    onclick={onToggleEntityTypeArchived}
  >{entityTypeAction.label}</button>
{/if}

<section class="inspector-list" aria-label={identifierStrings.label}>
  <h3>{identifierStrings.label} ({object.identifiers.length})</h3>
  {#if object.identifiers.length === 0}
    <p class="muted">{identifierStrings.empty}</p>
  {:else}
    <ul>
      {#each object.identifiers as identifier (identifier.id)}
        <li>
          <span>
            <strong>{identifier.scheme}</strong>
            {identifier.value}
            {#if identifier.authority_or_namespace}
              <small>· {identifier.authority_or_namespace}</small>
            {/if}
            <small>· {identifierStrings.normalizedLabel} {identifier.normalized_value}</small>
          </span>
          <button
            type="button"
            disabled={busy}
            aria-label={`${identifierStrings.remove.description}: ${identifier.value}`}
            title={identifierStrings.remove.description}
            onclick={() => onRemoveIdentifier(identifier.id)}
          >{identifierStrings.remove.label}</button>
        </li>
      {/each}
    </ul>
  {/if}

  {#if duplicateCandidates.length > 0}
    <p class="inspector-duplicates" role="status">
      {identifierStrings.duplicates(duplicateCandidates.map((candidate) => candidate.name))}
    </p>
  {/if}

  <form
    class="inspector-add inspector-add-identifier"
    onsubmit={(event) => {
      event.preventDefault()
      submit()
    }}
  >
    <input bind:value={scheme} aria-label="Scheme" placeholder={identifierStrings.schemePlaceholder} />
    <input bind:value aria-label="Valore" placeholder={identifierStrings.valuePlaceholder} />
    <input bind:value={authority} aria-label="Authority" placeholder={identifierStrings.authorityPlaceholder} />
    <button
      type="submit"
      disabled={scheme.trim() === '' || value.trim() === '' || busy}
      title={identifierStrings.add.description}
    >{identifierStrings.add.label}</button>
  </form>
  <p class="muted inspector-note">{identifierStrings.note}</p>
</section>

<StructuredData {blocks} {templates} {busy} {onAddBlock} {onRemoveBlock} {onSetValues} />

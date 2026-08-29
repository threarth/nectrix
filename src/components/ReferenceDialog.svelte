<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { untrack } from 'svelte'
  import type { KnowledgeSearchResult, SemanticBlock } from '../lib/api'
  import { referenceStrings } from '../lib/strings'
  import Dialog from './Dialog.svelte'

  let {
    initialQuery,
    onSearch,
    onLoadBlocks,
    onCancel,
    onConfirm,
  }: {
    initialQuery: string
    onSearch: (query: string) => Promise<KnowledgeSearchResult[]>
    onLoadBlocks: (entityId: string) => Promise<SemanticBlock[]>
    onCancel: () => void
    /** Either the Entity itself or one of its SemanticBlock becomes the destination. */
    onConfirm: (destination: { kind: 'entityReference' | 'semanticBlockReference'; id: string }) => void
  } = $props()

  const WHOLE_ENTITY = ''

  // The dialog is created fresh at every opening, so the prop is a starting value only.
  let query = $state(untrack(() => initialQuery))
  let results = $state<KnowledgeSearchResult[]>([])
  let selectedEntity = $state('')
  let blocks = $state<SemanticBlock[]>([])
  let selectedBlock = $state(WHOLE_ENTITY)
  let searching = $state(false)
  let searched = $state(false)
  let field = $state<HTMLInputElement | undefined>(undefined)

  $effect(() => {
    field?.focus()
    field?.select()
  })

  async function search(): Promise<void> {
    const text = query.trim()
    if (text === '' || searching) return
    searching = true
    try {
      results = (await onSearch(text)).filter((result) => result.object_type === 'entity')
      searched = true
      await choose(results[0]?.id ?? '')
    } finally {
      searching = false
    }
  }

  async function choose(entityId: string): Promise<void> {
    selectedEntity = entityId
    selectedBlock = WHOLE_ENTITY
    blocks = entityId === '' ? [] : await onLoadBlocks(entityId)
  }
</script>

<Dialog
  title={referenceStrings.dialogTitle}
  hint={referenceStrings.hint}
  confirmLabel={referenceStrings.confirm}
  confirmDisabled={selectedEntity === ''}
  {onCancel}
  onConfirm={() => onConfirm(selectedBlock === WHOLE_ENTITY
    ? { kind: 'entityReference', id: selectedEntity }
    : { kind: 'semanticBlockReference', id: selectedBlock })}
>
  <div class="dialog-field dialog-search">
    <label>
      {referenceStrings.queryLabel}
      <input bind:this={field} bind:value={query} placeholder={referenceStrings.queryPlaceholder} />
    </label>
    <button type="button" disabled={query.trim() === '' || searching} onclick={() => void search()}>
      {searching ? referenceStrings.searching : referenceStrings.search}
    </button>
  </div>

  {#if searched && results.length === 0}
    <p class="dialog-note">{referenceStrings.empty}</p>
  {:else if results.length > 0}
    <ul class="dialog-results">
      {#each results as result (result.id)}
        <li>
          <label class:active={selectedEntity === result.id}>
            <input
              type="radio"
              name="reference-entity"
              value={result.id}
              checked={selectedEntity === result.id}
              onchange={() => void choose(result.id)}
            />
            <span class="dialog-result-name">{result.name}</span>
            {#if result.entity_type_name}
              <small>{result.entity_type_name}</small>
            {/if}
          </label>
        </li>
      {/each}
    </ul>

    <label class="dialog-field">
      {referenceStrings.blockLabel}
      <select bind:value={selectedBlock} disabled={selectedEntity === ''}>
        <option value={WHOLE_ENTITY}>{referenceStrings.wholeEntity}</option>
        {#each blocks as block (block.id)}
          <option value={block.id}>{block.templateName}</option>
        {/each}
      </select>
    </label>
  {/if}
</Dialog>

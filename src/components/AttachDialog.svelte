<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { untrack } from 'svelte'

  import type { KnowledgeSearchResult } from '../lib/api'
  import { attachDialogStrings } from '../lib/strings'
  import Dialog from './Dialog.svelte'

  let {
    initialQuery,
    onSearch,
    onCancel,
    onConfirm,
  }: {
    initialQuery: string
    onSearch: (query: string) => Promise<KnowledgeSearchResult[]>
    onCancel: () => void
    onConfirm: (result: KnowledgeSearchResult) => void
  } = $props()

  // The dialog is created fresh at every opening, so the prop is a starting value only.
  let query = $state(untrack(() => initialQuery))
  let results = $state<KnowledgeSearchResult[]>([])
  let selectedId = $state('')
  let searching = $state(false)
  let searched = $state(false)
  let failure = $state('')
  let field = $state<HTMLInputElement | undefined>(undefined)

  const selected = $derived(results.find((result) => result.id === selectedId) ?? null)

  $effect(() => {
    field?.focus()
    field?.select()
  })

  async function search(): Promise<void> {
    const text = query.trim()
    if (text === '' || searching) return
    searching = true
    failure = ''
    try {
      results = await onSearch(text)
      selectedId = results[0]?.id ?? ''
      searched = true
    } catch (cause) {
      failure = cause instanceof Error ? cause.message : attachDialogStrings.failure
    } finally {
      searching = false
    }
  }
</script>

<Dialog
  title={attachDialogStrings.title}
  hint={attachDialogStrings.hint}
  confirmLabel={attachDialogStrings.confirm}
  confirmDisabled={selected === null}
  {onCancel}
  onConfirm={() => {
    if (selected !== null) onConfirm(selected)
  }}
>
  <div class="dialog-field dialog-search">
    <label>
      {attachDialogStrings.queryLabel}
      <input bind:this={field} bind:value={query} placeholder={attachDialogStrings.queryPlaceholder} />
    </label>
    <button type="button" disabled={query.trim() === '' || searching} onclick={() => void search()}>
      {searching ? attachDialogStrings.searching : attachDialogStrings.search}
    </button>
  </div>

  {#if failure}
    <p class="dialog-note dialog-error">{failure}</p>
  {:else if searched && results.length === 0}
    <p class="dialog-note">{attachDialogStrings.empty}</p>
  {:else if results.length > 0}
    <ul class="dialog-results" aria-label={attachDialogStrings.resultsLabel}>
      {#each results as result (result.id)}
        <li>
          <label class:active={selectedId === result.id}>
            <input type="radio" name="knowledge-result" value={result.id} bind:group={selectedId} />
            <span class="dialog-result-kind">{attachDialogStrings.kind(result.object_type)}</span>
            <span class="dialog-result-name">{result.name}</span>
            {#if result.entity_type_name}
              <small>{result.entity_type_name}</small>
            {/if}
          </label>
        </li>
      {/each}
    </ul>
  {/if}

  <p class="dialog-note">{attachDialogStrings.note}</p>
</Dialog>

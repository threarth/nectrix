<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { untrack } from 'svelte'
  import type { KnowledgeSearchResult } from '../lib/api'
  import { relationStrings } from '../lib/strings'
  import Dialog from './Dialog.svelte'

  let {
    sourceName,
    suggestions,
    onSearch,
    onCancel,
    onConfirm,
  }: {
    sourceName: string
    /** Suggested predicates plus the ones already used: the field stays free. */
    suggestions: string[]
    onSearch: (query: string) => Promise<KnowledgeSearchResult[]>
    onCancel: () => void
    onConfirm: (input: { targetId: string; relationType: string }) => void
  } = $props()

  let query = $state(untrack(() => ''))
  let results = $state<KnowledgeSearchResult[]>([])
  let targetId = $state('')
  let relationType = $state('')
  let searching = $state(false)
  let searched = $state(false)
  let field = $state<HTMLInputElement | undefined>(undefined)

  $effect(() => {
    field?.focus()
  })

  async function search(): Promise<void> {
    const text = query.trim()
    if (text === '' || searching) return
    searching = true
    try {
      results = await onSearch(text)
      targetId = results[0]?.id ?? ''
      searched = true
    } finally {
      searching = false
    }
  }
</script>

<Dialog
  title={relationStrings.dialogTitle}
  hint={`${sourceName} ${relationStrings.outgoing} … — ${relationStrings.hint}`}
  confirmLabel={relationStrings.confirm}
  confirmDisabled={targetId === '' || relationType.trim() === ''}
  {onCancel}
  onConfirm={() => onConfirm({ targetId, relationType: relationType.trim() })}
>
  <label class="dialog-field">
    {relationStrings.predicateLabel}
    <input
      bind:this={field}
      bind:value={relationType}
      list="relation-predicates"
      placeholder={relationStrings.predicatePlaceholder}
    />
    <datalist id="relation-predicates">
      {#each suggestions as suggestion}
        <option value={suggestion}></option>
      {/each}
    </datalist>
  </label>

  <div class="dialog-field dialog-search">
    <label>
      {relationStrings.queryLabel}
      <input bind:value={query} placeholder={relationStrings.queryPlaceholder} />
    </label>
    <button type="button" disabled={query.trim() === '' || searching} onclick={() => void search()}>
      {searching ? relationStrings.searching : relationStrings.search}
    </button>
  </div>

  {#if searched && results.length === 0}
    <p class="dialog-note">{relationStrings.empty_results}</p>
  {:else if results.length > 0}
    <ul class="dialog-results">
      {#each results as result (result.id)}
        <li>
          <label class:active={targetId === result.id}>
            <input type="radio" name="relation-target" value={result.id} bind:group={targetId} />
            <span class="dialog-result-kind">{result.object_type === 'concept' ? 'Concept' : 'Entity'}</span>
            <span class="dialog-result-name">{result.name}</span>
          </label>
        </li>
      {/each}
    </ul>
  {/if}
</Dialog>

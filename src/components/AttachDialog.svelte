<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { onDestroy, untrack } from 'svelte'

  import type { IndexSearchResult } from '../lib/api'
  import { contextPathLabel, orderContexts, type ContextNode } from '../lib/contexts'
  import { attachStrings } from '../lib/strings'
  import Dialog from './Dialog.svelte'

  let {
    initialQuery,
    contexts = [],
    onSearch,
    onCancel,
    onConfirm,
  }: {
    initialQuery: string
    /** Known Context, used to show the path of a result instead of a bare name. */
    contexts?: ContextNode[]
    onSearch: (query: string) => Promise<IndexSearchResult[]>
    onCancel: () => void
    onConfirm: (result: IndexSearchResult) => void
  } = $props()

  /** Wait after the last keystroke: enough to stop typing, short enough to feel immediate. */
  const SEARCH_DELAY_MS = 160

  // The dialog is created fresh at every opening, so the prop is a starting value only.
  let query = $state(untrack(() => initialQuery))
  let results = $state<IndexSearchResult[]>([])
  let selectedId = $state('')
  let searching = $state(false)
  let searched = $state(false)
  let failure = $state('')
  let field = $state<HTMLInputElement | undefined>(undefined)
  let timer: ReturnType<typeof setTimeout> | undefined
  let pending = 0

  const selected = $derived(results.find((result) => result.id === selectedId) ?? null)
  const paths = $derived(new Map(orderContexts(contexts).map((row) => [row.id, contextPathLabel(row)])))

  $effect(() => {
    field?.focus()
    field?.select()
  })

  // Searching while typing: the query is state, so the effect re-runs at every keystroke.
  $effect(() => {
    const text = query.trim()
    clearTimeout(timer)
    if (text === '') {
      results = []
      searched = false
      return
    }
    timer = setTimeout(() => void search(text), SEARCH_DELAY_MS)
  })

  onDestroy(() => clearTimeout(timer))

  /** Keeps only the answer of the last query sent: an older one must not overwrite it. */
  async function search(text: string): Promise<void> {
    const ticket = ++pending
    searching = true
    failure = ''
    try {
      const found = await onSearch(text)
      if (ticket !== pending) return
      results = found
      selectedId = found.some((result) => result.id === selectedId) ? selectedId : found[0]?.id ?? ''
      searched = true
    } catch (cause) {
      if (ticket === pending) failure = cause instanceof Error ? cause.message : attachStrings.failure
    } finally {
      if (ticket === pending) searching = false
    }
  }

  function label(result: IndexSearchResult): string {
    return result.object_type === 'context' ? paths.get(result.id) ?? result.name : result.name
  }
</script>

<Dialog
  title={attachStrings.title}
  hint={attachStrings.hint}
  confirmLabel={attachStrings.confirm}
  confirmDisabled={selected === null}
  {onCancel}
  onConfirm={() => {
    if (selected !== null) onConfirm(selected)
  }}
>
  <div class="dialog-field">
    <label>
      {attachStrings.queryLabel}
      <input bind:this={field} bind:value={query} placeholder={attachStrings.queryPlaceholder} />
    </label>
  </div>

  {#if failure}
    <p class="dialog-note dialog-error">{failure}</p>
  {:else if searching && results.length === 0}
    <p class="dialog-note">{attachStrings.searching}</p>
  {:else if searched && results.length === 0}
    <p class="dialog-note">{attachStrings.empty}</p>
  {:else if results.length > 0}
    <ul class="dialog-results" aria-label={attachStrings.resultsLabel}>
      {#each results as result (result.id)}
        <li>
          <label class:active={selectedId === result.id}>
            <input type="radio" name="index-result" value={result.id} bind:group={selectedId} />
            <span class="dialog-result-kind">{attachStrings.kind(result.object_type)}</span>
            <span class="dialog-result-name">{label(result)}</span>
            {#if result.entity_type_name}
              <small>{result.entity_type_name}</small>
            {/if}
          </label>
        </li>
      {/each}
    </ul>
  {/if}

  <p class="dialog-note">{attachStrings.note}</p>
</Dialog>

<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { SearchResult } from '../lib/api'
  import { searchStrings } from '../lib/strings'

  let {
    results,
    query,
    searching = false,
    onSearch,
    onClear,
    onOpen,
    onShowOccurrences,
  }: {
    results: SearchResult[]
    /** Query the results belong to; empty means the panel is idle. */
    query: string
    searching?: boolean
    onSearch: (query: string) => void
    onClear: () => void
    onOpen: (result: SearchResult) => void
    /** Documents holding an occurrence of the object, reached by identity and not by words. */
    onShowOccurrences: (result: SearchResult) => void
  } = $props()

  let draft = $state('')

  function submit(): void {
    const text = draft.trim()
    if (text === '') return
    onSearch(text)
  }
</script>

<section class="search-panel" aria-label={searchStrings.panelLabel}>
  <form
    onsubmit={(event) => {
      event.preventDefault()
      submit()
    }}
  >
    <input
      bind:value={draft}
      type="search"
      aria-label={searchStrings.panelLabel}
      placeholder={searchStrings.placeholder}
    />
    <button type="submit" disabled={draft.trim() === '' || searching} title={searchStrings.submit.description}>
      {searching ? searchStrings.searching : searchStrings.submit.label}
    </button>
  </form>

  {#if query !== ''}
    <div class="search-results" aria-label={searchStrings.resultsLabel}>
      <div class="search-head">
        <span>{searchStrings.resultsLabel} ({results.length})</span>
        <button
          type="button"
          title={searchStrings.clear.description}
          onclick={() => {
            draft = ''
            onClear()
          }}
        >{searchStrings.clear.label}</button>
      </div>

      {#if results.length === 0}
        <p class="empty-state">{searchStrings.empty}</p>
      {:else}
        <ul>
          {#each results as result (`${result.category}-${result.match}-${result.id}`)}
            <li>
              <button type="button" onclick={() => onOpen(result)}>
                <span class="search-kind">
                  {searchStrings.categories[result.category] ?? result.category}
                  · {searchStrings.matches[result.match] ?? result.match}
                </span>
                <span class="search-label">{result.label}</span>
                {#if result.detail}
                  <small>{result.detail}</small>
                {/if}
              </button>
              {#if result.objectId !== undefined}
                <button
                  type="button"
                  class="search-occurrences"
                  title={searchStrings.occurrencesOf.description}
                  onclick={() => onShowOccurrences(result)}
                >{searchStrings.occurrencesOf.label}</button>
              {/if}
            </li>
          {/each}
        </ul>
      {/if}
    </div>
  {/if}
</section>

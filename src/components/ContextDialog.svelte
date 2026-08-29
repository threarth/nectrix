<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { contextPathLabel, orderContexts, type ContextNode } from '../lib/contexts'
  import { contextDialogStrings } from '../lib/strings'
  import Dialog from './Dialog.svelte'

  let {
    contexts,
    text,
    busy = false,
    error = '',
    onCancel,
    onConfirm,
    onCreate,
  }: {
    contexts: ContextNode[]
    /** The fragment the range will cover, shown so the user sees what is being organised. */
    text: string
    busy?: boolean
    error?: string
    onCancel: () => void
    onConfirm: (contextId: string) => void
    onCreate: (name: string, parentId: string | null) => Promise<ContextNode>
  } = $props()

  let selectedId = $state('')
  let newName = $state('')
  let creating = $state(false)
  let failure = $state('')

  const rows = $derived(orderContexts(contexts))
  const parentId = $derived(selectedId === '' ? null : selectedId)

  /** Creates the Context on the spot: the index grows while reading, not before. */
  async function create(): Promise<void> {
    const name = newName.trim()
    if (name === '' || creating) return
    creating = true
    failure = ''
    try {
      const created = await onCreate(name, parentId)
      selectedId = created.id
      newName = ''
    } catch (cause) {
      failure = cause instanceof Error ? cause.message : contextDialogStrings.createFailure
    } finally {
      creating = false
    }
  }
</script>

<Dialog
  title={contextDialogStrings.title}
  hint={contextDialogStrings.hint}
  confirmLabel={contextDialogStrings.confirm}
  confirmDisabled={selectedId === '' || busy}
  {busy}
  {error}
  {onCancel}
  onConfirm={() => {
    if (selectedId !== '') onConfirm(selectedId)
  }}
>
  <p class="dialog-note">{contextDialogStrings.fragment(text)}</p>

  {#if rows.length === 0}
    <p class="dialog-note">{contextDialogStrings.empty}</p>
  {:else}
    <ul class="dialog-results" aria-label={contextDialogStrings.listLabel}>
      {#each rows as row (row.id)}
        <li>
          <label class:active={selectedId === row.id}>
            <input type="radio" name="context-choice" value={row.id} bind:group={selectedId} />
            <span class="dialog-result-name">{contextPathLabel(row)}</span>
            {#if (row.occurrences ?? 0) > 0}
              <small>{contextDialogStrings.rangeCount(row.occurrences ?? 0)}</small>
            {/if}
          </label>
        </li>
      {/each}
    </ul>
  {/if}

  <div class="dialog-field dialog-search">
    <label>
      {contextDialogStrings.newLabel}
      <input bind:value={newName} placeholder={contextDialogStrings.newPlaceholder} />
    </label>
    <button type="button" disabled={newName.trim() === '' || creating} onclick={() => void create()}>
      {creating ? contextDialogStrings.creating : contextDialogStrings.create}
    </button>
  </div>

  {#if failure}
    <p class="dialog-note dialog-error">{failure}</p>
  {:else}
    <p class="dialog-note">{selectedId === '' ? contextDialogStrings.newRootNote : contextDialogStrings.newChildNote}</p>
  {/if}
</Dialog>

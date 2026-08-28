<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { ContextMode } from '../lib/api'
  import { contextPathLabel, orderContexts, possibleParents, type ContextNode } from '../lib/contexts'
  import { contextStrings } from '../lib/strings'
  import NameDialog from './NameDialog.svelte'

  let {
    contexts,
    selectedId,
    mode,
    busy = false,
    onSelect,
    onModeChange,
    onCreate,
    onRename,
    onMove,
    onDelete,
  }: {
    contexts: ContextNode[]
    selectedId: string | null
    mode: ContextMode
    busy?: boolean
    onSelect: (contextId: string | null) => void
    onModeChange: (mode: ContextMode) => void
    onCreate: (name: string, parentId: string | null) => void
    onRename: (contextId: string, name: string) => void
    onMove: (contextId: string, parentId: string | null) => void
    onDelete: (contextId: string) => void
  } = $props()

  const rows = $derived(orderContexts(contexts))
  const selected = $derived(rows.find((row) => row.id === selectedId) ?? null)
  const parents = $derived(selectedId === null ? [] : possibleParents(rows, selectedId))

  let naming = $state<'create' | 'rename' | null>(null)
</script>

<section class="context-panel" aria-label={contextStrings.panelLabel}>
  <h2>{contextStrings.panelLabel}</h2>

  <button
    type="button"
    class="context-row"
    class:active={selectedId === null}
    title={contextStrings.all.description}
    onclick={() => onSelect(null)}
  >{contextStrings.all.label}</button>

  {#if rows.length === 0}
    <p class="empty-state">{contextStrings.empty}</p>
  {:else}
    {#each rows as row (row.id)}
      <button
        type="button"
        class="context-row"
        class:active={selectedId === row.id}
        style={`padding-left: ${12 + row.depth * 14}px`}
        title={contextPathLabel(row)}
        onclick={() => onSelect(row.id)}
      >{row.name}</button>
    {/each}
  {/if}

  {#if selectedId !== null}
    <div class="context-mode" role="group" aria-label={contextStrings.modeLabel}>
      {#each contextStrings.modes as option}
        <button
          type="button"
          class:active={mode === option.value}
          aria-pressed={mode === option.value}
          title={option.description}
          onclick={() => onModeChange(option.value)}
        >{option.label}</button>
      {/each}
    </div>
  {/if}

  <div class="context-actions">
    <button type="button" disabled={busy} title={contextStrings.create.description} onclick={() => (naming = 'create')}>
      {contextStrings.create.label}
    </button>
    <button type="button" disabled={busy || selected === null} title={contextStrings.rename.description} onclick={() => (naming = 'rename')}>
      {contextStrings.rename.label}
    </button>
    <button
      type="button"
      disabled={busy || selected === null}
      title={contextStrings.remove.description}
      onclick={() => selected !== null && onDelete(selected.id)}
    >{contextStrings.remove.label}</button>
  </div>

  {#if selected !== null}
    <label class="context-move" title={contextStrings.move.description}>
      {contextStrings.move.label}
      <select
        value={selected.parent_id ?? ''}
        disabled={busy}
        onchange={(event) => onMove(selected.id, event.currentTarget.value === '' ? null : event.currentTarget.value)}
      >
        <option value="">{contextStrings.moveToRoot}</option>
        {#each parents as parent (parent.id)}
          <option value={parent.id}>{contextPathLabel(parent)}</option>
        {/each}
      </select>
    </label>
  {/if}
</section>

{#if naming === 'create'}
  <NameDialog
    title={contextStrings.create.dialogTitle}
    hint={selected === null ? contextStrings.create.rootHint : contextStrings.create.childHint(selected.name)}
    label={contextStrings.create.nameLabel}
    placeholder={contextStrings.create.placeholder}
    confirmLabel={contextStrings.create.label}
    onCancel={() => (naming = null)}
    onConfirm={(name) => {
      onCreate(name, selectedId)
      naming = null
    }}
  />
{:else if naming === 'rename' && selected !== null}
  <NameDialog
    title={contextStrings.rename.dialogTitle}
    hint={contextStrings.rename.description}
    label={contextStrings.create.nameLabel}
    initialValue={selected.name}
    confirmLabel={contextStrings.rename.label}
    onCancel={() => (naming = null)}
    onConfirm={(name) => {
      onRename(selected.id, name)
      naming = null
    }}
  />
{/if}

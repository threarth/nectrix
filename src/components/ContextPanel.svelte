<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { ContextMode } from '../lib/api'
  import {
    contextPathLabel,
    deletionBlockers,
    deletionImpact,
    orderContexts,
    possibleParents,
    treeRows,
    type ContextNode,
    type ContextObjectRow,
  } from '../lib/contexts'
  import { contextStrings, trashStrings } from '../lib/strings'
  import { SvelteSet } from 'svelte/reactivity'
  import NameDialog from './NameDialog.svelte'
  import RemoveButton from './RemoveButton.svelte'

  let {
    contexts,
    objects = [],
    selectedId,
    mode,
    busy = false,
    onSelect,
    onPreview,
    onModeChange,
    onCreate,
    onRename,
    onMove,
    onDelete,
    onOpenObject,
    onTrashObject,
  }: {
    contexts: ContextNode[]
    /** Concept and Entity contained in the ranges of each Context: the branches of the tree. */
    objects?: ContextObjectRow[]
    selectedId: string | null
    mode: ContextMode
    busy?: boolean
    onSelect: (contextId: string | null) => void
    /** Shows the Documents that carry the ranges of this Context, as thumbnails. */
    onPreview: (contextId: string) => void
    onModeChange: (mode: ContextMode) => void
    onCreate: (name: string, parentId: string | null) => void
    onRename: (contextId: string, name: string) => void
    onMove: (contextId: string, parentId: string | null) => void
    onDelete: (contextId: string) => void
    onOpenObject: (objectId: string) => void
    onTrashObject: (objectId: string) => void
  } = $props()

  const rows = $derived(orderContexts(contexts))
  const selected = $derived(rows.find((row) => row.id === selectedId) ?? null)
  const parents = $derived(selectedId === null ? [] : possibleParents(rows, selectedId))
  const blocked = $derived(selectedId === null ? null : deletionBlockers(rows, selectedId))
  const impact = $derived(selectedId === null ? 0 : deletionImpact(rows, selectedId))

  let naming = $state<'create' | 'rename' | null>(null)
  let collapsed = $state(new SvelteSet<string>())

  const tree = $derived(treeRows(rows, objects, collapsed))

  function toggle(contextId: string): void {
    if (collapsed.has(contextId)) collapsed.delete(contextId)
    else collapsed.add(contextId)
  }

  function blockedFor(contextId: string): boolean {
    return deletionBlockers(rows, contextId) !== null
  }
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
    {#each tree as row (`${row.kind}:${row.id}:${row.depth}`)}
      {#if row.kind === 'context'}
        <div class="context-line" style={`padding-left: ${row.depth * 12}px`}>
          <button
            type="button"
            class="context-twist"
            aria-label={collapsed.has(row.id) ? contextStrings.tree.expand(row.name) : contextStrings.tree.collapse(row.name)}
            aria-expanded={!collapsed.has(row.id)}
            disabled={!row.hasChildren}
            onclick={() => toggle(row.id)}
          >{row.hasChildren ? (collapsed.has(row.id) ? '▸' : '▾') : '·'}</button>
          <button
            type="button"
            class="context-row"
            class:active={selectedId === row.id}
            title={contextStrings.tree.contextDescription}
            aria-label={row.occurrences > 0
              ? `${row.name}, ${contextStrings.rangeCount(row.occurrences)}`
              : row.name}
            onclick={() => {
              onSelect(row.id)
              if (row.occurrences > 0) onPreview(row.id)
            }}
          >{row.name}{#if row.occurrences > 0}<small>{row.occurrences}</small>{/if}</button>
          <RemoveButton
            label={row.name}
            disabled={busy || blockedFor(row.id)}
            description={trashStrings.trashObject.description}
            confirmDescription={trashStrings.trashObjectConfirm}
            onRemove={() => onDelete(row.id)}
          />
        </div>
      {:else}
        <div class="context-line context-object" style={`padding-left: ${row.depth * 12}px`}>
          <span class="context-twist" aria-hidden="true">{row.objectType === 'concept' ? '◆' : '■'}</span>
          <button
            type="button"
            class="context-row"
            title={contextStrings.tree.objectDescription}
            aria-label={`${trashStrings.kind(row.objectType)} ${row.name}`}
            onclick={() => onOpenObject(row.id)}
          >{row.name}</button>
          <RemoveButton
            label={row.name}
            disabled={busy}
            description={trashStrings.trashObject.description}
            confirmDescription={trashStrings.trashObjectConfirm}
            onRemove={() => onTrashObject(row.id)}
          />
        </div>
      {/if}
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
      disabled={busy || selected === null || blocked !== null}
      title={blocked === null
        ? contextStrings.remove.impact(impact)
        : contextStrings.remove.blocked(blocked.children)}
      onclick={() => selected !== null && onDelete(selected.id)}
    >{contextStrings.remove.label}</button>
  </div>

  {#if blocked !== null}
    <p class="tag-note">{contextStrings.remove.blocked(blocked.children)}</p>
  {:else if selected !== null}
    <p class="tag-note">{contextStrings.remove.impact(impact)}</p>
  {/if}

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

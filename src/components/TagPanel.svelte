<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { TagSummary } from '../lib/api'
  import { contextStrings, tagStrings } from '../lib/strings'
  import NameDialog from './NameDialog.svelte'

  let {
    tags,
    selectedIds,
    busy = false,
    onToggle,
    onCreate,
    onRename,
    onDelete,
  }: {
    tags: TagSummary[]
    /** Selected tags filter the documents and are the target of rename and delete. */
    selectedIds: string[]
    busy?: boolean
    onToggle: (tagId: string) => void
    onCreate: (name: string) => void
    onRename: (tagId: string, name: string) => void
    onDelete: (tagId: string) => void
  } = $props()

  let naming = $state<'create' | 'rename' | null>(null)

  const only = $derived(selectedIds.length === 1 ? tags.find((tag) => tag.id === selectedIds[0]) ?? null : null)
</script>

<section class="tag-panel" aria-label={tagStrings.panelLabel}>
  <h2>{tagStrings.panelLabel}</h2>

  {#if tags.length === 0}
    <p class="empty-state">{tagStrings.empty}</p>
  {:else}
    <div class="tag-chips">
      {#each tags as tag (tag.id)}
        <button
          type="button"
          class:active={selectedIds.includes(tag.id)}
          aria-pressed={selectedIds.includes(tag.id)}
          title={tagStrings.filterDescription}
          aria-label={`${tag.name}, ${contextStrings.documentCount(tag.documents)}`}
          onclick={() => onToggle(tag.id)}
        >{tag.name} <small>{tag.documents}</small></button>
      {/each}
    </div>
  {/if}

  {#if selectedIds.length > 1}
    <p class="tag-note">{tagStrings.allRequired}</p>
  {/if}

  <div class="context-actions">
    <button type="button" disabled={busy} title={tagStrings.create.description} onclick={() => (naming = 'create')}>
      {tagStrings.create.label}
    </button>
    <button
      type="button"
      disabled={busy || only === null}
      title={tagStrings.rename.description}
      onclick={() => (naming = 'rename')}
    >{tagStrings.rename.label}</button>
    <button
      type="button"
      disabled={busy || only === null || (only?.documents ?? 0) > 0}
      title={only !== null && only.documents > 0
        ? tagStrings.remove.blocked(only.documents)
        : tagStrings.remove.description}
      onclick={() => only !== null && onDelete(only.id)}
    >{tagStrings.remove.label}</button>
  </div>

  {#if only !== null && only.documents > 0}
    <p class="tag-note">{tagStrings.remove.blocked(only.documents)}</p>
  {/if}
</section>

{#if naming === 'create'}
  <NameDialog
    title={tagStrings.create.dialogTitle}
    hint={tagStrings.create.hint}
    label={tagStrings.nameLabel}
    placeholder={tagStrings.placeholder}
    confirmLabel={tagStrings.create.label}
    onCancel={() => (naming = null)}
    onConfirm={(name) => {
      onCreate(name)
      naming = null
    }}
  />
{:else if naming === 'rename' && only !== null}
  <NameDialog
    title={tagStrings.rename.dialogTitle}
    hint={tagStrings.rename.description}
    label={tagStrings.nameLabel}
    initialValue={only.name}
    confirmLabel={tagStrings.rename.label}
    onCancel={() => (naming = null)}
    onConfirm={(name) => {
      onRename(only.id, name)
      naming = null
    }}
  />
{/if}

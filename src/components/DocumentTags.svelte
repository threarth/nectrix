<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { Tag, TagSummary } from '../lib/api'
  import { tagStrings } from '../lib/strings'
  import NameDialog from './NameDialog.svelte'

  let {
    tags,
    available,
    disabled = false,
    onAssign,
    onUnassign,
    onCreateAndAssign,
  }: {
    /** Tags currently on the Document. */
    tags: Tag[]
    /** Every existing Tag, to offer the ones not yet assigned. */
    available: TagSummary[]
    disabled?: boolean
    onAssign: (tagId: string) => void
    onUnassign: (tagId: string) => void
    onCreateAndAssign: (name: string) => void
  } = $props()

  const NEW_TAG = 'new'

  let naming = $state(false)

  const assignable = $derived(available.filter((tag) => !tags.some((assigned) => assigned.id === tag.id)))
</script>

<div class="document-tags" aria-label={tagStrings.documentLabel}>
  <span class="document-tags-label">{tagStrings.documentLabel}</span>

  {#if tags.length === 0}
    <span class="muted">{tagStrings.none}</span>
  {:else}
    {#each tags as tag (tag.id)}
      <span class="document-tag">
        {tag.name}
        <button
          type="button"
          {disabled}
          aria-label={`${tagStrings.removeFromDocument}: ${tag.name}`}
          title={tagStrings.removeFromDocument}
          onclick={() => onUnassign(tag.id)}
        >×</button>
      </span>
    {/each}
  {/if}

  <select
    value=""
    {disabled}
    aria-label={tagStrings.addToDocument}
    title={tagStrings.addToDocument}
    onchange={(event) => {
      const value = event.currentTarget.value
      event.currentTarget.value = ''
      if (value === NEW_TAG) naming = true
      else if (value !== '') onAssign(value)
    }}
  >
    <option value="">{tagStrings.addToDocument}</option>
    {#each assignable as tag (tag.id)}
      <option value={tag.id}>{tag.name}</option>
    {/each}
    <option value={NEW_TAG}>{tagStrings.create.dialogTitle}…</option>
  </select>
</div>

{#if naming}
  <NameDialog
    title={tagStrings.create.dialogTitle}
    hint={tagStrings.create.hint}
    label={tagStrings.nameLabel}
    placeholder={tagStrings.placeholder}
    confirmLabel={tagStrings.create.label}
    onCancel={() => (naming = false)}
    onConfirm={(name) => {
      onCreateAndAssign(name)
      naming = false
    }}
  />
{/if}

<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { TrashContents } from '../lib/api'
  import { trashStrings } from '../lib/strings'
  import RemoveButton from './RemoveButton.svelte'

  let {
    contents,
    busy = false,
    onPreviewObject,
    onPreviewContext,
    onRestoreObject,
    onRestoreContext,
    onPurgeObject,
    onPurgeContext,
  }: {
    contents: TrashContents
    busy?: boolean
    /** Shows what the trashed object was: its fragments are still in the text. */
    onPreviewObject: (objectId: string) => void
    onPreviewContext: (contextId: string) => void
    onRestoreObject: (objectId: string) => void
    onRestoreContext: (contextId: string) => void
    onPurgeObject: (objectId: string) => void
    onPurgeContext: (contextId: string) => void
  } = $props()

  const empty = $derived(contents.knowledgeObjects.length === 0 && contents.contexts.length === 0)
</script>

<section class="trash-panel" aria-label={trashStrings.panelLabel}>
  <h2>{trashStrings.panelLabel}</h2>

  {#if empty}
    <p class="empty-state">{trashStrings.empty}</p>
  {:else}
    <p class="tag-note">{trashStrings.hint}</p>
    <ul>
      {#each contents.knowledgeObjects as item (item.id)}
        <li>
          <span class="trash-kind">{trashStrings.kind(item.object_type ?? 'concept')}</span>
          <button
            type="button"
            class="trash-name"
            title={trashStrings.preview.description}
            onclick={() => onPreviewObject(item.id)}
          >{item.name}</button>
          <small>{trashStrings.ranges(item.occurrences)}</small>
          <button
            type="button"
            disabled={busy}
            title={trashStrings.restore.description}
            onclick={() => onRestoreObject(item.id)}
          >{trashStrings.restore.label}</button>
          <RemoveButton
            label={item.name}
            disabled={busy}
            description={trashStrings.purge.description}
            confirmDescription={trashStrings.purge.confirm}
            onRemove={() => onPurgeObject(item.id)}
          />
        </li>
      {/each}
      {#each contents.contexts as item (item.id)}
        <li>
          <span class="trash-kind">{trashStrings.kind('context')}</span>
          <button
            type="button"
            class="trash-name"
            title={trashStrings.preview.description}
            onclick={() => onPreviewContext(item.id)}
          >{item.name}</button>
          <small>{trashStrings.ranges(item.occurrences)}</small>
          <button
            type="button"
            disabled={busy}
            title={trashStrings.restore.description}
            onclick={() => onRestoreContext(item.id)}
          >{trashStrings.restore.label}</button>
          <RemoveButton
            label={item.name}
            disabled={busy}
            description={trashStrings.purge.description}
            confirmDescription={trashStrings.purge.confirm}
            onRemove={() => onPurgeContext(item.id)}
          />
        </li>
      {/each}
    </ul>
  {/if}
</section>

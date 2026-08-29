<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { contextStrings, trashStrings } from '../lib/strings'
  import RemoveButton from './RemoveButton.svelte'

  let {
    objects,
    busy = false,
    onOpen,
    onTrash,
  }: {
    /** Concept and Entity of the Documents selected by the Context and Tag filters. */
    objects: { id: string; object_type: 'concept' | 'entity'; name: string }[]
    busy?: boolean
    onOpen: (objectId: string) => void
    onTrash: (objectId: string) => void
  } = $props()
</script>

<section class="context-derived" aria-label={contextStrings.derived.label} title={contextStrings.derived.description}>
  <h2>{contextStrings.derived.label} ({objects.length})</h2>
  {#if objects.length === 0}
    <p class="empty-state">{contextStrings.derived.empty}</p>
  {:else}
    <ul>
      {#each objects as object (object.id)}
        <li>
          <button type="button" class="derived-open" onclick={() => onOpen(object.id)}>
            <span>{trashStrings.kind(object.object_type)}</span> {object.name}
          </button>
          <RemoveButton
            label={object.name}
            disabled={busy}
            description={trashStrings.trashObject.description}
            confirmDescription={trashStrings.trashObjectConfirm}
            onRemove={() => onTrash(object.id)}
          />
        </li>
      {/each}
    </ul>
  {/if}
</section>

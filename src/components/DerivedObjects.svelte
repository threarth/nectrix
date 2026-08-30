<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { contextStrings, previewStrings, trashStrings } from '../lib/strings'
  import RemoveButton from './RemoveButton.svelte'

  let {
    objects,
    busy = false,
    onOpen,
    onInspect,
    onTrash,
  }: {
    /** Concept and Entity of the Documents selected by the Context and Tag filters. */
    objects: { id: string; object_type: 'concept' | 'entity'; name: string }[]
    busy?: boolean
    /** Shows the Documents that contain it, as thumbnails. */
    onOpen: (objectId: string) => void
    /** Opens the detail panel of the object itself. */
    onInspect: (objectId: string) => void
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
          <button
            type="button"
            class="derived-open"
            title={previewStrings.showLabel.description}
            onclick={() => onOpen(object.id)}
          >
            <span>{trashStrings.kind(object.object_type)}</span> {object.name}
          </button>
          <button
            type="button"
            class="derived-inspect"
            aria-label={`Dettaglio di ${object.name}`}
            title={contextStrings.derived.inspect}
            onclick={() => onInspect(object.id)}
          >›</button>
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

<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { contextStrings } from '../lib/strings'

  let {
    objects,
  }: {
    /** Concept and Entity of the Documents selected by the Context and Tag filters. */
    objects: { id: string; object_type: 'concept' | 'entity'; name: string }[]
  } = $props()
</script>

<section class="context-derived" aria-label={contextStrings.derived.label} title={contextStrings.derived.description}>
  <h2>{contextStrings.derived.label} ({objects.length})</h2>
  {#if objects.length === 0}
    <p class="empty-state">{contextStrings.derived.empty}</p>
  {:else}
    <ul>
      {#each objects as object (object.id)}
        <li><span>{object.object_type === 'concept' ? 'Concept' : 'Entity'}</span> {object.name}</li>
      {/each}
    </ul>
  {/if}
</section>

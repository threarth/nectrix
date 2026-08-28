<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { KnowledgeObjectDetail } from '../lib/api'
  import { inspectorStrings } from '../lib/strings'

  let {
    object,
    busy,
    onToggleEntityTypeArchived,
  }: {
    object: KnowledgeObjectDetail
    busy: boolean
    onToggleEntityTypeArchived: () => void
  } = $props()

  const entityTypeArchived = $derived(object.entityType?.status === 'archived')
  const entityTypeAction = $derived(
    entityTypeArchived ? inspectorStrings.restoreEntityType : inspectorStrings.archiveEntityType,
  )
</script>

<dl class="inspector-fields">
  <dt>{inspectorStrings.entityType.label}</dt>
  <dd>
    {object.entityType?.name ?? ''}
    {#if entityTypeArchived}
      <span class="inspector-badge">{inspectorStrings.entityType.archived}</span>
    {/if}
  </dd>
  <dt>{inspectorStrings.description.label}</dt>
  <dd class:muted={object.description === null}>
    {object.description ?? inspectorStrings.description.empty}
  </dd>
</dl>

{#if object.entityType}
  <button
    type="button"
    class="inspector-secondary"
    disabled={busy}
    title={entityTypeAction.description}
    onclick={onToggleEntityTypeArchived}
  >{entityTypeAction.label}</button>
{/if}

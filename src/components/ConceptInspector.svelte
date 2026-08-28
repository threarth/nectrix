<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { KnowledgeObjectDetail } from '../lib/api'
  import { aliasStrings, inspectorStrings } from '../lib/strings'

  let {
    object,
    busy = false,
    onAddAlias,
    onRemoveAlias,
  }: {
    object: KnowledgeObjectDetail
    busy?: boolean
    onAddAlias: (alias: string) => void
    onRemoveAlias: (aliasId: string) => void
  } = $props()

  let draft = $state('')

  function submit(): void {
    const alias = draft.trim()
    if (alias === '' || busy) return
    onAddAlias(alias)
    draft = ''
  }
</script>

<dl class="inspector-fields">
  <dt>{inspectorStrings.description.label}</dt>
  <dd class:muted={object.description === null}>
    {object.description ?? inspectorStrings.description.empty}
  </dd>
</dl>

<section class="inspector-list" aria-label={aliasStrings.label}>
  <h3>{aliasStrings.label} ({object.aliases.length})</h3>
  {#if object.aliases.length === 0}
    <p class="muted">{aliasStrings.empty}</p>
  {:else}
    <ul>
      {#each object.aliases as alias (alias.id)}
        <li>
          <span>{alias.alias}</span>
          <button
            type="button"
            disabled={busy}
            aria-label={`${aliasStrings.remove.description}: ${alias.alias}`}
            title={aliasStrings.remove.description}
            onclick={() => onRemoveAlias(alias.id)}
          >{aliasStrings.remove.label}</button>
        </li>
      {/each}
    </ul>
  {/if}

  <form
    class="inspector-add"
    onsubmit={(event) => {
      event.preventDefault()
      submit()
    }}
  >
    <input bind:value={draft} aria-label={aliasStrings.add.label} placeholder={aliasStrings.placeholder} />
    <button type="submit" disabled={draft.trim() === '' || busy} title={aliasStrings.add.description}>
      {aliasStrings.add.label}
    </button>
  </form>
  <p class="muted inspector-note">{aliasStrings.note}</p>
</section>

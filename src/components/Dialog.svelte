<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { Snippet } from 'svelte'
  import { dialogStrings } from '../lib/strings'

  let {
    title,
    hint = '',
    confirmLabel,
    confirmDisabled = false,
    busy = false,
    error = '',
    onCancel,
    onConfirm,
    children,
  }: {
    title: string
    /** One line explaining what the command will do, shown under the title. */
    hint?: string
    confirmLabel: string
    confirmDisabled?: boolean
    busy?: boolean
    /** Reason the last confirmation failed, shown without closing the dialog. */
    error?: string
    onCancel: () => void
    onConfirm: () => void
    children: Snippet
  } = $props()

  function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.preventDefault()
      onCancel()
    }
  }
</script>

<svelte:window onkeydown={handleKeydown} />

<div class="dialog-backdrop" role="dialog" aria-modal="true" aria-label={title}>
  <form
    class="dialog"
    onsubmit={(event) => {
      event.preventDefault()
      if (!confirmDisabled && !busy) onConfirm()
    }}
  >
    <h2>{title}</h2>
    {#if hint}
      <p class="dialog-hint">{hint}</p>
    {/if}

    {@render children()}

    {#if error}
      <p class="dialog-note dialog-error" role="alert">{error}</p>
    {/if}

    <div class="dialog-actions">
      <button type="button" class="dialog-cancel" onclick={onCancel}>{dialogStrings.cancel}</button>
      <button type="submit" class="dialog-confirm" disabled={confirmDisabled || busy}>
        {busy ? dialogStrings.working : confirmLabel}
      </button>
    </div>
  </form>
</div>

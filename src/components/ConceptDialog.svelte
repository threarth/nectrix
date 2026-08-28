<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { untrack } from 'svelte'

  import { conceptDialogStrings } from '../lib/strings'
  import Dialog from './Dialog.svelte'

  let {
    initialName,
    onCancel,
    onConfirm,
  }: {
    initialName: string
    onCancel: () => void
    onConfirm: (name: string) => void
  } = $props()

  // The dialog is created fresh at every opening, so the prop is a starting value only.
  let name = $state(untrack(() => initialName))
  let field = $state<HTMLInputElement | undefined>(undefined)

  $effect(() => {
    field?.focus()
    field?.select()
  })
</script>

<Dialog
  title={conceptDialogStrings.title}
  hint={conceptDialogStrings.hint}
  confirmLabel={conceptDialogStrings.confirm}
  confirmDisabled={name.trim() === ''}
  {onCancel}
  onConfirm={() => onConfirm(name.trim())}
>
  <label class="dialog-field">
    {conceptDialogStrings.nameLabel}
    <input bind:this={field} bind:value={name} placeholder={conceptDialogStrings.namePlaceholder} />
  </label>
  <p class="dialog-note">{conceptDialogStrings.note}</p>
</Dialog>

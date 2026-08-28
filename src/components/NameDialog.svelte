<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { untrack } from 'svelte'
  import Dialog from './Dialog.svelte'

  let {
    title,
    hint = '',
    label,
    placeholder = '',
    initialValue = '',
    confirmLabel,
    onCancel,
    onConfirm,
  }: {
    title: string
    hint?: string
    label: string
    placeholder?: string
    initialValue?: string
    confirmLabel: string
    onCancel: () => void
    onConfirm: (value: string) => void
  } = $props()

  // The dialog is created fresh at every opening, so the prop is a starting value only.
  let value = $state(untrack(() => initialValue))
  let field = $state<HTMLInputElement | undefined>(undefined)

  $effect(() => {
    field?.focus()
    field?.select()
  })
</script>

<Dialog
  {title}
  {hint}
  {confirmLabel}
  confirmDisabled={value.trim() === ''}
  {onCancel}
  onConfirm={() => onConfirm(value.trim())}
>
  <label class="dialog-field">
    {label}
    <input bind:this={field} bind:value {placeholder} />
  </label>
</Dialog>

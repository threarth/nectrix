<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { structuredStrings } from '../lib/strings'
  import Dialog from './Dialog.svelte'

  let {
    templateName,
    onCancel,
    onConfirm,
  }: {
    templateName: string
    onCancel: () => void
    onConfirm: (input: { name: string; fieldType: string; required: boolean; options?: string[] }) => void
  } = $props()

  const OPTION_TYPES = ['enum', 'multi_enum']

  let name = $state('')
  let fieldType = $state('text')
  let required = $state(false)
  let options = $state('')
  let field = $state<HTMLInputElement | undefined>(undefined)

  const needsOptions = $derived(OPTION_TYPES.includes(fieldType))
  const parsedOptions = $derived(
    options.split(',').map((option) => option.trim()).filter((option) => option !== ''),
  )

  $effect(() => {
    field?.focus()
  })
</script>

<Dialog
  title={structuredStrings.addField.dialogTitle}
  hint={templateName}
  confirmLabel={structuredStrings.addField.label}
  confirmDisabled={name.trim() === '' || (needsOptions && parsedOptions.length === 0)}
  {onCancel}
  onConfirm={() => onConfirm({
    name: name.trim(),
    fieldType,
    required,
    ...(needsOptions ? { options: parsedOptions } : {}),
  })}
>
  <label class="dialog-field">
    {structuredStrings.addField.nameLabel}
    <input bind:this={field} bind:value={name} placeholder={structuredStrings.addField.placeholder} />
  </label>

  <label class="dialog-field">
    Tipo
    <select bind:value={fieldType}>
      {#each structuredStrings.fieldTypes as type}
        <option value={type.value}>{type.label}</option>
      {/each}
    </select>
  </label>

  {#if needsOptions}
    <label class="dialog-field">
      {structuredStrings.optionsLabel}
      <input bind:value={options} placeholder="Europa, Asia, America" />
    </label>
  {/if}

  <label class="dialog-checkbox">
    <input type="checkbox" bind:checked={required} />
    {structuredStrings.required}
  </label>
</Dialog>

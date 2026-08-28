<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { untrack } from 'svelte'

  import type { EntityType } from '../lib/api'
  import { entityDialogStrings } from '../lib/strings'
  import Dialog from './Dialog.svelte'

  let {
    initialName,
    entityTypes,
    busy = false,
    error = '',
    onCancel,
    onConfirm,
  }: {
    initialName: string
    entityTypes: EntityType[]
    busy?: boolean
    error?: string
    onCancel: () => void
    /** The type is chosen among the active ones, or created by name when none fits. */
    onConfirm: (name: string, entityTypeName: string) => void
  } = $props()

  const NEW_TYPE = ''

  // The dialog is created fresh at every opening, so the prop is a starting value only.
  let name = $state(untrack(() => initialName))
  let selectedType = $state(NEW_TYPE)
  let newTypeName = $state('')
  let field = $state<HTMLInputElement | undefined>(undefined)

  const available = $derived(entityTypes.filter((entityType) => entityType.status === 'active'))
  const typeName = $derived(selectedType === NEW_TYPE ? newTypeName.trim() : selectedType)
  const archived = $derived(entityTypes.length - available.length)

  $effect(() => {
    field?.focus()
    field?.select()
  })
</script>

<Dialog
  title={entityDialogStrings.title}
  hint={entityDialogStrings.hint}
  confirmLabel={entityDialogStrings.confirm}
  confirmDisabled={name.trim() === '' || typeName === ''}
  {busy}
  {error}
  {onCancel}
  onConfirm={() => onConfirm(name.trim(), typeName)}
>
  <label class="dialog-field">
    {entityDialogStrings.nameLabel}
    <input bind:this={field} bind:value={name} placeholder={entityDialogStrings.namePlaceholder} />
  </label>

  <label class="dialog-field">
    {entityDialogStrings.typeLabel}
    <select bind:value={selectedType}>
      <option value={NEW_TYPE}>{entityDialogStrings.newType}</option>
      {#each available as entityType (entityType.id)}
        <option value={entityType.name}>{entityType.name}</option>
      {/each}
    </select>
  </label>

  {#if selectedType === NEW_TYPE}
    <label class="dialog-field">
      {entityDialogStrings.newTypeLabel}
      <input bind:value={newTypeName} placeholder={entityDialogStrings.newTypePlaceholder} />
    </label>
  {/if}

  <p class="dialog-note">
    {entityDialogStrings.note}
    {#if archived > 0}
      {entityDialogStrings.archivedNote(archived)}
    {/if}
  </p>
</Dialog>

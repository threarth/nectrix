<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { Template } from '../lib/api'
  import { structuredStrings } from '../lib/strings'
  import FieldDialog from './FieldDialog.svelte'
  import NameDialog from './NameDialog.svelte'

  let {
    templates,
    busy = false,
    onCreate,
    onAddField,
  }: {
    templates: Template[]
    busy?: boolean
    onCreate: (name: string) => void
    onAddField: (templateId: string, input: { name: string; fieldType: string; required: boolean; options?: string[] }) => void
  } = $props()

  let selectedId = $state<string | null>(null)
  let creating = $state(false)
  let addingField = $state(false)

  const selected = $derived(templates.find((template) => template.id === selectedId) ?? null)
</script>

<section class="template-panel" aria-label="Template">
  <h2>Template</h2>

  {#if templates.length === 0}
    <p class="empty-state">{structuredStrings.noTemplates}</p>
  {:else}
    {#each templates as template (template.id)}
      <button
        type="button"
        class="context-row"
        class:active={selectedId === template.id}
        onclick={() => (selectedId = selectedId === template.id ? null : template.id)}
      >{template.name}<small>{template.fields.length}</small></button>
    {/each}
  {/if}

  {#if selected !== null}
    <ul class="template-fields">
      {#each selected.fields as field (field.id)}
        <li>{field.name} <small>{field.field_type}</small></li>
      {/each}
    </ul>
  {/if}

  <div class="context-actions">
    <button type="button" disabled={busy} onclick={() => (creating = true)}>
      {structuredStrings.newTemplate.label}
    </button>
    <button type="button" disabled={busy || selected === null} onclick={() => (addingField = true)}>
      {structuredStrings.addField.label}
    </button>
  </div>
</section>

{#if creating}
  <NameDialog
    title={structuredStrings.newTemplate.dialogTitle}
    label={structuredStrings.newTemplate.nameLabel}
    placeholder={structuredStrings.newTemplate.placeholder}
    confirmLabel={structuredStrings.newTemplate.label}
    onCancel={() => (creating = false)}
    onConfirm={(name) => {
      onCreate(name)
      creating = false
    }}
  />
{:else if addingField && selected !== null}
  <FieldDialog
    templateName={selected.name}
    onCancel={() => (addingField = false)}
    onConfirm={(input) => {
      onAddField(selected.id, input)
      addingField = false
    }}
  />
{/if}

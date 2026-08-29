<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { BlockField, SemanticBlock, Template } from '../lib/api'
  import { structuredStrings } from '../lib/strings'

  let {
    blocks,
    templates,
    busy = false,
    onAddBlock,
    onRemoveBlock,
    onSetValues,
  }: {
    blocks: SemanticBlock[]
    templates: Template[]
    busy?: boolean
    onAddBlock: (templateId: string) => void
    onRemoveBlock: (blockId: string) => void
    onSetValues: (blockId: string, fieldId: string, values: unknown[]) => void
  } = $props()

  const EDITABLE = [
    'text', 'url', 'number', 'percentage', 'date', 'boolean',
    'enum', 'multi_enum', 'measurement', 'currency',
  ]

  const available = $derived(templates.filter((template) => template.status === 'active'))

  function first(field: BlockField): unknown {
    return field.values[0]?.value ?? null
  }

  function textOf(field: BlockField): string {
    const value = first(field)
    return typeof value === 'string' ? value : ''
  }

  function numberOf(field: BlockField): string {
    const value = first(field)
    if (typeof value === 'number') return String(value)
    if (value !== null && typeof value === 'object' && 'value' in value) return String((value as { value: number }).value)
    return ''
  }

  function partOf(field: BlockField, key: 'unit' | 'currency'): string {
    const value = first(field)
    if (value !== null && typeof value === 'object' && key in value) return String((value as Record<string, unknown>)[key] ?? '')
    return ''
  }

  /** Sends the value, or clears the field when the input is emptied. */
  function submit(blockId: string, field: BlockField, value: unknown): void {
    onSetValues(blockId, field.fieldId, value === null || value === '' ? [] : [value])
  }

  function submitNumber(blockId: string, field: BlockField, raw: string): void {
    if (raw.trim() === '') {
      onSetValues(blockId, field.fieldId, [])
      return
    }
    const parsed = Number(raw)
    if (!Number.isNaN(parsed)) submit(blockId, field, parsed)
  }

  function toggleOption(blockId: string, field: BlockField, option: string, checked: boolean): void {
    const current = field.values.map((entry) => String(entry.value))
    const next = checked ? [...current, option] : current.filter((value) => value !== option)
    onSetValues(blockId, field.fieldId, next)
  }
</script>

<section class="structured" aria-label={structuredStrings.label} title={structuredStrings.description}>
  <h3>{structuredStrings.label}</h3>

  {#if blocks.length === 0}
    <p class="muted">{structuredStrings.empty}</p>
  {/if}

  {#each blocks as block (block.id)}
    <article class="structured-block">
      <header>
        <strong>{block.templateName}</strong>
        <button
          type="button"
          disabled={busy}
          aria-label={`${structuredStrings.removeBlock.description}: ${block.templateName}`}
          title={structuredStrings.removeBlock.description}
          onclick={() => onRemoveBlock(block.id)}
        >{structuredStrings.removeBlock.label}</button>
      </header>

      {#each block.fields as field (field.fieldId)}
        <label class="structured-field">
          <span>
            {field.name}
            {#if field.required}<small>{structuredStrings.required}</small>{/if}
          </span>

          {#if field.fieldType === 'boolean'}
            <input
              type="checkbox"
              checked={first(field) === true}
              disabled={busy}
              onchange={(event) => submit(block.id, field, event.currentTarget.checked)}
            />
          {:else if field.fieldType === 'date'}
            <input
              type="date"
              value={textOf(field)}
              disabled={busy}
              onchange={(event) => submit(block.id, field, event.currentTarget.value)}
            />
          {:else if field.fieldType === 'number' || field.fieldType === 'percentage'}
            <input
              type="number"
              value={numberOf(field)}
              disabled={busy}
              onchange={(event) => submitNumber(block.id, field, event.currentTarget.value)}
            />
          {:else if field.fieldType === 'enum'}
            <select
              value={textOf(field)}
              disabled={busy}
              onchange={(event) => submit(block.id, field, event.currentTarget.value)}
            >
              <option value="">{structuredStrings.emptyValue}</option>
              {#each field.options as option}
                <option value={option}>{option}</option>
              {/each}
            </select>
          {:else if field.fieldType === 'multi_enum'}
            <span class="structured-options">
              {#each field.options as option}
                <label>
                  <input
                    type="checkbox"
                    checked={field.values.some((entry) => entry.value === option)}
                    disabled={busy}
                    onchange={(event) => toggleOption(block.id, field, option, event.currentTarget.checked)}
                  />
                  {option}
                </label>
              {/each}
            </span>
          {:else if field.fieldType === 'measurement' || field.fieldType === 'currency'}
            {@const isCurrency = field.fieldType === 'currency'}
            <span class="structured-pair">
              <input
                type="number"
                value={numberOf(field)}
                disabled={busy}
                onchange={(event) => {
                  const amount = Number(event.currentTarget.value)
                  const other = partOf(field, isCurrency ? 'currency' : 'unit')
                  if (event.currentTarget.value.trim() === '') onSetValues(block.id, field.fieldId, [])
                  else if (!Number.isNaN(amount) && other !== '') {
                    submit(block.id, field, isCurrency ? { value: amount, currency: other } : { value: amount, unit: other })
                  }
                }}
              />
              <input
                type="text"
                value={partOf(field, isCurrency ? 'currency' : 'unit')}
                placeholder={isCurrency ? structuredStrings.currencyLabel : structuredStrings.unitLabel}
                disabled={busy}
                onchange={(event) => {
                  const other = event.currentTarget.value.trim()
                  const amount = Number(numberOf(field))
                  if (other !== '' && numberOf(field) !== '' && !Number.isNaN(amount)) {
                    submit(block.id, field, isCurrency ? { value: amount, currency: other } : { value: amount, unit: other })
                  }
                }}
              />
            </span>
          {:else if EDITABLE.includes(field.fieldType)}
            <input
              type="text"
              value={textOf(field)}
              disabled={busy}
              onchange={(event) => submit(block.id, field, event.currentTarget.value)}
            />
          {:else}
            <span class="muted">{structuredStrings.unsupported}</span>
          {/if}
        </label>
      {/each}
    </article>
  {/each}

  {#if available.length === 0}
    <p class="muted">{structuredStrings.noTemplates}</p>
  {:else}
    <select
      value=""
      disabled={busy}
      aria-label={structuredStrings.addBlock}
      onchange={(event) => {
        const templateId = event.currentTarget.value
        event.currentTarget.value = ''
        if (templateId !== '') onAddBlock(templateId)
      }}
    >
      <option value="">{structuredStrings.addBlock}</option>
      {#each available as template (template.id)}
        <option value={template.id}>{template.name}</option>
      {/each}
    </select>
  {/if}
</section>

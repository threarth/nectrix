<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { StructuredEntity, Template, TemplateField } from '../lib/api'
  import { structuredSearchStrings } from '../lib/strings'

  let {
    template,
    results,
    searching = false,
    onSearch,
    onOpen,
  }: {
    template: Template
    results: StructuredEntity[] | null
    searching?: boolean
    onSearch: (fieldId: string, operator: string, value: unknown, withFilters: boolean) => void
    onOpen: (entityId: string) => void
  } = $props()

  let fieldId = $state('')
  let operator = $state('eq')
  // bind:value su un input numerico consegna un numero: il valore resta di due tipi possibili.
  let value = $state<string | number>('')
  let withFilters = $state(false)

  const field = $derived<TemplateField | null>(template.fields.find((row) => row.id === fieldId) ?? null)
  const operators = $derived(field === null ? [] : structuredSearchStrings.byType[field.field_type] ?? ['eq'])
  const needsValue = $derived(operator !== 'is_true' && operator !== 'is_false')

  /** The value is sent in the shape the field type expects, never as generic text. */
  function typedValue(): unknown {
    if (field === null || !needsValue) return undefined
    if (['number', 'percentage', 'measurement', 'currency'].includes(field.field_type)) return Number(value)
    return String(value)
  }

  const missingValue = $derived(needsValue && String(value).trim() === '')
</script>

<section class="structured-search" aria-label={structuredSearchStrings.label} title={structuredSearchStrings.description}>
  <h3>{structuredSearchStrings.label}</h3>

  <form
    onsubmit={(event) => {
      event.preventDefault()
      if (fieldId !== '') onSearch(fieldId, operator, typedValue(), withFilters)
    }}
  >
    <label>
      {structuredSearchStrings.fieldLabel}
      <select
        bind:value={fieldId}
        onchange={() => {
          operator = structuredSearchStrings.byType[field?.field_type ?? 'text']?.[0] ?? 'eq'
        }}
      >
        <option value="">—</option>
        {#each template.fields as row (row.id)}
          <option value={row.id}>{row.name}</option>
        {/each}
      </select>
    </label>

    {#if field !== null}
      <label>
        {structuredSearchStrings.operatorLabel}
        <select bind:value={operator}>
          {#each operators as option}
            <option value={option}>{structuredSearchStrings.operators[option] ?? option}</option>
          {/each}
        </select>
      </label>

      {#if needsValue}
        <label>
          {structuredSearchStrings.valueLabel}
          <input
            bind:value
            type={['number', 'percentage', 'measurement', 'currency'].includes(field.field_type)
              ? 'number'
              : field.field_type === 'date' ? 'date' : 'text'}
          />
        </label>
      {/if}

      <label class="structured-search-toggle">
        <input type="checkbox" bind:checked={withFilters} />
        {structuredSearchStrings.withFilters}
      </label>

      <button type="submit" disabled={searching || missingValue}>
        {searching ? structuredSearchStrings.searching : structuredSearchStrings.submit}
      </button>
    {/if}
  </form>

  {#if results !== null}
    <div class="structured-results" aria-label={structuredSearchStrings.resultsLabel}>
      {#if results.length === 0}
        <p class="empty-state">{structuredSearchStrings.empty}</p>
      {:else}
        <ul>
          {#each results as entity (entity.id)}
            <li>
              <button type="button" onclick={() => onOpen(entity.id)}>
                <span class="search-label">{entity.name}</span>
                <small>
                  {entity.matches
                    .map((match) => structuredSearchStrings.matchedBy(match.field, structuredSearchStrings.operators[match.operator] ?? match.operator))
                    .join(' · ')}
                </small>
                {#if entity.documents.length > 0}
                  <small>{entity.documents.length} documento/i via occurrence</small>
                {/if}
              </button>
            </li>
          {/each}
        </ul>
      {/if}
    </div>
  {/if}
</section>

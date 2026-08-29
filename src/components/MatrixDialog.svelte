<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import {
    fetchMatrix,
    fetchMatrixCell,
    type ContextMode,
    type Matrix,
    type MatrixAxis,
    type MatrixDrill,
    type MatrixQuery,
    type Template,
  } from '../lib/api'
  import { contextPathLabel, orderContexts, type ContextNode } from '../lib/contexts'
  import { matrixStrings, structuredSearchStrings } from '../lib/strings'

  let {
    contexts,
    templates,
    onOpenDocument,
    onError,
    onClose,
  }: {
    contexts: ContextNode[]
    templates: Template[]
    onOpenDocument: (documentId: string) => void
    onError: (message: string) => void
    onClose: () => void
  } = $props()

  const ROW_LIMIT = 100

  let axis = $state<MatrixAxis>('concept')
  let mode = $state<ContextMode>('subtree')
  let fieldId = $state('')
  let operator = $state('eq')
  // Un input numerico consegna un numero: il valore resta di due tipi possibili.
  let value = $state<string | number>('')
  let matrix = $state<Matrix | null>(null)
  let drill = $state<MatrixDrill | null>(null)
  let drillTitle = $state('')
  let loading = $state(false)

  const fields = $derived(templates.flatMap((template) => template.fields.map((field) => ({ template, field }))))
  const field = $derived(fields.find((row) => row.field.id === fieldId) ?? null)
  const operators = $derived(field === null ? [] : structuredSearchStrings.byType[field.field.field_type] ?? ['eq'])
  const needsValue = $derived(operator !== 'is_true' && operator !== 'is_false')
  const filterAllowed = $derived(axis !== 'concept')
  const columns = $derived(buildColumns())

  /** Column order follows the hierarchy, so a child sits under its parent as in the sidebar. */
  function buildColumns(): { id: string | null; label: string }[] {
    const ordered = orderContexts(contexts).map((row) => ({ id: row.id, label: contextPathLabel(row) }))
    return [{ id: null, label: matrixStrings.noContext }, ...ordered]
  }

  /** The value is sent in the shape the field type expects, never as generic text. */
  function typedValue(): unknown {
    if (field === null || !needsValue) return undefined
    const numeric = ['number', 'percentage', 'measurement', 'currency']
    return numeric.includes(field.field.field_type) ? Number(value) : String(value)
  }

  function query(): MatrixQuery {
    const base: MatrixQuery = { axis, mode }
    if (!filterAllowed || fieldId === '') return base
    return { ...base, fieldFilter: { fieldId, operator, value: typedValue() } }
  }

  async function load(): Promise<void> {
    loading = true
    drill = null
    try {
      matrix = await fetchMatrix(query())
    } catch (error) {
      onError(error instanceof Error ? error.message : String(error))
    } finally {
      loading = false
    }
  }

  async function openCell(rowId: string, rowLabel: string, contextId: string | null, columnLabel: string): Promise<void> {
    try {
      drill = await fetchMatrixCell({ ...query(), rowId, contextId })
      drillTitle = matrixStrings.drillTitle(rowLabel, columnLabel)
    } catch (error) {
      onError(error instanceof Error ? error.message : String(error))
    }
  }

  function matches(row: Matrix['rows'][number], contextId: string | null): number {
    return row.cells.find((cell) => cell.contextId === contextId)?.matches ?? 0
  }

  function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') onClose()
  }

  void load()
</script>

<svelte:window onkeydown={handleKeydown} />

<div class="dialog-backdrop" role="dialog" aria-modal="true" aria-label={matrixStrings.dialogTitle}>
  <div class="dialog matrix-dialog">
    <h2>{matrixStrings.dialogTitle}</h2>

    <div class="matrix-controls">
      <div role="group" aria-label={matrixStrings.axisLabel}>
        {#each matrixStrings.axes as option}
          <button
            type="button"
            class:active={axis === option.value}
            aria-pressed={axis === option.value}
            title={option.description}
            onclick={() => {
              axis = option.value
              void load()
            }}
          >{option.label}</button>
        {/each}
      </div>

      <div role="group" aria-label={matrixStrings.modeLabel}>
        {#each matrixStrings.modes as option}
          <button
            type="button"
            class:active={mode === option.value}
            aria-pressed={mode === option.value}
            title={option.description}
            onclick={() => {
              mode = option.value
              void load()
            }}
          >{option.label}</button>
        {/each}
      </div>
    </div>

    <div class="matrix-filter">
      <label>
        {matrixStrings.filterLabel}
        <select
          bind:value={fieldId}
          disabled={!filterAllowed}
          onchange={() => {
            operator = structuredSearchStrings.byType[field?.field.field_type ?? 'text']?.[0] ?? 'eq'
            void load()
          }}
        >
          <option value="">{matrixStrings.filterNone}</option>
          {#each fields as row (row.field.id)}
            <option value={row.field.id}>{row.template.name} · {row.field.name}</option>
          {/each}
        </select>
      </label>

      {#if filterAllowed && field !== null}
        <label>
          {structuredSearchStrings.operatorLabel}
          <select bind:value={operator} onchange={() => void load()}>
            {#each operators as option}
              <option value={option}>{structuredSearchStrings.operators[option] ?? option}</option>
            {/each}
          </select>
        </label>

        {#if needsValue}
          <label>
            {structuredSearchStrings.valueLabel}
            <input bind:value onchange={() => void load()} />
          </label>
        {/if}
      {/if}

      {#if !filterAllowed}
        <p class="dialog-hint">{matrixStrings.filterUnavailable}</p>
      {/if}
    </div>

    {#if matrix !== null}
      <p class="dialog-hint">{matrixStrings.paths[matrix.path] ?? matrix.path}</p>
    {/if}

    <div class="matrix-scroll">
      {#if matrix === null || matrix.rows.length === 0}
        <p class="empty-state">{loading ? '…' : matrixStrings.empty}</p>
      {:else}
        <table>
          <thead>
            <tr>
              <th>{matrixStrings.rowHeader}</th>
              {#each columns as column (column.id ?? '')}
                <th>{column.label}</th>
              {/each}
              <th>{matrixStrings.totalHeader}</th>
            </tr>
          </thead>
          <tbody>
            {#each matrix.rows as row (row.id)}
              <tr>
                <th scope="row">{row.label}</th>
                {#each columns as column (column.id ?? '')}
                  <td>
                    {#if matches(row, column.id) > 0}
                      <button
                        type="button"
                        aria-label={matrixStrings.cellLabel(row.label, column.label, matches(row, column.id))}
                        onclick={() => void openCell(row.id, row.label, column.id, column.label)}
                      >{matches(row, column.id)}</button>
                    {:else}
                      <span class="muted">·</span>
                    {/if}
                  </td>
                {/each}
                <td class="matrix-total">{row.total}</td>
              </tr>
            {/each}
          </tbody>
        </table>
      {/if}
    </div>

    {#if matrix?.truncated}
      <p class="dialog-hint">{matrixStrings.truncated(ROW_LIMIT)}</p>
    {/if}

    {#if drill !== null}
      <section class="matrix-drill" aria-label={drillTitle}>
        <header>
          <strong>{drillTitle}</strong>
          <button type="button" onclick={() => (drill = null)}>{matrixStrings.drillClose}</button>
        </header>
        <p class="dialog-hint">{matrixStrings.paths[drill.path] ?? drill.path} · {matrixStrings.sourceLater}</p>
        {#if drill.occurrences.length === 0}
          <p class="empty-state">{matrixStrings.drillEmpty}</p>
        {:else}
          <ul>
            {#each drill.occurrences as occurrence (occurrence.occurrenceId)}
              <li>
                <button type="button" title={matrixStrings.openDocument} onclick={() => onOpenDocument(occurrence.documentId)}>
                  {occurrence.documentTitle}
                </button>
                {#if occurrence.coObjects.length > 0}
                  <small>{matrixStrings.coObjects} {occurrence.coObjects.map((row) => row.label).join(', ')}</small>
                {/if}
              </li>
            {/each}
          </ul>
        {/if}
      </section>
    {/if}

    <div class="dialog-actions">
      <button type="button" onclick={onClose}>{matrixStrings.close}</button>
    </div>
  </div>
</div>

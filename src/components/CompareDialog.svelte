<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { Comparison } from '../lib/api'
  import { compareStrings } from '../lib/strings'

  let {
    comparison,
    onClose,
  }: {
    comparison: Comparison
    onClose: () => void
  } = $props()

  function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') onClose()
  }
</script>

<svelte:window onkeydown={handleKeydown} />

<div class="dialog-backdrop" role="dialog" aria-modal="true" aria-label={compareStrings.dialogTitle(comparison.mode)}>
  <div class="dialog compare-dialog">
    <h2>{compareStrings.dialogTitle(comparison.mode)}</h2>
    <p class="dialog-hint">{compareStrings.hint}</p>

    <div class="compare-scroll">
      <table>
        <thead>
          <tr>
            <th></th>
            {#each comparison.subjects as subject (subject.id)}
              <th>{subject.name}</th>
            {/each}
          </tr>
        </thead>
        <tbody>
          {#each comparison.rows as row (row.label)}
            <tr>
              <th scope="row">
                {row.label}
                <small>{compareStrings.paths[row.path] ?? row.path}</small>
              </th>
              {#each row.cells as cell}
                <td>
                  {#if cell.length === 0}
                    <span class="muted">{compareStrings.empty}</span>
                  {:else}
                    <ul>
                      {#each cell as value}
                        <li>{value}</li>
                      {/each}
                    </ul>
                  {/if}
                </td>
              {/each}
            </tr>
          {/each}
        </tbody>
      </table>
    </div>

    <div class="dialog-actions">
      <button type="button" class="dialog-confirm" onclick={onClose}>{compareStrings.close}</button>
    </div>
  </div>
</div>

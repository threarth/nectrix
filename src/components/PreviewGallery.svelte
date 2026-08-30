<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { Preview } from '../lib/api'
  import { previewStrings } from '../lib/strings'

  let {
    preview,
    onOpen,
    onClose,
  }: {
    preview: Preview
    /** Opening a Document from here also says which occurrence was chosen. */
    onOpen: (documentId: string, occurrenceId: string | null) => void
    onClose: () => void
  } = $props()

  function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') onClose()
  }
</script>

<svelte:window onkeydown={handleKeydown} />

<section class="preview-view" aria-label={previewStrings.title(preview.kind, preview.label)}>
  <header class="preview-head">
    <div>
      <h1>{previewStrings.title(preview.kind, preview.label)}</h1>
      <p>{preview.trashed ? previewStrings.trashedHint : previewStrings.hint}</p>
    </div>
    <button type="button" class="preview-close" onclick={onClose}>{previewStrings.close}</button>
  </header>

    {#if preview.documents.length === 0}
      <p class="empty-state">{previewStrings.empty}</p>
    {:else}
      <div class="preview-grid">
        {#each preview.documents as document (document.id)}
          <article class="preview-card">
            <header>
              <button
                type="button"
                class="preview-open"
                title={previewStrings.open(document.title)}
                onclick={() => onOpen(document.id, document.fragments[0]?.occurrenceId ?? null)}
              >{document.title}</button>
              <small>
                {previewStrings.fragments(document.total)}
                {#if previewStrings.documentStatus[document.status]}
                  · {previewStrings.documentStatus[document.status]}
                {/if}
              </small>
            </header>

            <ul>
              {#each document.fragments as fragment (fragment.occurrenceId)}
                <li>
                  <button
                    type="button"
                    class="preview-fragment"
                    class:detached={fragment.status !== 'active'}
                    onclick={() => onOpen(document.id, fragment.occurrenceId)}
                  >
                    <span class="preview-around">{fragment.before}</span><mark>{fragment.text}</mark><span class="preview-around">{fragment.after}</span>
                  </button>
                  {#if fragment.status !== 'active'}
                    <small>{previewStrings.detached}</small>
                  {/if}
                </li>
              {/each}
            </ul>

            {#if document.total > document.fragments.length}
              <small class="preview-more">{previewStrings.more(document.total - document.fragments.length)}</small>
            {/if}
          </article>
        {/each}
      </div>
    {/if}

</section>

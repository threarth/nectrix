<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { JSONContent } from '@tiptap/core'
  import { onMount } from 'svelte'
  import DocumentEditor from './components/DocumentEditor.svelte'
  import {
    ApiError,
    createDocument,
    getDocument,
    listDocuments,
    saveDocument,
    type DocumentRecord,
    type DocumentSummary,
  } from './lib/api'

  let documents = $state<DocumentSummary[]>([])
  let selected = $state<DocumentRecord | null>(null)
  let draftTitle = $state('')
  let draftJson = $state<JSONContent | null>(null)
  let dirty = $state(false)
  let loading = $state(true)
  let saving = $state(false)
  let error = $state('')

  onMount(() => {
    void initialise()
  })

  async function initialise(): Promise<void> {
    loading = true
    error = ''
    try {
      documents = await listDocuments()
      if (documents.length > 0) {
        await openDocument(documents[0].id)
      }
    } catch (cause) {
      showError(cause)
    } finally {
      loading = false
    }
  }

  async function openDocument(id: string): Promise<void> {
    if (dirty && !window.confirm('Le modifiche non salvate andranno perse. Continuare?')) return
    error = ''
    try {
      applyDocument(await getDocument(id))
    } catch (cause) {
      showError(cause)
    }
  }

  async function addDocument(): Promise<void> {
    if (dirty && !window.confirm('Le modifiche non salvate andranno perse. Continuare?')) return
    error = ''
    try {
      const created = await createDocument()
      documents = [created, ...documents]
      applyDocument(created)
    } catch (cause) {
      showError(cause)
    }
  }

  async function persist(): Promise<void> {
    if (!selected || !draftJson || saving) return
    saving = true
    error = ''
    try {
      const saved = await saveDocument(selected, draftTitle, draftJson)
      applyDocument(saved)
      documents = [saved, ...documents.filter((document) => document.id !== saved.id)]
    } catch (cause) {
      showError(cause)
    } finally {
      saving = false
    }
  }

  function applyDocument(document: DocumentRecord): void {
    selected = document
    draftTitle = document.title
    draftJson = document.documentJson
    dirty = false
  }

  function changeTitle(value: string): void {
    draftTitle = value
    dirty = true
  }

  function changeContent(content: JSONContent): void {
    draftJson = content
    dirty = true
  }

  function showError(cause: unknown): void {
    if (cause instanceof ApiError && cause.code === 'revision_conflict') {
      error = 'Il documento è cambiato dopo l’apertura. La bozza resta nell’editor: ricarica solo dopo averla copiata o verificata.'
      return
    }
    error = cause instanceof Error ? cause.message : 'Errore inatteso.'
  }
</script>

<svelte:head>
  <meta name="description" content="Nectrix, documenti per lo studio e la conoscenza personale" />
</svelte:head>

<div class="app-frame">
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark" aria-hidden="true">N</span>
      <div>
        <strong>Nectrix</strong>
        <small>Documenti di studio</small>
      </div>
    </div>

    <button class="new-document" type="button" onclick={addDocument}>+ Nuovo documento</button>

    <nav aria-label="Documenti">
      {#if loading}
        <p class="muted">Caricamento…</p>
      {:else if documents.length === 0}
        <p class="empty-state">Non ci sono ancora documenti.</p>
      {:else}
        {#each documents as document (document.id)}
          <button
            type="button"
            class="document-item"
            class:active={selected?.id === document.id}
            onclick={() => openDocument(document.id)}
          >
            <span>{document.title || 'Senza titolo'}</span>
            <small>rev. {document.revision}</small>
          </button>
        {/each}
      {/if}
    </nav>
  </aside>

  <main class="workspace">
    {#if error}
      <div class="error-banner" role="alert">
        <span>{error}</span>
        <button type="button" aria-label="Chiudi errore" onclick={() => (error = '')}>×</button>
      </div>
    {/if}

    {#if selected && draftJson}
      <header class="document-header">
        <input
          class="title-input"
          aria-label="Titolo documento"
          value={draftTitle}
          oninput={(event) => changeTitle(event.currentTarget.value)}
          placeholder="Titolo del documento"
        />
        <div class="save-area">
          <span class:dirty class="save-status">{dirty ? 'Modifiche non salvate' : `Revisione ${selected.revision}`}</span>
          <button class="save-button" type="button" disabled={!dirty || saving} onclick={persist}>
            {saving ? 'Salvataggio…' : 'Salva'}
          </button>
        </div>
      </header>

      {#key selected.id}
        <DocumentEditor initialContent={draftJson} onChange={changeContent} />
      {/key}
    {:else if !loading}
      <section class="welcome">
        <span class="welcome-symbol" aria-hidden="true">✦</span>
        <h1>Inizia da un documento</h1>
        <p>Scrivi normalmente. La struttura arriverà quando ti serve.</p>
        <button class="save-button" type="button" onclick={addDocument}>Crea il primo documento</button>
      </section>
    {/if}
  </main>
</div>

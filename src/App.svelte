<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { JSONContent } from '@tiptap/core'
  import { onMount, tick } from 'svelte'
  import DocumentEditor from './components/DocumentEditor.svelte'
  import {
    ApiError,
    createDocument,
    getDocument,
    getKnowledgeObject,
    listDocuments,
    saveDocument,
    setDocumentLifecycle,
    setEntityTypeArchived,
    setKnowledgeObjectArchived,
    addConceptAlias,
    addEntityIdentifier,
    removeConceptAlias,
    removeEntityIdentifier,
    type DocumentRecord,
    type DocumentStatus,
    type DocumentSummary,
    type DuplicateCandidate,
    type EntityIdentifierInput,
    type KnowledgeObjectDetail,
    type KnowledgeOccurrenceView,
  } from './lib/api'
  import KnowledgeInspector from './components/KnowledgeInspector.svelte'
  import { documentStrings } from './lib/strings'
  import {
    collectOccurrences,
    deriveOccurrenceCreates,
    collectOccurrenceTexts,
    type PendingKnowledgeObject,
  } from './lib/occurrences'

  let documents = $state<DocumentSummary[]>([])
  let scope = $state<DocumentStatus>('active')
  let selected = $state<DocumentRecord | null>(null)
  let draftTitle = $state('')
  let draftJson = $state<JSONContent | null>(null)
  let dirty = $state(false)
  let loading = $state(true)
  let saving = $state(false)
  let error = $state('')
  /** Occurrence already persisted in the loaded revision: they need no creation at save time. */
  let persistedOccurrenceIds = new Set<string>()

  /** KnowledgeObject created in this session and not yet persisted, by knowledgeObjectId. */
  let pendingObjects = new Map<string, PendingKnowledgeObject>()
  let inspector = $state<KnowledgeObjectDetail | null>(null)
  let inspectorBusy = $state(false)
  let duplicateCandidates = $state<DuplicateCandidate[]>([])
  let sidebarRename = $state<{ id: string; title: string } | null>(null)
  let sidebarRenameInput = $state<HTMLInputElement | undefined>(undefined)
  let titleInput = $state<HTMLTextAreaElement | undefined>(undefined)

  onMount(() => {
    void initialise()
  })

  async function initialise(): Promise<void> {
    loading = true
    error = ''
    try {
      documents = await listDocuments(scope)
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

  const readOnly = $derived(selected !== null && selected.status !== 'active')

  /**
   * The API reads the occurrence text from the last saved revision. For the Document open in the
   * editor the draft is what the user is looking at, so the panel shows that instead of a text
   * that would look like a change gone lost.
   */
  const inspectorObject = $derived.by(() => {
    const object = inspector
    const documentId = selected?.id
    const draft = draftJson
    if (object === null || documentId === undefined || draft === null) return object
    const texts = collectOccurrenceTexts(draft)
    return {
      ...object,
      occurrences: object.occurrences.map((occurrence) => occurrence.documentId === documentId
        ? { ...occurrence, text: texts.get(occurrence.id) ?? '' }
        : occurrence),
    }
  })

  /** Lifecycle commands available from the current state, in the order they are offered. */
  function lifecycleActions(status: DocumentStatus): { command: 'archive' | 'trash' | 'restore'; label: string; description: string }[] {
    const restore = { command: 'restore' as const, ...documentStrings.restore }
    const archive = { command: 'archive' as const, ...documentStrings.archive }
    const trash = { command: 'trash' as const, ...documentStrings.trash }
    if (status === 'active') return [archive, trash]
    if (status === 'archived') return [restore, trash]
    return [restore]
  }

  async function changeScope(next: DocumentStatus): Promise<void> {
    if (scope === next) return
    scope = next
    error = ''
    try {
      documents = await listDocuments(scope)
    } catch (cause) {
      showError(cause)
    }
  }

  /** Archive, trash and restore are reversible and never touch content or occurrence. */
  async function changeLifecycle(action: 'archive' | 'trash' | 'restore'): Promise<void> {
    if (!selected || saving) return
    if (dirty && !window.confirm('Le modifiche non salvate andranno perse. Continuare?')) return
    saving = true
    error = ''
    try {
      applyDocument(await setDocumentLifecycle(selected.id, action))
      documents = await listDocuments(scope)
    } catch (cause) {
      showError(cause)
    } finally {
      saving = false
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
      const creates = deriveOccurrenceCreates(draftJson, persistedOccurrenceIds, pendingObjects)
      const saved = await saveDocument(selected, draftTitle, draftJson, creates)
      for (const create of creates) {
        if (create.newObject) pendingObjects.delete(create.knowledgeObjectId)
      }
      applyDocument(saved)
      documents = [saved, ...documents.filter((document) => document.id !== saved.id)]
      await refreshInspector()
    } catch (cause) {
      showError(cause)
    } finally {
      saving = false
    }
  }

  function applyDocument(document: DocumentRecord): void {
    // A KnowledgeObject created but not yet persisted survives a save of the same Document: undo
    // can bring its mark back and the next save still has to declare its creation.
    const sameDocument = selected?.id === document.id
    selected = document
    draftTitle = document.title
    draftJson = document.documentJson
    dirty = false
    persistedOccurrenceIds = new Set(collectOccurrences(document.documentJson).keys())
    if (!sameDocument) pendingObjects = new Map()
    void tick().then(resizeTitleInput)
  }

  function changeTitle(value: string): void {
    draftTitle = value
    dirty = true
    void tick().then(resizeTitleInput)
  }

  function changeContent(content: JSONContent): void {
    draftJson = content
    dirty = true
  }

  function addPendingObject(knowledgeObjectId: string, object: PendingKnowledgeObject): void {
    pendingObjects.set(knowledgeObjectId, object)
    dirty = true
  }

  /**
   * The inspector always shows the discriminator recorded in the database, never the one guessed
   * from the mark, so a manipulated mark cannot open the wrong inspector.
   */
  async function openInspector(knowledgeObjectId: string): Promise<void> {
    inspectorBusy = true
    error = ''
    try {
      inspector = await getKnowledgeObject(knowledgeObjectId)
      duplicateCandidates = []
    } catch (cause) {
      showError(cause)
    } finally {
      inspectorBusy = false
    }
  }

  async function toggleInspectorArchived(): Promise<void> {
    const object = inspector
    if (!object || inspectorBusy) return
    await withInspectorBusy(async () => {
      inspector = await setKnowledgeObjectArchived(object.id, object.status !== 'archived')
    })
  }

  async function toggleInspectorEntityTypeArchived(): Promise<void> {
    const object = inspector
    const entityType = object?.entityType
    if (!object || !entityType || inspectorBusy) return
    await withInspectorBusy(async () => {
      await setEntityTypeArchived(entityType.id, entityType.status !== 'archived')
      inspector = await getKnowledgeObject(object.id)
    })
  }

  async function addAlias(alias: string): Promise<void> {
    const object = inspector
    if (!object) return
    await withInspectorBusy(async () => {
      inspector = await addConceptAlias(object.id, alias)
    })
  }

  async function removeAlias(aliasId: string): Promise<void> {
    await withInspectorBusy(async () => {
      inspector = await removeConceptAlias(aliasId)
    })
  }

  /** A collision with another Entity is reported as a duplicate candidate, never merged. */
  async function addIdentifier(input: EntityIdentifierInput): Promise<void> {
    const object = inspector
    if (!object) return
    await withInspectorBusy(async () => {
      const added = await addEntityIdentifier(object.id, input)
      inspector = added.object
      duplicateCandidates = added.duplicateCandidates
    })
  }

  async function removeIdentifier(identifierId: string): Promise<void> {
    await withInspectorBusy(async () => {
      inspector = await removeEntityIdentifier(identifierId)
      duplicateCandidates = []
    })
  }

  /**
   * Realigns the panel with what was just saved. A failure here must not be reported as a failed
   * save: the Document is stored, only the panel would stay one step behind.
   */
  async function refreshInspector(): Promise<void> {
    const object = inspector
    if (object === null) return
    try {
      inspector = await getKnowledgeObject(object.id)
    } catch (cause) {
      console.warn('Aggiornamento del pannello non riuscito dopo il salvataggio.', cause)
    }
  }

  async function withInspectorBusy(operation: () => Promise<void>): Promise<void> {
    inspectorBusy = true
    error = ''
    try {
      await operation()
    } catch (cause) {
      showError(cause)
    } finally {
      inspectorBusy = false
    }
  }

  /** Opens the Document owning the occurrence and brings its mark into view. */
  async function openOccurrence(occurrence: KnowledgeOccurrenceView): Promise<void> {
    if (occurrence.documentId !== selected?.id) {
      await openDocument(occurrence.documentId)
    }
    await tick()
    if (!scrollToOccurrence(occurrence.id)) {
      await new Promise((resolve) => window.requestAnimationFrame(resolve))
      scrollToOccurrence(occurrence.id)
    }
  }

  function scrollToOccurrence(occurrenceId: string): boolean {
    const element = window.document.querySelector(`.tiptap [data-occurrence-id="${occurrenceId}"]`)
    element?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    return element !== null
  }

  async function beginSidebarRename(document: DocumentSummary): Promise<void> {
    if (document.status !== 'active') return
    sidebarRename = {
      id: document.id,
      title: selected?.id === document.id ? draftTitle : document.title,
    }
    await tick()
    sidebarRenameInput?.focus()
    sidebarRenameInput?.select()
  }

  async function confirmSidebarRename(id: string): Promise<void> {
    const rename = sidebarRename
    if (!rename || rename.id !== id || saving) return

    if (selected?.id === id && draftJson) {
      changeTitle(rename.title)
      sidebarRename = null
      await persist()
      return
    }

    saving = true
    error = ''
    try {
      const document = await getDocument(id)
      const saved = await saveDocument(document, rename.title, document.documentJson)
      documents = [saved, ...documents.filter((item) => item.id !== saved.id)]
      sidebarRename = null
    } catch (cause) {
      showError(cause)
    } finally {
      saving = false
    }
  }

  function confirmTitleFromDocument(event: KeyboardEvent): void {
    if (event.key !== 'Enter') return

    if (event.ctrlKey || event.metaKey) {
      event.preventDefault()
      const input = event.currentTarget as HTMLTextAreaElement
      const start = input.selectionStart
      const end = input.selectionEnd
      const nextTitle = `${draftTitle.slice(0, start)}\n${draftTitle.slice(end)}`
      changeTitle(nextTitle)
      void tick().then(() => {
        input.selectionStart = start + 1
        input.selectionEnd = start + 1
      })
      return
    }

    event.preventDefault()
    void persist()
  }

  function resizeTitleInput(): void {
    if (!titleInput) return
    titleInput.style.height = 'auto'
    titleInput.style.height = `${titleInput.scrollHeight}px`
  }

  function showError(cause: unknown): void {
    if (cause instanceof ApiError && cause.code === 'knowledge_object_not_found') {
      error = 'Questo Concept o questa Entity esistono solo nella bozza: salva il documento per aprirne il dettaglio.'
      return
    }
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

<div class="app-frame" class:with-inspector={inspector !== null}>
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark" aria-hidden="true">N</span>
      <div>
        <strong>Nectrix</strong>
        <small>Documenti di studio</small>
      </div>
    </div>

    <button class="new-document" type="button" onclick={addDocument}>+ Nuovo documento</button>

    <div class="scope-switch" role="group" aria-label={documentStrings.scopeLabel}>
      {#each documentStrings.scopes as option}
        <button
          type="button"
          class:active={scope === option.value}
          aria-pressed={scope === option.value}
          title={option.description}
          onclick={() => void changeScope(option.value)}
        >{option.label}</button>
      {/each}
    </div>

    <nav aria-label="Documenti">
      {#if loading}
        <p class="muted">Caricamento…</p>
      {:else if documents.length === 0}
        <p class="empty-state">{documentStrings.emptyScope[scope]}</p>
      {:else}
        {#each documents as document (document.id)}
          {#if sidebarRename?.id === document.id}
            <form
              class="document-rename"
              class:active={selected?.id === document.id}
              onsubmit={(event) => {
                event.preventDefault()
                void confirmSidebarRename(document.id)
              }}
            >
              <input
                bind:this={sidebarRenameInput}
                aria-label="Rinomina documento"
                value={sidebarRename.title}
                oninput={(event) => {
                  if (sidebarRename?.id === document.id) sidebarRename.title = event.currentTarget.value
                }}
                onkeydown={(event) => {
                  if (event.key === 'Escape') sidebarRename = null
                }}
              />
            </form>
          {:else}
            <button
              type="button"
              class="document-item"
              class:active={selected?.id === document.id}
              onclick={() => openDocument(document.id)}
              ondblclick={() => void beginSidebarRename(document)}
            >
              <span>{document.title || 'Senza titolo'}</span>
              <small>rev. {document.revision}</small>
            </button>
          {/if}
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
        <textarea
          bind:this={titleInput}
          class="title-input"
          aria-label="Titolo documento"
          rows="1"
          value={draftTitle}
          oninput={(event) => changeTitle(event.currentTarget.value)}
          onkeydown={confirmTitleFromDocument}
          placeholder="Titolo del documento"
        ></textarea>
        <div class="save-area">
          {#if readOnly}
            <span class="read-only-badge" title={documentStrings.readOnlyHint[selected.status]}>
              {documentStrings.readOnly}
            </span>
          {:else}
            <span class:dirty class="save-status">{dirty ? 'Modifiche non salvate' : `Revisione ${selected.revision}`}</span>
          {/if}
          {#each lifecycleActions(selected.status) as action}
            <button
              class="lifecycle-button"
              type="button"
              disabled={saving}
              title={action.description}
              onclick={() => void changeLifecycle(action.command)}
            >{action.label}</button>
          {/each}
          {#if !readOnly}
            <button class="save-button" type="button" disabled={!dirty || saving} onclick={persist}>
              {saving ? 'Salvataggio…' : 'Salva'}
            </button>
          {/if}
        </div>
      </header>

      {#key selected.id}
        <DocumentEditor
          documentId={selected.id}
          initialContent={draftJson}
          editable={!readOnly}
          onChange={changeContent}
          onObjectCreate={addPendingObject}
          onOpenInspector={(knowledgeObjectId) => void openInspector(knowledgeObjectId)}
        />
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

  {#if inspector}
    <KnowledgeInspector
      object={inspectorObject ?? inspector}
      busy={inspectorBusy}
      {duplicateCandidates}
      onClose={() => (inspector = null)}
      onAddAlias={(alias) => void addAlias(alias)}
      onRemoveAlias={(aliasId) => void removeAlias(aliasId)}
      onAddIdentifier={(input) => void addIdentifier(input)}
      onRemoveIdentifier={(identifierId) => void removeIdentifier(identifierId)}
      onToggleArchived={() => void toggleInspectorArchived()}
      onToggleEntityTypeArchived={() => void toggleInspectorEntityTypeArchived()}
      onOpenOccurrence={(occurrence) => void openOccurrence(occurrence)}
    />
  {/if}
</div>

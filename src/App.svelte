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
    assignTag,
    createContext,
    deleteKnowledgeObject,
    fetchTrash,
    trashContext,
    trashKnowledgeObject,
    createTag,
    deleteTag,
    derivedKnowledgeObjects,
    listDocumentTags,
    listTags,
    searchKnowledge,
    renameTag,
    addBlock,
    addTemplateField,
    createTemplate,
    listBlocks,
    listTemplates,
    removeBlock,
    search,
    searchByObject,
    setBlockValues,
    addRelationEvidence,
    compareObjects,
    createRelation,
    deleteRelation,
    listRelations,
    listRelationEvidence,
    listRelationTypes,
    removeRelationEvidence,
    structuredSearch,
    unassignTag,
    deleteContext,
    listContexts,
    listDocuments,
    moveContext,
    renameContext,
    saveDocument,
    setDocumentLifecycle,
    setEntityTypeArchived,
    setKnowledgeObjectArchived,
    updateKnowledgeObject,
    addConceptAlias,
    addEntityIdentifier,
    removeConceptAlias,
    removeEntityIdentifier,
    type ContextMode,
    type SearchResult,
    type SemanticBlock,
    type Comparison,
    type EvidenceView,
    type RelationView,
    type StructuredEntity,
    type TrashContents,
    type Template,
    type Tag,
    type TagSummary,
    type DocumentRecord,
    type DocumentStatus,
    type DocumentSummary,
    type DuplicateCandidate,
    type EntityIdentifierInput,
    type KnowledgeObjectDetail,
    type KnowledgeOccurrenceView,
  } from './lib/api'
  import ContextPanel from './components/ContextPanel.svelte'
  import DerivedObjects from './components/DerivedObjects.svelte'
  import CompareDialog from './components/CompareDialog.svelte'
  import MatrixDialog from './components/MatrixDialog.svelte'
  import RemoveButton from './components/RemoveButton.svelte'
  import TrashPanel from './components/TrashPanel.svelte'
  import RelationDialog from './components/RelationDialog.svelte'
  import SearchPanel from './components/SearchPanel.svelte'
  import TemplatePanel from './components/TemplatePanel.svelte'
  import DocumentTags from './components/DocumentTags.svelte'
  import TagPanel from './components/TagPanel.svelte'
  import KnowledgeInspector from './components/KnowledgeInspector.svelte'
  import { contextPathLabel, orderContexts, type ContextNode } from './lib/contexts'
  import { compareStrings, contextStrings, documentStrings, matrixStrings, tagStrings, trashStrings } from './lib/strings'
  import {
    collectContextOccurrences,
    collectOccurrences,
    deriveContextCreates,
    deriveOccurrenceCreates,
    collectOccurrenceTexts,
    type PendingKnowledgeObject,
  } from './lib/occurrences'

  let documents = $state<DocumentSummary[]>([])
  let scope = $state<DocumentStatus>('active')
  let contexts = $state<ContextNode[]>([])
  let selectedContextId = $state<string | null>(null)
  let contextMode = $state<ContextMode>('subtree')
  let contextObjects = $state<{ id: string; object_type: 'concept' | 'entity'; name: string }[]>([])
  let tags = $state<TagSummary[]>([])
  let selectedTagIds = $state<string[]>([])
  let documentTags = $state<Tag[]>([])
  let searchQuery = $state('')
  let searchResults = $state<SearchResult[]>([])
  let searching = $state(false)
  let blocks = $state<SemanticBlock[]>([])
  let templates = $state<Template[]>([])
  let structuredResults = $state<StructuredEntity[] | null>(null)
  let structuredSearching = $state(false)
  let relations = $state<RelationView[]>([])
  let relationTypes = $state<string[]>([])
  let addingRelation = $state(false)
  let relationEvidence = $state<{ relationId: string; items: EvidenceView[] } | null>(null)
  let compareQueue = $state<{ id: string; name: string }[]>([])
  let comparison = $state<Comparison | null>(null)
  let showingMatrix = $state(false)
  let selected = $state<DocumentRecord | null>(null)
  let draftTitle = $state('')
  let draftJson = $state<JSONContent | null>(null)
  /** Pause after the last keystroke before the draft is written by itself. */
  const AUTOSAVE_DELAY_MS = 1200
  /** Wait before retrying when a save is already in flight. */
  const AUTOSAVE_RETRY_MS = 400

  /** Grows when a deletion rewrote the open Document: the editor has to take the new content. */
  let editorReloadToken = $state(0)
  let trash = $state<TrashContents>({ knowledgeObjects: [], contexts: [] })
  /** Grows at ogni richiesta della lista: una risposta arrivata in ritardo viene scartata. */
  let documentsFetch = 0
  let autosaveTimer: ReturnType<typeof setTimeout> | undefined
  /** Grows at every edit: tells whether the draft moved on while a save was in flight. */
  let draftVersion = 0
  let dirty = $state(false)
  let loading = $state(true)
  let saving = $state(false)
  let error = $state('')
  /** Occurrence already persisted in the loaded revision: they need no creation at save time. */
  let persistedOccurrenceIds = new Set<string>()
  let persistedContextOccurrenceIds = new Set<string>()

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

  /**
   * Reloads the document list discarding a late answer: with the automatic save, a list requested
   * before a write could otherwise land after it and show the previous title.
   */
  async function reloadDocuments(): Promise<void> {
    const ticket = ++documentsFetch
    const list = await listDocuments(scope, selectedContextId, contextMode, selectedTagIds)
    if (ticket === documentsFetch) documents = list
  }

  async function withTemplates(operation: () => Promise<void>): Promise<void> {
    error = ''
    try {
      await operation()
      templates = await listTemplates()
    } catch (cause) {
      showError(cause)
    }
  }

  async function initialise(): Promise<void> {
    loading = true
    error = ''
    try {
      contexts = await listContexts()
      tags = await listTags()
      templates = await listTemplates()
      trash = await fetchTrash()
      await reloadDocuments()
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

  /** Context and Tag are separate dimensions: both narrow the same list of Document. */
  async function refreshFilters(): Promise<void> {
    error = ''
    try {
      await reloadDocuments()
      contextObjects = selectedContextId === null && selectedTagIds.length === 0
        ? []
        : await derivedKnowledgeObjects(selectedContextId, contextMode, selectedTagIds)
    } catch (cause) {
      showError(cause)
    }
  }

  /** Typed comparison on the values, optionally narrowed by the active editorial filters. */
  async function runStructuredSearch(
    fieldId: string,
    operator: string,
    value: unknown,
    withFilters: boolean,
  ): Promise<void> {
    structuredSearching = true
    error = ''
    try {
      const filter = value === undefined ? { fieldId, operator } : { fieldId, operator, value }
      const result = await structuredSearch([filter], withFilters
        ? { contextId: selectedContextId, contextMode, tagIds: selectedTagIds }
        : {})
      structuredResults = result.entities
    } catch (cause) {
      showError(cause)
    } finally {
      structuredSearching = false
    }
  }

  async function runSearch(query: string): Promise<void> {
    searching = true
    error = ''
    try {
      searchResults = await search(query)
      searchQuery = query
    } catch (cause) {
      showError(cause)
    } finally {
      searching = false
    }
  }

  /** Documents holding an occurrence of the object: identity, not the words written. */
  async function showOccurrencesOf(objectId: string, label: string): Promise<void> {
    searching = true
    error = ''
    try {
      searchResults = await searchByObject(objectId)
      searchQuery = label
    } catch (cause) {
      showError(cause)
    } finally {
      searching = false
    }
  }

  function clearSearch(): void {
    searchQuery = ''
    searchResults = []
  }

  async function openSearchResult(result: SearchResult): Promise<void> {
    if (result.category === 'document' && result.documentId !== undefined) {
      await openDocument(result.documentId)
      if (result.occurrenceId !== undefined) {
        await tick()
        scrollToOccurrence(result.occurrenceId)
      }
      return
    }
    if (result.objectId !== undefined) {
      await openInspector(result.objectId)
      return
    }
    if (result.contextId !== undefined) {
      await selectContext(result.contextId)
      return
    }
    if (result.tagId !== undefined) await toggleTag(result.tagId)
  }

  async function toggleTag(tagId: string): Promise<void> {
    selectedTagIds = selectedTagIds.includes(tagId)
      ? selectedTagIds.filter((id) => id !== tagId)
      : [...selectedTagIds, tagId]
    await refreshFilters()
  }

  async function withTags(operation: () => Promise<void>): Promise<void> {
    error = ''
    try {
      await operation()
      tags = await listTags()
      await refreshFilters()
    } catch (cause) {
      showError(cause)
    }
  }

  async function changeDocumentTag(tagId: string, assign: boolean): Promise<void> {
    const document = selected
    if (!document) return
    await withTags(async () => {
      documentTags = assign
        ? await assignTag(document.id, tagId)
        : await unassignTag(document.id, tagId)
    })
  }

  async function addNewDocumentTag(name: string): Promise<void> {
    const document = selected
    if (!document) return
    await withTags(async () => {
      const tag = await createTag(name)
      documentTags = await assignTag(document.id, tag.id)
    })
  }

  async function selectContext(contextId: string | null): Promise<void> {
    selectedContextId = contextId
    await refreshFilters()
  }

  async function changeContextMode(mode: ContextMode): Promise<void> {
    contextMode = mode
    await refreshFilters()
  }

  /**
   * Creates a Context while marking a fragment: the index grows while reading, which is the only
   * moment when the user knows what the note is about.
   */
  /**
   * Moves a Concept or an Entity to the trash. Nothing is destroyed: the marks stay in the text and
   * the object comes back whole from the trash, so the gesture costs nothing to try.
   */
  async function moveObjectToTrash(objectId: string): Promise<void> {
    error = ''
    try {
      await trashKnowledgeObject(objectId)
      if (inspector?.id === objectId) inspector = null
      compareQueue = compareQueue.filter((entry) => entry.id !== objectId)
      await refreshTrash()
      await refreshFilters()
    } catch (cause) {
      showError(cause)
    }
  }

  /** Moves a Context to the trash: the ranges stay in the text, ready to come back. */
  async function moveContextToTrash(contextId: string): Promise<void> {
    error = ''
    try {
      await trashContext(contextId)
      if (selectedContextId === contextId) selectedContextId = null
      contexts = await listContexts()
      await refreshTrash()
      await refreshFilters()
    } catch (cause) {
      showError(cause)
    }
  }

  /** The Document lifecycle already has a trash: the × uses it, it does not invent a second one. */
  async function moveDocumentToTrash(documentId: string): Promise<void> {
    error = ''
    if (selected?.id === documentId && autosaveTimer !== undefined) {
      clearTimeout(autosaveTimer)
      autosaveTimer = undefined
    }
    try {
      await setDocumentLifecycle(documentId, 'trash')
      documents = documents.filter((document) => document.id !== documentId)
      if (selected?.id === documentId) {
        selected = null
        draftJson = null
      }
    } catch (cause) {
      showError(cause)
    }
  }

  async function restoreFromTrash(kind: 'object' | 'context', id: string): Promise<void> {
    error = ''
    try {
      if (kind === 'object') await trashKnowledgeObject(id, false)
      else {
        await trashContext(id, false)
        contexts = await listContexts()
      }
      await refreshTrash()
      await refreshFilters()
    } catch (cause) {
      showError(cause)
    }
  }

  async function purgeFromTrash(kind: 'object' | 'context', id: string): Promise<void> {
    error = ''
    try {
      if (kind === 'object') await deleteKnowledgeObject(id)
      else await deleteContext(id)
      await refreshTrash()
      await reloadAfterDeletion()
    } catch (cause) {
      showError(cause)
    }
  }

  async function refreshTrash(): Promise<void> {
    trash = await fetchTrash()
  }

  /** Reloads what a deletion may have rewritten: the open Document, the lists and the filters. */
  async function reloadAfterDeletion(): Promise<void> {
    if (selected) {
      applyDocument(await getDocument(selected.id))
      editorReloadToken += 1
    }
    contexts = await listContexts()
    await refreshFilters()
  }

  async function createContextForRange(name: string, parentId: string | null): Promise<ContextNode> {
    const created = await createContext(name, parentId)
    contexts = await listContexts()
    return created
  }

  async function withContexts(operation: () => Promise<void>): Promise<void> {
    error = ''
    try {
      await operation()
      contexts = await listContexts()
      await refreshFilters()
    } catch (cause) {
      showError(cause)
    }
  }

  async function changeScope(next: DocumentStatus): Promise<void> {
    if (scope === next) return
    scope = next
    error = ''
    try {
      await reloadDocuments()
    } catch (cause) {
      showError(cause)
    }
  }

  /** Archive, trash and restore are reversible and never touch content or occurrence. */
  async function changeLifecycle(action: 'archive' | 'trash' | 'restore'): Promise<void> {
    if (!selected || saving) return
    // Un salvataggio automatico in attesa scriverebbe su un Document appena archiviato o cestinato.
    if (autosaveTimer !== undefined) clearTimeout(autosaveTimer)
    autosaveTimer = undefined
    if (dirty && !window.confirm('Le modifiche non salvate andranno perse. Continuare?')) return
    saving = true
    error = ''
    try {
      applyDocument(await setDocumentLifecycle(selected.id, action))
      await reloadDocuments()
    } catch (cause) {
      showError(cause)
    } finally {
      saving = false
    }
  }

  async function addDocument(): Promise<void> {
    if (saving) return
    if (dirty && !window.confirm('Le modifiche non salvate andranno perse. Continuare?')) return
    // La creazione passa dal server: senza questo blocco si potrebbe scrivere nel documento
    // precedente, o crearne due, mentre il nuovo sta arrivando.
    saving = true
    error = ''
    try {
      const created = await createDocument()
      documents = [created, ...documents]
      applyDocument(created)
    } catch (cause) {
      showError(cause)
    } finally {
      saving = false
    }
  }

  async function persist(): Promise<void> {
    if (!selected || !draftJson || saving) return
    const version = draftVersion
    saving = true
    error = ''
    try {
      const creates = deriveOccurrenceCreates(draftJson, persistedOccurrenceIds, pendingObjects)
      const contextCreates = deriveContextCreates(draftJson, persistedContextOccurrenceIds)
      // I conteggi dei Context vivono sui frammenti: se il salvataggio ne aggiunge o ne toglie,
      // la barra laterale va riletta, altrimenti spiegherebbe una situazione che non c'e piu.
      const rangesChanged = contextCreates.length > 0
        || collectContextOccurrences(draftJson).size !== persistedContextOccurrenceIds.size
      const saved = await saveDocument(selected, draftTitle, draftJson, creates, contextCreates)
      for (const create of creates) {
        if (create.newObject) pendingObjects.delete(create.knowledgeObjectId)
      }
      // Con il salvataggio automatico si scrive mentre la richiesta viaggia: la risposta non deve
      // riportare indietro il testo digitato nel frattempo, solo prendere atto della revisione.
      if (draftVersion === version) applyDocument(saved)
      else adoptRevision(saved)
      if (rangesChanged) contexts = await listContexts()
      // Il salvataggio ha l'ultima parola sulla lista: una richiesta piu vecchia non deve tornarci sopra.
      documentsFetch += 1
      documents = [saved, ...documents.filter((document) => document.id !== saved.id)]
      await refreshInspector()
    } catch (cause) {
      showError(cause)
    } finally {
      saving = false
    }
  }

  /**
   * Takes the saved revision without touching the draft: what is in the editor is newer, so it
   * stays, and another automatic save will carry it.
   */
  function adoptRevision(document: DocumentRecord): void {
    selected = document
    persistedOccurrenceIds = new Set(collectOccurrences(document.documentJson).keys())
    persistedContextOccurrenceIds = new Set(collectContextOccurrences(document.documentJson).keys())
    dirty = true
    scheduleAutosave()
  }

  function applyDocument(document: DocumentRecord): void {
    // A KnowledgeObject created but not yet persisted survives a save of the same Document: undo
    // can bring its mark back and the next save still has to declare its creation.
    const sameDocument = selected?.id === document.id
    // Un salvataggio automatico in attesa riguarda la revisione appena sostituita: non deve partire.
    if (autosaveTimer !== undefined) clearTimeout(autosaveTimer)
    autosaveTimer = undefined
    selected = document
    draftTitle = document.title
    draftJson = document.documentJson
    dirty = false
    persistedOccurrenceIds = new Set(collectOccurrences(document.documentJson).keys())
    persistedContextOccurrenceIds = new Set(collectContextOccurrences(document.documentJson).keys())
    if (!sameDocument) void loadDocumentTags(document.id)
    if (!sameDocument) pendingObjects = new Map()
    void tick().then(resizeTitleInput)
  }

  async function loadDocumentTags(documentId: string): Promise<void> {
    try {
      documentTags = await listDocumentTags(documentId)
    } catch (cause) {
      console.warn('Tag del documento non disponibili.', cause)
      documentTags = []
    }
  }

  function changeTitle(value: string): void {
    draftTitle = value
    dirty = true
    draftVersion += 1
    scheduleAutosave()
    void tick().then(resizeTitleInput)
  }

  function changeContent(content: JSONContent): void {
    draftJson = content
    dirty = true
    draftVersion += 1
    scheduleAutosave()
  }

  /**
   * A new Concept, Entity or Context is saved at once: the index is what the user is building, and
   * losing it to a closed tab would cost more than a write. The plain typing waits for a pause.
   */
  function addPendingObject(knowledgeObjectId: string, object: PendingKnowledgeObject): void {
    pendingObjects.set(knowledgeObjectId, object)
    dirty = true
    draftVersion += 1
    scheduleAutosave(0)
  }

  /**
   * Saves on its own after a pause in the typing. A save already running is not interrupted: the
   * next tick reschedules, so nothing is lost and no two writes race on the same revision.
   */
  function scheduleAutosave(delay: number = AUTOSAVE_DELAY_MS): void {
    if (autosaveTimer !== undefined) clearTimeout(autosaveTimer)
    autosaveTimer = setTimeout(() => {
      autosaveTimer = undefined
      if (!dirty || readOnly) return
      if (saving) {
        scheduleAutosave(AUTOSAVE_RETRY_MS)
        return
      }
      void persist()
    }, delay)
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
      blocks = inspector.objectType === 'entity' ? await listBlocks(knowledgeObjectId) : []
      relations = await listRelations(knowledgeObjectId)
      relationEvidence = null
      if (templates.length === 0) templates = await listTemplates()
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

  /** Relations are declared, never inferred from documents, contexts or tags. */
  async function openRelationDialog(): Promise<void> {
    if (relationTypes.length === 0) {
      try {
        relationTypes = await listRelationTypes()
      } catch (cause) {
        console.warn('Predicati suggeriti non disponibili.', cause)
      }
    }
    addingRelation = true
  }

  async function changeRelations(operation: () => Promise<RelationView[]>): Promise<void> {
    await withInspectorBusy(async () => {
      relations = await operation()
    })
  }

  /** The comparison works on persisted knowledge only: nothing is generated. */
  function addToCompare(): void {
    const object = inspector
    if (!object || compareQueue.some((entry) => entry.id === object.id)) return
    compareQueue = [...compareQueue, { id: object.id, name: object.name }]
  }

  async function runComparison(): Promise<void> {
    error = ''
    try {
      comparison = await compareObjects(compareQueue.map((entry) => entry.id))
    } catch (cause) {
      showError(cause)
    }
  }

  /** Provenance: which already existing data supports the relation. */
  async function showEvidence(relationId: string): Promise<void> {
    if (relationEvidence?.relationId === relationId) {
      relationEvidence = null
      return
    }
    await withInspectorBusy(async () => {
      relationEvidence = { relationId, items: await listRelationEvidence(relationId) }
    })
  }

  async function changeEvidence(relationId: string, operation: () => Promise<EvidenceView[]>): Promise<void> {
    await withInspectorBusy(async () => {
      relationEvidence = { relationId, items: await operation() }
    })
  }

  /** Structured data belong to the Entity: they never touch the document content. */
  async function changeBlocks(operation: () => Promise<SemanticBlock[]>): Promise<void> {
    await withInspectorBusy(async () => {
      blocks = await operation()
    })
  }

  async function renameObject(name: string, description: string | null): Promise<void> {
    const object = inspector
    if (!object) return
    await withInspectorBusy(async () => {
      inspector = await updateKnowledgeObject(object.id, { name, description })
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
  <meta name="description" content="Chaorganix: Concept, Entity e Context sui frammenti per organizzare il caos degli appunti" />
</svelte:head>

<div class="app-frame" class:with-inspector={inspector !== null}>
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark" aria-hidden="true">C</span>
      <div>
        <strong>Chaorganix</strong>
        <small>Organize the chaos of knowledge</small>
      </div>
    </div>

    <button class="new-document" type="button" disabled={saving} onclick={addDocument}>+ Nuovo documento</button>

    <SearchPanel
      results={searchResults}
      query={searchQuery}
      {searching}
      onSearch={(query) => void runSearch(query)}
      onClear={clearSearch}
      onOpen={(result) => void openSearchResult(result)}
      onShowOccurrences={(result) => void showOccurrencesOf(result.objectId ?? '', result.label)}
    />

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

    <ContextPanel
      {contexts}
      selectedId={selectedContextId}
      mode={contextMode}
      busy={saving}
      onSelect={(contextId) => void selectContext(contextId)}
      onModeChange={(mode) => void changeContextMode(mode)}
      onCreate={(name, parentId) => void withContexts(async () => {
        await createContext(name, parentId)
      })}
      onRename={(contextId, name) => void withContexts(async () => {
        await renameContext(contextId, name)
      })}
      onMove={(contextId, parentId) => void withContexts(async () => {
        await moveContext(contextId, parentId)
      })}
      onDelete={(contextId) => void moveContextToTrash(contextId)}
    />

    <TagPanel
      {tags}
      selectedIds={selectedTagIds}
      busy={saving}
      onToggle={(tagId) => void toggleTag(tagId)}
      onCreate={(name) => void withTags(async () => {
        await createTag(name)
      })}
      onRename={(tagId, name) => void withTags(async () => {
        await renameTag(tagId, name)
      })}
      onDelete={(tagId) => void withTags(async () => {
        await deleteTag(tagId)
        selectedTagIds = selectedTagIds.filter((id) => id !== tagId)
      })}
    />

    <TemplatePanel
      {templates}
      busy={saving}
      onCreate={(name) => void withTemplates(async () => {
        await createTemplate(name)
      })}
      onAddField={(templateId, input) => void withTemplates(async () => {
        await addTemplateField(templateId, input)
      })}
      searchResults={structuredResults}
      searching={structuredSearching}
      onSearch={(fieldId, operator, value, withFilters) => void runStructuredSearch(fieldId, operator, value, withFilters)}
      onOpenEntity={(entityId) => void openInspector(entityId)}
    />

    <TrashPanel
      contents={trash}
      busy={saving}
      onRestoreObject={(objectId) => void restoreFromTrash('object', objectId)}
      onRestoreContext={(contextId) => void restoreFromTrash('context', contextId)}
      onPurgeObject={(objectId) => void purgeFromTrash('object', objectId)}
      onPurgeContext={(contextId) => void purgeFromTrash('context', contextId)}
    />

    <button
      type="button"
      class="matrix-open"
      title={matrixStrings.open.description}
      onclick={() => (showingMatrix = true)}
    >{matrixStrings.open.label}</button>

    {#if selectedContextId !== null || selectedTagIds.length > 0}
      <DerivedObjects
        objects={contextObjects}
        busy={saving}
        onOpen={(objectId) => void openInspector(objectId)}
        onTrash={(objectId) => void moveObjectToTrash(objectId)}
      />
    {/if}

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
            <div class="document-row">
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
              {#if scope === 'active'}
                <RemoveButton
                  label={document.title || 'Senza titolo'}
                  disabled={saving}
                  description={trashStrings.trashDocument.description}
                  confirmDescription={trashStrings.trashDocumentConfirm}
                  onRemove={() => void moveDocumentToTrash(document.id)}
                />
              {/if}
            </div>
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

      <DocumentTags
        tags={documentTags}
        available={tags}
        disabled={saving || readOnly}
        onAssign={(tagId) => void changeDocumentTag(tagId, true)}
        onUnassign={(tagId) => void changeDocumentTag(tagId, false)}
        onCreateAndAssign={(name) => void addNewDocumentTag(name)}
      />

      {#key selected.id}
        <DocumentEditor
          documentId={selected.id}
          initialContent={draftJson}
          editable={!readOnly}
          {contexts}
          reloadToken={editorReloadToken}
          onChange={changeContent}
          onObjectCreate={addPendingObject}
          onOpenInspector={(knowledgeObjectId) => void openInspector(knowledgeObjectId)}
          onContextCreate={createContextForRange}
          onOpenContext={(contextId) => void selectContext(contextId)}
          onIndexChange={() => scheduleAutosave(0)}
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
    {#if compareQueue.length > 0}
      <div class="compare-tray" aria-label={compareStrings.trayLabel}>
        <span>{compareStrings.trayLabel}</span>
        {#each compareQueue as entry (entry.id)}
          <span class="document-tag">
            {entry.name}
            <button
              type="button"
              aria-label={compareStrings.remove(entry.name)}
              onclick={() => (compareQueue = compareQueue.filter((row) => row.id !== entry.id))}
            >×</button>
          </span>
        {/each}
        <button
          type="button"
          class="lifecycle-button"
          disabled={compareQueue.length < 2}
          title={compareStrings.run.description}
          onclick={() => void runComparison()}
        >{compareStrings.run.label}</button>
        <button
          type="button"
          class="lifecycle-button"
          title={compareStrings.clear.description}
          onclick={() => (compareQueue = [])}
        >{compareStrings.clear.label}</button>
      </div>
    {/if}
  </main>

  {#if comparison !== null}
    <CompareDialog {comparison} onClose={() => (comparison = null)} />
  {/if}

  {#if showingMatrix}
    <MatrixDialog
      {contexts}
      {templates}
      onOpenDocument={(documentId) => {
        showingMatrix = false
        void openDocument(documentId)
      }}
      onError={(message) => (error = message)}
      onClose={() => (showingMatrix = false)}
    />
  {/if}

  {#if addingRelation && inspector}
    <RelationDialog
      sourceName={inspector.name}
      suggestions={relationTypes}
      onSearch={searchKnowledge}
      onCancel={() => (addingRelation = false)}
      onConfirm={(input) => {
        addingRelation = false
        void changeRelations(async () => {
          const updated = await createRelation(inspector?.id ?? '', input)
          relationTypes = await listRelationTypes()
          return updated
        })
      }}
    />
  {/if}

  {#if inspector}
    {@const shown = inspectorObject ?? inspector}
    <KnowledgeInspector
      object={shown}
      busy={inspectorBusy}
      {duplicateCandidates}
      onClose={() => (inspector = null)}
      onDelete={() => void moveObjectToTrash(shown.id)}
      onAddAlias={(alias) => void addAlias(alias)}
      onRemoveAlias={(aliasId) => void removeAlias(aliasId)}
      onAddIdentifier={(input) => void addIdentifier(input)}
      onRemoveIdentifier={(identifierId) => void removeIdentifier(identifierId)}
      onRename={(name, description) => void renameObject(name, description)}
      {blocks}
      {templates}
      onAddBlock={(templateId) => void changeBlocks(() => addBlock(inspector?.id ?? '', templateId))}
      onRemoveBlock={(blockId) => void changeBlocks(() => removeBlock(blockId))}
      onSetValues={(blockId, fieldId, values) => void changeBlocks(() => setBlockValues(blockId, fieldId, values))}
      {relations}
      onAddRelation={() => void openRelationDialog()}
      onRemoveRelation={(relationId) => void changeRelations(() => deleteRelation(inspector?.id ?? '', relationId))}
      onOpenRelated={(objectId) => void openInspector(objectId)}
      evidence={relationEvidence}
      canAddDocumentEvidence={selected !== null}
      onShowEvidence={(relationId) => void showEvidence(relationId)}
      onAddDocumentEvidence={(relationId) => void changeEvidence(relationId, () =>
        addRelationEvidence(relationId, { family: 'document', destinationId: selected?.id ?? '' }))}
      onRemoveEvidence={(relationId, family, evidenceId) => void changeEvidence(relationId, () =>
        removeRelationEvidence(relationId, family, evidenceId))}
      onAddToCompare={addToCompare}
      onToggleArchived={() => void toggleInspectorArchived()}
      onToggleEntityTypeArchived={() => void toggleInspectorEntityTypeArchived()}
      onOpenOccurrence={(occurrence) => void openOccurrence(occurrence)}
    />
  {/if}
</div>

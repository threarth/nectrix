<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { Editor, type JSONContent } from '@tiptap/core'
  import { onDestroy, onMount } from 'svelte'
  import {
    defaultHighlightColors,
    editorExtensions,
    normalizeHighlightColor,
    occurrenceClipboardExtension,
    occurrenceFreeRanges,
    referenceDestinations,
    referenceLabelsExtension,
    occurrenceRangeAt,
    removeOccurrenceMarks,
    type HighlightColor,
  } from '../lib/editor'
  import {
    collectOccurrences,
    isOccurrenceAttributes,
    type KnowledgeObjectType,
    type OccurrenceAttributes,
    type PendingKnowledgeObject,
  } from '../lib/occurrences'
  import {
    MAX_HIGHLIGHT_COLORS,
    MIN_HIGHLIGHT_COLORS,
    readHighlightPalette,
    writeHighlightPalette,
  } from '../lib/highlightPalette'
  import { uuidV7 } from '../lib/uuid'
  import {
    createEntityType,
    listBlocks,
    listEntityTypes,
    resolveKnowledgeObjects,
    resolveReferences,
    searchKnowledge,
    type EntityType,
    type KnowledgeSearchResult,
  } from '../lib/api'
  import AttachDialog from './AttachDialog.svelte'
  import ReferenceDialog from './ReferenceDialog.svelte'
  import ConceptDialog from './ConceptDialog.svelte'
  import EntityDialog from './EntityDialog.svelte'
  import {
    editorStrings,
    highlightPopoverStrings,
    occurrencePopoverStrings,
    palettePanelStrings,
    referenceStrings,
  } from '../lib/strings'
  import ToolbarButton from './ToolbarButton.svelte'

  let {
    documentId,
    initialContent,
    editable = true,
    onChange,
    onObjectCreate,
    onOpenInspector,
  }: {
    documentId: string
    initialContent: JSONContent
    /** An archived or trashed Document is shown without the editing commands. */
    editable?: boolean
    onChange: (content: JSONContent) => void
    onObjectCreate: (knowledgeObjectId: string, object: PendingKnowledgeObject) => void
    onOpenInspector: (knowledgeObjectId: string) => void
  } = $props()

  let element: HTMLDivElement
  let editorShell: HTMLDivElement
  let toolbarElement = $state<HTMLDivElement | undefined>(undefined)
  let editorState = $state<{ editor: Editor | null }>({ editor: null })
  let editorPopover = $state<{
    color: HighlightColor | null
    occurrence: OccurrenceAttributes | null
    selectionActive: boolean
    above: boolean
    left: number
    top: number
  } | null>(null)
  let highlightPalette = $state<string[]>([...defaultHighlightColors])
  let paletteSelectorOpen = $state(false)
  let clipboardWarning = $state('')

  /** Discriminator of the KnowledgeObject already known to be valid, to avoid useless lookups. */
  const knownObjectTypes = new Map<string, 'concept' | 'entity'>()

  /**
   * A Concept or an Entity carries its own visual identity, so it cannot be highlighted: the
   * command stays available only where a Highlight would actually be visible.
   */
  const canHighlight = $derived.by(() => {
    const editor = editorState.editor
    if (!editor || !editable) return false
    const { from, to, empty } = editor.state.selection
    if (empty) return occurrenceRangeAt(editor.state, from) === null
    return editor.isActive('highlight') || occurrenceFreeRanges(editor.state, from, to).length > 0
  })

  /** Applies the Highlight only outside the occurrences, leaving them untouched. */
  function toggleHighlight(): void {
    const editor = editorState.editor
    if (!editor || !canHighlight) return
    if (editor.isActive('highlight')) {
      editor.chain().focus().unsetMark('highlight').run()
      return
    }

    const { from, to } = editor.state.selection
    const ranges = occurrenceFreeRanges(editor.state, from, to)
    if (ranges.length === 0) {
      editor.chain().focus().setMark('highlight').run()
      return
    }
    const chain = editor.chain().focus()
    for (const range of ranges) chain.setTextSelection(range).setMark('highlight')
    chain.setTextSelection({ from, to }).run()
  }

  /** Distance kept from the toolbar and from the marked line, so nothing overlaps. */
  const POPOVER_MARGIN = 8

  /** Room needed above the line to place the popover there instead of over the text below. */
  const POPOVER_ROOM = 56

  /** Range the open dialog will mark, remembered because the dialog takes the focus. */
  let pendingCommand = $state<{ kind: 'concept' | 'entity' | 'attach'; text: string; from: number; to: number } | null>(null)
  let entityTypes = $state<EntityType[]>([])
  let dialogBusy = $state(false)
  let dialogError = $state('')
  let referencing = $state(false)

  /** Labels of the reference destinations, resolved from the API and never written in the content. */
  const referenceLabels = new Map<string, string>()

  onMount(() => {
    highlightPalette = readHighlightPalette()
    for (const occurrence of collectOccurrences(initialContent).values()) {
      knownObjectTypes.set(occurrence.knowledgeObjectId, occurrence.objectType)
    }
    const editor = new Editor({
      element,
      extensions: [
        ...editorExtensions,
        occurrenceClipboardExtension({
          documentId,
          createId: uuidV7,
          onPaste: (occurrences) => void verifyPastedOccurrences(occurrences),
        }),
        referenceLabelsExtension({
          label: (node, destinationId) => referenceLabels.get(`${node}:${destinationId}`),
          fallback: referenceStrings.unresolved,
        }),
      ],
      content: initialContent,
      editable,
      onTransaction: ({ editor, transaction }) => {
        editorState = { editor }
        syncEditorPopover(editor)
        if (transaction.docChanged) {
          onChange(editor.getJSON())
          void loadReferenceLabels(editor)
        }
      },
      onSelectionUpdate: ({ editor }) => syncEditorPopover(editor),
    })
    editorState = { editor }
    void loadReferenceLabels(editor)
  })

  onDestroy(() => editorState.editor?.destroy())

  $effect(() => {
    editorState.editor?.setEditable(editable)
  })

  function run(command: (editor: Editor) => void): void {
    const editor = editorState.editor
    if (editor) command(editor)
  }

  /**
   * Resolves the labels of the destinations still unknown and redraws, so the reference shows what
   * it points at without the document ever storing that text.
   */
  async function loadReferenceLabels(editor: Editor): Promise<void> {
    const { entities, blocks } = referenceDestinations(editor.state)
    const missingEntities = entities.filter((id) => !referenceLabels.has(`entityReference:${id}`))
    const missingBlocks = blocks.filter((id) => !referenceLabels.has(`semanticBlockReference:${id}`))
    if (missingEntities.length === 0 && missingBlocks.length === 0) return

    try {
      const resolved = await resolveReferences(missingEntities, missingBlocks)
      for (const entity of resolved.entities) referenceLabels.set(`entityReference:${entity.id}`, entity.label)
      for (const block of resolved.semanticBlocks) {
        referenceLabels.set(`semanticBlockReference:${block.id}`, `${block.detail} · ${block.label}`)
      }
      if (!editor.isDestroyed) editor.view.dispatch(editor.state.tr.setMeta('addToHistory', false))
    } catch (cause) {
      console.warn('Etichette dei riferimenti non disponibili.', cause)
    }
  }

  function insertReference(destination: { kind: 'entityReference' | 'semanticBlockReference'; id: string }): void {
    const editor = editorState.editor
    if (!editor) return
    const attrs = destination.kind === 'entityReference'
      ? { referenceId: uuidV7(), entityId: destination.id }
      : { referenceId: uuidV7(), semanticBlockId: destination.id }
    editor.chain().focus().insertContent({ type: destination.kind, attrs }).run()
    referencing = false
    void loadReferenceLabels(editor)
  }

  /** Opens the dialog of a semantic command on the current selection. */
  function openCommand(kind: 'concept' | 'entity' | 'attach'): void {
    const editor = editorState.editor
    if (!editor || editor.state.selection.empty) return
    const { from, to } = editor.state.selection
    dialogError = ''
    pendingCommand = { kind, from, to, text: editor.state.doc.textBetween(from, to, ' ') }
    if (kind === 'entity') void loadEntityTypes()
  }

  function closeCommand(): void {
    pendingCommand = null
    dialogError = ''
    editorState.editor?.commands.focus()
  }

  async function loadEntityTypes(): Promise<void> {
    try {
      entityTypes = await listEntityTypes()
    } catch (cause) {
      dialogError = cause instanceof Error ? cause.message : 'EntityType non disponibili.'
    }
  }

  /** Marks the remembered range, which the dialog may have moved the selection away from. */
  function markRange(range: { from: number; to: number }, knowledgeObjectId: string, objectType: KnowledgeObjectType): void {
    const editor = editorState.editor
    if (!editor) return
    editor.chain().focus().setTextSelection(range)
      .unsetMark('highlight')
      .setMark('knowledgeOccurrence', { occurrenceId: uuidV7(), knowledgeObjectId, objectType })
      .run()
    knownObjectTypes.set(knowledgeObjectId, objectType)
  }

  function confirmConcept(name: string): void {
    const command = pendingCommand
    if (!command) return
    const knowledgeObjectId = uuidV7()
    markRange(command, knowledgeObjectId, 'concept')
    onObjectCreate(knowledgeObjectId, { objectType: 'concept', name })
    closeCommand()
  }

  async function confirmEntity(name: string, entityTypeName: string): Promise<void> {
    const command = pendingCommand
    if (!command || dialogBusy) return
    dialogBusy = true
    dialogError = ''
    try {
      const entityType = await createEntityType(entityTypeName)
      if (entityType.status === 'archived') {
        dialogError = 'L’EntityType è archiviato: ripristinalo dall’inspector prima di usarlo.'
        return
      }
      const knowledgeObjectId = uuidV7()
      markRange(command, knowledgeObjectId, 'entity')
      onObjectCreate(knowledgeObjectId, { objectType: 'entity', name, entityTypeId: entityType.id })
      closeCommand()
    } catch (cause) {
      dialogError = cause instanceof Error ? cause.message : 'Impossibile creare l’EntityType.'
    } finally {
      dialogBusy = false
    }
  }

  function confirmAttach(result: KnowledgeSearchResult): void {
    const command = pendingCommand
    if (!command) return
    markRange(command, result.id, result.object_type)
    closeCommand()
  }

  /**
   * INV-OCC-15: a pasted mark keeps its KnowledgeObject only if the database confirms both the
   * existence and the discriminator. Otherwise only the mark is removed, with a non blocking notice.
   */
  async function verifyPastedOccurrences(occurrences: OccurrenceAttributes[]): Promise<void> {
    const editor = editorState.editor
    clipboardWarning = ''
    const unverified = occurrences.filter((occurrence) => !isKnownObject(occurrence))
    if (!editor || unverified.length === 0) return

    try {
      const resolved = await resolveKnowledgeObjects([...new Set(unverified.map((occurrence) => occurrence.knowledgeObjectId))])
      for (const object of resolved) knownObjectTypes.set(object.id, object.object_type)
    } catch (cause) {
      clipboardWarning = cause instanceof Error
        ? `Verifica degli oggetti incollati non riuscita: ${cause.message}`
        : 'Verifica degli oggetti incollati non riuscita.'
      return
    }

    const invalid = occurrences.filter((occurrence) => !isKnownObject(occurrence))
    if (invalid.length === 0) return
    removeOccurrenceMarks(editor, new Set(invalid.map((occurrence) => occurrence.occurrenceId)))
    clipboardWarning = invalid.length === 1
      ? 'Un elemento incollato faceva riferimento a un Concept o a una Entity non validi: il testo è stato mantenuto senza associazione.'
      : `${invalid.length} elementi incollati facevano riferimento a Concept o Entity non validi: il testo è stato mantenuto senza associazione.`
  }

  function isKnownObject(occurrence: OccurrenceAttributes): boolean {
    return knownObjectTypes.get(occurrence.knowledgeObjectId) === occurrence.objectType
  }

  /**
   * One popover for the marks under the caret: the highlight colours when the range is highlighted
   * and the way into the inspector when it is a KnowledgeOccurrence. The two can coexist.
   */
  function syncEditorPopover(editor: Editor): void {
    const selectionActive = editable && !editor.state.selection.empty
    const highlighted = editable && editor.isActive('highlight') && occurrenceRangeAt(editor.state, editor.state.selection.from) === null
    const attributes = editor.getAttributes('knowledgeOccurrence')
    const occurrence = isOccurrenceAttributes(attributes) ? attributes : null
    if (!editorShell || (!highlighted && occurrence === null && !selectionActive)) {
      editorPopover = null
      return
    }

    const coordinates = editor.view.coordsAtPos(editor.state.selection.from)
    const shell = editorShell.getBoundingClientRect()
    // Sotto la riga corrente e sempre sotto la toolbar, che puo occupare piu righe: i comandi
    // contestuali non devono mai coprire quelli della barra.
    const toolbarBottom = toolbarElement === undefined
      ? 0
      : toolbarElement.getBoundingClientRect().bottom - shell.top
    // Sopra la riga quando c'è spazio fra toolbar e testo, così non copre le righe successive;
    // altrimenti sotto. Sopra si posiziona per il proprio bordo inferiore, senza misurarne l'altezza.
    const lineTop = coordinates.top - shell.top
    const above = lineTop - POPOVER_MARGIN - toolbarBottom >= POPOVER_ROOM
    editorPopover = {
      color: highlighted ? normalizeHighlightColor(editor.getAttributes('highlight').color) : null,
      occurrence,
      selectionActive,
      above,
      left: Math.max(POPOVER_MARGIN, Math.min(coordinates.left - shell.left - 84, shell.width - 238)),
      top: above ? lineTop - POPOVER_MARGIN : coordinates.bottom - shell.top + POPOVER_MARGIN,
    }
  }

  function keepSelection(event: PointerEvent): void {
    event.preventDefault()
  }

  function changeHighlightColor(color: HighlightColor): void {
    applyToHighlightBlock((editor) => editor.chain().focus().extendMarkRange('highlight').setMark('highlight', { color }), false)
  }

  function removeHighlight(): void {
    applyToHighlightBlock((editor) => editor.chain().focus().extendMarkRange('highlight').unsetMark('highlight'), true)
  }

  function changePaletteSize(value: string): void {
    const size = Number(value)
    if (!Number.isInteger(size) || size < MIN_HIGHLIGHT_COLORS || size > MAX_HIGHLIGHT_COLORS) return
    if (size <= highlightPalette.length) {
      highlightPalette = highlightPalette.slice(0, size)
    } else {
      highlightPalette = [...highlightPalette, ...defaultHighlightColors.slice(highlightPalette.length, size)]
    }
    writeHighlightPalette(highlightPalette)
  }

  function updatePaletteColor(index: number, color: string): void {
    highlightPalette = highlightPalette.map((savedColor, savedIndex) => savedIndex === index ? normalizeHighlightColor(color) : savedColor)
    writeHighlightPalette(highlightPalette)
  }

  function resetHighlightPalette(): void {
    highlightPalette = [...defaultHighlightColors]
    writeHighlightPalette(highlightPalette)
  }

  function applyToHighlightBlock(command: (editor: Editor) => ReturnType<Editor['chain']>, closePopover: boolean): void {
    const editor = editorState.editor
    if (!editor) return

    const position = editor.state.selection.from
    command(editor).setTextSelection(position).run()
    if (closePopover) {
      editor.commands.blur()
      editorPopover = null
    } else {
      syncEditorPopover(editor)
    }
  }
</script>

<div class="editor-shell" bind:this={editorShell}>
  {#if editorState.editor && editable}
    <div class="toolbar" role="toolbar" bind:this={toolbarElement} aria-label={editorStrings.toolbarLabel}>
      <ToolbarButton
        command={editorStrings.paragraph}
        toggle
        active={editorState.editor.isActive('paragraph')}
        onclick={() => run((editor) => editor.chain().focus().setParagraph().run())}
      />
      {#each [1, 2, 3] as level}
        <ToolbarButton
          command={editorStrings.heading(level)}
          toggle
          active={editorState.editor.isActive('heading', { level })}
          onclick={() => run((editor) => editor.chain().focus().toggleHeading({ level: level as 1 | 2 | 3 }).run())}
        />
      {/each}
      <span class="toolbar-divider" aria-hidden="true"></span>
      <ToolbarButton
        command={editorStrings.bold}
        toggle
        active={editorState.editor.isActive('bold')}
        onclick={() => run((editor) => editor.chain().focus().toggleBold().run())}
      ><strong>{editorStrings.bold.label}</strong></ToolbarButton>
      <ToolbarButton
        command={editorStrings.italic}
        toggle
        active={editorState.editor.isActive('italic')}
        onclick={() => run((editor) => editor.chain().focus().toggleItalic().run())}
      ><em>{editorStrings.italic.label}</em></ToolbarButton>
      <ToolbarButton
        command={editorStrings.underline}
        toggle
        active={editorState.editor.isActive('underline')}
        onclick={() => run((editor) => editor.chain().focus().toggleUnderline().run())}
      ><u>{editorStrings.underline.label}</u></ToolbarButton>
      <ToolbarButton
        command={editorStrings.highlight}
        toggle
        active={editorState.editor.isActive('highlight')}
        disabled={!canHighlight}
        onclick={toggleHighlight}
      />
      <ToolbarButton
        command={editorStrings.createConcept}
        disabled={editorState.editor.state.selection.empty}
        onclick={() => openCommand('concept')}
      />
      <ToolbarButton
        command={editorStrings.createEntity}
        disabled={editorState.editor.state.selection.empty}
        onclick={() => openCommand('entity')}
      />
      <ToolbarButton
        command={editorStrings.attachExisting}
        disabled={editorState.editor.state.selection.empty}
        onclick={() => openCommand('attach')}
      />
      <ToolbarButton
        command={referenceStrings.command}
        onclick={() => (referencing = true)}
      />
      <ToolbarButton
        command={editorStrings.palette}
        toggle
        active={paletteSelectorOpen}
        onclick={() => (paletteSelectorOpen = !paletteSelectorOpen)}
      />
      <span class="toolbar-divider" aria-hidden="true"></span>
      <ToolbarButton
        command={editorStrings.bulletList}
        toggle
        active={editorState.editor.isActive('bulletList')}
        onclick={() => run((editor) => editor.chain().focus().toggleBulletList().run())}
      />
      <ToolbarButton
        command={editorStrings.orderedList}
        toggle
        active={editorState.editor.isActive('orderedList')}
        onclick={() => run((editor) => editor.chain().focus().toggleOrderedList().run())}
      />
      <ToolbarButton
        command={editorStrings.blockquote}
        toggle
        active={editorState.editor.isActive('blockquote')}
        onclick={() => run((editor) => editor.chain().focus().toggleBlockquote().run())}
      />
      <span class="toolbar-divider" aria-hidden="true"></span>
      <ToolbarButton
        command={editorStrings.undo}
        disabled={!editorState.editor.can().chain().focus().undo().run()}
        onclick={() => run((editor) => editor.chain().focus().undo().run())}
      />
      <ToolbarButton
        command={editorStrings.redo}
        disabled={!editorState.editor.can().chain().focus().redo().run()}
        onclick={() => run((editor) => editor.chain().focus().redo().run())}
      />
    </div>
    {#if paletteSelectorOpen}
      <div class="highlight-palette-selector" aria-label={palettePanelStrings.panelLabel}>
        <label title={palettePanelStrings.sizeDescription}>
          {palettePanelStrings.sizeLabel}
          <select value={String(highlightPalette.length)} onchange={(event) => changePaletteSize(event.currentTarget.value)}>
            {#each Array.from({ length: MAX_HIGHLIGHT_COLORS - MIN_HIGHLIGHT_COLORS + 1 }, (_, index) => MIN_HIGHLIGHT_COLORS + index) as size}
              <option value={String(size)}>{size}</option>
            {/each}
          </select>
        </label>
        <div class="highlight-palette-colors" aria-label={palettePanelStrings.colorsLabel}>
          {#each highlightPalette as color, index}
            <label
              aria-label={palettePanelStrings.colorDescription(index + 1)}
              title={palettePanelStrings.colorDescription(index + 1)}
              style={`background-color: ${color}`}
            >
              <input type="color" value={color} oninput={(event) => updatePaletteColor(index, event.currentTarget.value)} />
            </label>
          {/each}
        </div>
        <button type="button" title={palettePanelStrings.reset.description} onclick={resetHighlightPalette}>
          {palettePanelStrings.reset.label}
        </button>
      </div>
    {/if}
  {/if}
  {#if clipboardWarning}
    <div class="clipboard-warning" role="status">
      <span>{clipboardWarning}</span>
      <button type="button" aria-label="Chiudi avviso" onclick={() => (clipboardWarning = '')}>×</button>
    </div>
  {/if}
  <div class="editor-content" bind:this={element}></div>
  {#if referencing}
    <ReferenceDialog
      initialQuery={editorState.editor?.state.doc.textBetween(
        editorState.editor.state.selection.from,
        editorState.editor.state.selection.to,
        ' ',
      ) ?? ''}
      onSearch={searchKnowledge}
      onLoadBlocks={listBlocks}
      onCancel={() => (referencing = false)}
      onConfirm={insertReference}
    />
  {/if}
  {#if pendingCommand?.kind === 'concept'}
    <ConceptDialog initialName={pendingCommand.text} onCancel={closeCommand} onConfirm={confirmConcept} />
  {:else if pendingCommand?.kind === 'entity'}
    <EntityDialog
      initialName={pendingCommand.text}
      {entityTypes}
      busy={dialogBusy}
      error={dialogError}
      onCancel={closeCommand}
      onConfirm={(name, entityTypeName) => void confirmEntity(name, entityTypeName)}
    />
  {:else if pendingCommand?.kind === 'attach'}
    <AttachDialog
      initialQuery={pendingCommand.text}
      onSearch={searchKnowledge}
      onCancel={closeCommand}
      onConfirm={confirmAttach}
    />
  {/if}
  {#if editorState.editor && editorPopover}
    <div
      class="highlight-popover"
      class:above={editorPopover.above}
      role="dialog"
      aria-label={occurrencePopoverStrings.dialogLabel}
      style={`left: ${editorPopover.left}px; top: ${editorPopover.top}px`}
    >
      {#if editorPopover.selectionActive}
        <div class="popover-group" role="group" aria-label={editorStrings.selectionGroupLabel}>
          {#each [editorStrings.createConcept, editorStrings.createEntity, editorStrings.attachExisting] as command, index}
            <button
              type="button"
              class="popover-command"
              title={command.description}
              onpointerdown={keepSelection}
              onclick={() => openCommand((['concept', 'entity', 'attach'] as const)[index])}
            >{command.label}</button>
          {/each}
        </div>
      {/if}
      {#if editorPopover.occurrence}
        {@const occurrence = editorPopover.occurrence}
        <button
          type="button"
          class="occurrence-open"
          title={occurrencePopoverStrings.open.description}
          onpointerdown={keepSelection}
          onclick={() => onOpenInspector(occurrence.knowledgeObjectId)}
        >{occurrencePopoverStrings.open.label(occurrence.objectType)}</button>
      {/if}
      {#if editorPopover.color !== null}
        {@const activeColor = editorPopover.color}
        <span class="highlight-popover-label">{highlightPopoverStrings.colorLabel}</span>
        <div class="highlight-colors" aria-label={highlightPopoverStrings.colorsGroupLabel} role="group">
          {#each highlightPalette as color}
            <button
              type="button"
              class:active={activeColor === color}
              style={`background-color: ${color}`}
              aria-label={highlightPopoverStrings.colorDescription(color)}
              title={highlightPopoverStrings.colorDescription(color)}
              aria-pressed={activeColor === color}
              onpointerdown={keepSelection}
              onclick={() => changeHighlightColor(color)}
            ></button>
          {/each}
        </div>
        <button
          type="button"
          class="highlight-remove"
          title={highlightPopoverStrings.remove.description}
          onpointerdown={keepSelection}
          onclick={removeHighlight}
        >{highlightPopoverStrings.remove.label}</button>
      {/if}
    </div>
  {/if}
</div>

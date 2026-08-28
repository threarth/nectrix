<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { Editor, type JSONContent } from '@tiptap/core'
  import { onDestroy, onMount } from 'svelte'
  import {
    defaultHighlightColors,
    editorExtensions,
    normalizeHighlightColor,
    occurrenceClipboardExtension,
    removeOccurrenceMarks,
    type HighlightColor,
  } from '../lib/editor'
  import { collectOccurrences, type OccurrenceAttributes, type PendingKnowledgeObject } from '../lib/occurrences'
  import {
    MAX_HIGHLIGHT_COLORS,
    MIN_HIGHLIGHT_COLORS,
    readHighlightPalette,
    writeHighlightPalette,
  } from '../lib/highlightPalette'
  import { uuidV7 } from '../lib/uuid'
  import { createEntityType, resolveKnowledgeObjects, searchKnowledge } from '../lib/api'

  let {
    documentId,
    initialContent,
    onChange,
    onObjectCreate,
  }: {
    documentId: string
    initialContent: JSONContent
    onChange: (content: JSONContent) => void
    onObjectCreate: (knowledgeObjectId: string, object: PendingKnowledgeObject) => void
  } = $props()

  let element: HTMLDivElement
  let editorShell: HTMLDivElement
  let editorState = $state<{ editor: Editor | null }>({ editor: null })
  let highlightPopover = $state<{ color: HighlightColor; left: number; top: number } | null>(null)
  let highlightPalette = $state<string[]>([...defaultHighlightColors])
  let paletteSelectorOpen = $state(false)
  let clipboardWarning = $state('')

  /** Discriminator of the KnowledgeObject already known to be valid, to avoid useless lookups. */
  const knownObjectTypes = new Map<string, 'concept' | 'entity'>()

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
      ],
      content: initialContent,
      onTransaction: ({ editor, transaction }) => {
        editorState = { editor }
        syncHighlightPopover(editor)
        if (transaction.docChanged) {
          onChange(editor.getJSON())
        }
      },
      onSelectionUpdate: ({ editor }) => syncHighlightPopover(editor),
    })
    editorState = { editor }
  })

  onDestroy(() => editorState.editor?.destroy())

  function run(command: (editor: Editor) => void): void {
    const editor = editorState.editor
    if (editor) command(editor)
  }

  function createConceptFromSelection(): void {
    const editor = editorState.editor
    if (!editor || editor.state.selection.empty) return
    const selectedText = editor.state.doc.textBetween(editor.state.selection.from, editor.state.selection.to, ' ')
    const name = window.prompt('Nome del nuovo Concept', selectedText)?.trim()
    if (!name) return
    const occurrenceId = uuidV7()
    const knowledgeObjectId = uuidV7()
    editor.chain().focus().setMark('knowledgeOccurrence', { occurrenceId, knowledgeObjectId, objectType: 'concept' }).run()
    knownObjectTypes.set(knowledgeObjectId, 'concept')
    onObjectCreate(knowledgeObjectId, { objectType: 'concept', name })
  }

  async function createEntityFromSelection(): Promise<void> {
    const editor = editorState.editor
    if (!editor || editor.state.selection.empty) return
    const selectedText = editor.state.doc.textBetween(editor.state.selection.from, editor.state.selection.to, ' ')
    const name = window.prompt('Nome della nuova Entity', selectedText)?.trim()
    if (!name) return
    const typeName = window.prompt('EntityType', 'Company')?.trim()
    if (!typeName) return
    try {
      const entityType = await createEntityType(typeName)
      const occurrenceId = uuidV7()
      const knowledgeObjectId = uuidV7()
      editor.chain().focus().setMark('knowledgeOccurrence', { occurrenceId, knowledgeObjectId, objectType: 'entity' }).run()
      knownObjectTypes.set(knowledgeObjectId, 'entity')
      onObjectCreate(knowledgeObjectId, { objectType: 'entity', name, entityTypeId: entityType.id })
    } catch (cause) {
      window.alert(cause instanceof Error ? cause.message : 'Impossibile creare EntityType.')
    }
  }

  async function attachExistingFromSelection(): Promise<void> {
    const editor = editorState.editor
    if (!editor || editor.state.selection.empty) return
    const selectedText = editor.state.doc.textBetween(editor.state.selection.from, editor.state.selection.to, ' ')
    const query = window.prompt('Cerca Concept o Entity', selectedText)?.trim()
    if (!query) return
    try {
      const results = await searchKnowledge(query)
      if (results.length === 0) {
        window.alert('Nessun Concept o Entity trovato.')
        return
      }
      const options = results.map((result, index) => `${index + 1}. ${result.object_type === 'concept' ? 'Concept' : 'Entity'} — ${result.name}`).join('\n')
      const choice = Number(window.prompt(`Scegli il risultato:\n${options}`, '1')) - 1
      const result = results[choice]
      if (!result) return
      const occurrenceId = uuidV7()
      editor.chain().focus().setMark('knowledgeOccurrence', { occurrenceId, knowledgeObjectId: result.id, objectType: result.object_type }).run()
      knownObjectTypes.set(result.id, result.object_type)
    } catch (cause) {
      window.alert(cause instanceof Error ? cause.message : 'Ricerca non disponibile.')
    }
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

  function syncHighlightPopover(editor: Editor): void {
    if (!editorShell || !editor.isActive('highlight')) {
      highlightPopover = null
      return
    }

    const coordinates = editor.view.coordsAtPos(editor.state.selection.from)
    const shell = editorShell.getBoundingClientRect()
    const color = normalizeHighlightColor(editor.getAttributes('highlight').color)
    highlightPopover = {
      color,
      left: Math.max(12, Math.min(coordinates.left - shell.left - 84, shell.width - 238)),
      top: Math.max(50, coordinates.top - shell.top - 48),
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
      highlightPopover = null
    } else {
      syncHighlightPopover(editor)
    }
  }
</script>

<div class="editor-shell" bind:this={editorShell}>
  {#if editorState.editor}
    <div class="toolbar" role="toolbar" aria-label="Formattazione documento">
      <button
        type="button"
        class:active={editorState.editor.isActive('paragraph')}
        aria-pressed={editorState.editor.isActive('paragraph')}
        onclick={() => run((editor) => editor.chain().focus().setParagraph().run())}
      >Normale</button>
      {#each [1, 2, 3] as level}
        <button
          type="button"
          class:active={editorState.editor.isActive('heading', { level })}
          aria-pressed={editorState.editor.isActive('heading', { level })}
          aria-label={`Titolo ${level}`}
          onclick={() => run((editor) => editor.chain().focus().toggleHeading({ level: level as 1 | 2 | 3 }).run())}
        >H{level}</button>
      {/each}
      <span class="toolbar-divider" aria-hidden="true"></span>
      <button
        type="button"
        class:active={editorState.editor.isActive('bold')}
        aria-pressed={editorState.editor.isActive('bold')}
        aria-label="Grassetto"
        onclick={() => run((editor) => editor.chain().focus().toggleBold().run())}
      ><strong>G</strong></button>
      <button
        type="button"
        class:active={editorState.editor.isActive('italic')}
        aria-pressed={editorState.editor.isActive('italic')}
        aria-label="Corsivo"
        onclick={() => run((editor) => editor.chain().focus().toggleItalic().run())}
      ><em>C</em></button>
      <button
        type="button"
        class:active={editorState.editor.isActive('underline')}
        aria-pressed={editorState.editor.isActive('underline')}
        aria-label="Sottolineato"
        onclick={() => run((editor) => editor.chain().focus().toggleUnderline().run())}
      ><u>S</u></button>
      <button
        type="button"
        class:active={editorState.editor.isActive('highlight')}
        aria-pressed={editorState.editor.isActive('highlight')}
        aria-label="Evidenzia"
        onclick={() => run((editor) => editor.chain().focus().toggleMark('highlight').run())}
      >Evidenzia</button>
      <button type="button" disabled={editorState.editor.state.selection.empty} onclick={createConceptFromSelection}>Crea Concept</button>
      <button type="button" disabled={editorState.editor.state.selection.empty} onclick={() => void createEntityFromSelection()}>Crea Entity</button>
      <button type="button" disabled={editorState.editor.state.selection.empty} onclick={() => void attachExistingFromSelection()}>Associa esistente</button>
      <button
        type="button"
        class:active={paletteSelectorOpen}
        aria-pressed={paletteSelectorOpen}
        onclick={() => (paletteSelectorOpen = !paletteSelectorOpen)}
      >Palette</button>
      <span class="toolbar-divider" aria-hidden="true"></span>
      <button
        type="button"
        class:active={editorState.editor.isActive('bulletList')}
        aria-pressed={editorState.editor.isActive('bulletList')}
        onclick={() => run((editor) => editor.chain().focus().toggleBulletList().run())}
      >Elenco •</button>
      <button
        type="button"
        class:active={editorState.editor.isActive('orderedList')}
        aria-pressed={editorState.editor.isActive('orderedList')}
        onclick={() => run((editor) => editor.chain().focus().toggleOrderedList().run())}
      >Elenco 1.</button>
      <button
        type="button"
        class:active={editorState.editor.isActive('blockquote')}
        aria-pressed={editorState.editor.isActive('blockquote')}
        onclick={() => run((editor) => editor.chain().focus().toggleBlockquote().run())}
      >Citazione</button>
      <span class="toolbar-divider" aria-hidden="true"></span>
      <button
        type="button"
        disabled={!editorState.editor.can().chain().focus().undo().run()}
        onclick={() => run((editor) => editor.chain().focus().undo().run())}
      >Annulla</button>
      <button
        type="button"
        disabled={!editorState.editor.can().chain().focus().redo().run()}
        onclick={() => run((editor) => editor.chain().focus().redo().run())}
      >Ripeti</button>
    </div>
    {#if paletteSelectorOpen}
      <div class="highlight-palette-selector" aria-label="Configura palette Highlight">
        <label>
          Colori
          <select value={String(highlightPalette.length)} onchange={(event) => changePaletteSize(event.currentTarget.value)}>
            {#each Array.from({ length: MAX_HIGHLIGHT_COLORS - MIN_HIGHLIGHT_COLORS + 1 }, (_, index) => MIN_HIGHLIGHT_COLORS + index) as size}
              <option value={String(size)}>{size}</option>
            {/each}
          </select>
        </label>
        <div class="highlight-palette-colors" aria-label="Modifica colori palette">
          {#each highlightPalette as color, index}
            <label aria-label={`Modifica colore ${index + 1}`} style={`background-color: ${color}`}>
              <input type="color" value={color} oninput={(event) => updatePaletteColor(index, event.currentTarget.value)} />
            </label>
          {/each}
        </div>
        <button type="button" onclick={resetHighlightPalette}>Ripristina predefiniti</button>
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
  {#if editorState.editor && highlightPopover}
    <div
      class="highlight-popover"
      role="dialog"
      aria-label="Opzioni evidenziazione"
      style={`left: ${highlightPopover.left}px; top: ${highlightPopover.top}px`}
    >
      <span class="highlight-popover-label">Colore</span>
      <div class="highlight-colors" aria-label="Scegli colore highlight" role="group">
        {#each highlightPalette as color}
          <button
            type="button"
            class:active={highlightPopover.color === color}
            style={`background-color: ${color}`}
            aria-label={`Usa highlight ${color}`}
            aria-pressed={highlightPopover.color === color}
            onpointerdown={keepSelection}
            onclick={() => changeHighlightColor(color)}
          ></button>
        {/each}
      </div>
      <button type="button" class="highlight-remove" onpointerdown={keepSelection} onclick={removeHighlight}>Rimuovi</button>
    </div>
  {/if}
</div>

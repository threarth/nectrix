<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import { Editor, type JSONContent } from '@tiptap/core'
  import { onDestroy, onMount } from 'svelte'
  import { editorExtensions } from '../lib/editor'

  let {
    initialContent,
    onChange,
  }: {
    initialContent: JSONContent
    onChange: (content: JSONContent) => void
  } = $props()

  let element: HTMLDivElement
  let editorState = $state<{ editor: Editor | null }>({ editor: null })

  onMount(() => {
    const editor = new Editor({
      element,
      extensions: editorExtensions,
      content: initialContent,
      onTransaction: ({ editor, transaction }) => {
        editorState = { editor }
        if (transaction.docChanged) {
          onChange(editor.getJSON())
        }
      },
    })
    editorState = { editor }
  })

  onDestroy(() => editorState.editor?.destroy())

  function run(command: (editor: Editor) => void): void {
    const editor = editorState.editor
    if (editor) command(editor)
  }
</script>

<div class="editor-shell">
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
  {/if}
  <div class="editor-content" bind:this={element}></div>
</div>

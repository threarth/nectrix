# Schema editoriale della FASE 1

Questo documento definisce l'allowlist JSON accettata dall'editor e dall'API nella FASE 1. `Document.document_json` è autorevole; contenuti fuori allowlist vengono rifiutati con errore `422` e non vengono corretti o eliminati silenziosamente.

## Nodi

| Nodo | Contenuto | Attributi |
|---|---|---|
| `doc` | uno o più nodi block | nessuno |
| `paragraph` | zero o più nodi `text` | nessuno |
| `heading` | zero o più nodi `text` | `level`, intero da 1 a 6 |
| `blockquote` | uno o più nodi block | nessuno |
| `bulletList` | uno o più `listItem` | nessuno |
| `orderedList` | uno o più `listItem` | `start`, intero positivo; `type`, soltanto `null` |
| `listItem` | un `paragraph` iniziale seguito da zero o più nodi block | nessuno |
| `text` | stringa non vuota | nessuno |

I nodi block sono `paragraph`, `heading`, `blockquote`, `bulletList` e `orderedList`. La profondità strutturale massima accettata dall'API è 32.

## Mark

Un nodo `text` può avere, senza duplicati:

- `bold`;
- `italic`;
- `underline`.

I mark non hanno attributi. Il loro ordine nell'array non ha significato semantico.

## Funzionalità non abilitate

Code, code block, hard break, horizontal rule, link, strike, highlight e trailing node di TipTap StarterKit sono disabilitati. Drop cursor, gap cursor, list keymap e undo/redo possono operare nell'editor ma non aggiungono nodi o mark persistenti.

## Documento vuoto

Un documento editoriale vuoto usa la rappresentazione canonica:

```json
{
  "type": "doc",
  "content": [
    { "type": "paragraph" }
  ]
}
```

`plain_text` è derivato concatenando paragraph e heading nell'ordine del documento, separati da `\n`; non è accettato come input modificabile dal client.

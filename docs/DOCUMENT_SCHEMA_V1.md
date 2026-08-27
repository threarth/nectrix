# Schema editoriale fino alla FASE 3

Questo documento definisce l'allowlist JSON accettata dall'editor e dall'API fino alla FASE 2. `Document.document_json` è autorevole; contenuti fuori allowlist vengono rifiutati con errore `422` e non vengono corretti o eliminati silenziosamente.

La Phase 1.1 aggiunge tabelle relazionali; la FASE 2 aggiunge Highlight e la FASE 3 abilita il mark comune `knowledgeOccurrence`, documentato qui sotto. Il round trip della FASE 1 resta invariato per tutti i contenuti che non usano questi mark.

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
- `highlight`;
- `knowledgeOccurrence`.

I mark non hanno attributi, eccetto `highlight`, che può avere il solo attributo `color` come colore CSS esadecimale `#RRGGBB`. L'assenza dell'attributo e i valori storici `yellow`, `green`, `blue`, `pink` sono compatibili con i documenti esistenti. Il loro ordine nell'array non ha significato semantico. Un highlight nuovo viene serializzato, per esempio, come `{ "type": "highlight", "attrs": { "color": "#f6dd79" } }` ed è soltanto formattazione visuale: non crea o riferisce KnowledgeObject, KnowledgeOccurrence, SemanticBlock o FieldValue. La palette dell'editor è una preferenza locale, configurabile tra 4 e 10 colori, e non cambia il modello di dominio.

`knowledgeOccurrence` richiede esattamente `occurrenceId`, `knowledgeObjectId` (UUIDv7 canonici) e `objectType` (`concept` o `entity`). È un'asserzione editoriale verificata contro il record persistito; non contiene testo o offset autorevoli.

Il mark Highlight è non inclusivo ai bordi: l'input nel suo intervallo lo estende, mentre l'input esattamente prima o dopo resta non evidenziato.

## Funzionalità non abilitate

Code, code block, hard break, horizontal rule, link, strike e trailing node di TipTap StarterKit sono disabilitati. Drop cursor, gap cursor, list keymap e undo/redo possono operare nell'editor ma non aggiungono nodi o mark persistenti oltre a quelli dell'allowlist.

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

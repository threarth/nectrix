# Modello di dominio

## 1. Linguaggio ubiquitario

```text
Concept  = cosa significa / di che cosa sto parlando
Context  = in quale ambito lo sto studiando o usando
Tag      = come voglio classificare o gestire il materiale
```

Le tre entità sono ortogonali. L'uguaglianza del testo dei loro nomi non implica uguaglianza semantica o referenziale.

## 2. Diagramma logico iniziale

```text
Context 1 ───────< Context
   │
   │ 0..1
   ▼
 Note 1 ─────────< Occurrence >───────── 1 Concept
   │                                           │
   └────────────< NoteTag >──────────── Tag    ├──< ConceptAlias
                                               │
                                               ├──< Relation (source)
                                               └──< Relation (target)
```

Comment e Source sono definiti concettualmente, ma non entrano nello schema iniziale eseguibile.

## 3. Entità

### 3.1 Note

Rappresenta un documento editabile.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | opaque ID | primary key |
| `title` | string | può essere vuoto, non nullo |
| `document_json` | JSON | fonte autorevole del contenuto |
| `plain_text` | text | derivato dal documento, non modificabile direttamente |
| `context_id` | opaque ID nullable | FK verso Context; un solo Context principale |
| `revision` | integer | parte da `0`, cresce di uno a ogni salvataggio accettato |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato a ogni modifica persistita |

`revision` fa parte del modello minimo: il client deve inviare la revisione letta e l'API deve rifiutare un update se nel frattempo la Note è cambiata.

Una Note può contenere zero o più occurrence e avere zero o più Tag.

### 3.2 Concept

Rappresenta un'entità di conoscenza globale, indipendente da note e parole usate per esprimerla.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | opaque ID | primary key |
| `canonical_name` | string | obbligatorio; nome di visualizzazione |
| `description` | text | opzionale |
| `status` | enum | `active`, `orphan`, `archived` |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato a ogni modifica persistita |

Semantica degli stati:

- `active`: disponibile normalmente; può avere zero occurrence se creato deliberatamente come Concept autonomo;
- `orphan`: aveva almeno una occurrence attiva e, dopo riconciliazione, non ne ha più; resta valido e recuperabile;
- `archived`: escluso dai flussi ordinari per scelta esplicita dell'utente, indipendentemente dal numero di occurrence.

`orphan` non significa “invalido” e non causa cancellazione. La creazione autonoma di un Concept con zero occurrence produce `active`, non `orphan`. Aggiungere una nuova occurrence a un Concept `orphan` lo riporta ad `active`; un Concept `archived` richiede una decisione esplicita della UI prima di essere riattivato.

Transizioni ammesse:

```text
creazione autonoma ───────────────→ active (anche con 0 occurrence)
active + perdita dell'ultima ─────→ orphan
orphan + nuova/riattivata occ. ───→ active
active|orphan + comando archive ──→ archived
archived + comando restore ───────→ active
```

La riconciliazione non modifica automaticamente `archived`. `orphan` è quindi il risultato dell'evento “perdita dell'ultima occurrence attiva”, non una vista calcolata dal solo conteggio corrente.

Il nome canonico non è condiviso con Context o Tag. Inizialmente non viene imposta unicità globale case-insensitive tra Concept: omonimi reali e duplicati da risolvere devono essere rappresentabili. La UI deve però segnalare corrispondenze esatte e simili prima della creazione.

### 3.3 ConceptAlias

Denominazione alternativa di un Concept; non è una occurrence.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | opaque ID | primary key |
| `concept_id` | opaque ID | FK obbligatoria verso Concept |
| `alias` | string | obbligatorio, non vuoto |

Lo stesso Concept non può avere due alias uguali dopo la normalizzazione definita per la ricerca. Lo stesso alias può appartenere a Concept diversi perché il linguaggio può essere ambiguo. Aggiungere o rimuovere un Alias non modifica il testo e non crea/distrugge occurrence.

### 3.4 Occurrence

Identità persistente di un intervallo vivo di testo dichiarato istanza di un Concept.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | opaque ID globale | primary key; presente nel mark |
| `concept_id` | opaque ID | FK obbligatoria verso Concept; immutabile per l'identità corrente |
| `note_id` | opaque ID | FK obbligatoria verso Note; immutabile salvo il cut interno verificato, che resta nella stessa nota |
| `status` | enum | `active`, `detached`, `deleted` |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato ai cambi di stato |

Non contiene testo, posizione assoluta o copia autorevole del range. Il testo corrente e la posizione si estraggono dal documento della Note cercando il mark con il suo ID.

Cambiare Concept a un range non muta `concept_id` in place: termina/detacha l'occurrence precedente e ne crea una nuova. L'identità esprime anche l'associazione semantica, non soltanto una decorazione visuale.

Transizioni ammesse:

```text
creazione con mark nella stessa transazione ──→ active
active + mark assente al salvataggio ─────────→ detached
detached + stesso mark ripristinato ──────────→ active
active|detached + eliminazione esplicita ─────→ deleted
```

`deleted` non deriva mai dalla normale cancellazione editoriale ed è terminale nel modello iniziale. Un eventuale ripristino futuro dovrà creare una nuova Occurrence oppure introdurre una transizione esplicita con relativa migrazione delle invarianti.

### 3.5 Context

Ambito gerarchico nel quale una Note viene studiata o utilizzata.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | opaque ID | primary key |
| `name` | string | obbligatorio, non vuoto |
| `parent_id` | opaque ID nullable | self-FK; `NULL` per radice |

Sono vietati cicli e auto-parenting. È raccomandata l'unicità del nome normalizzato tra fratelli (`parent_id`, nome normalizzato), mentre lo stesso nome può comparire in rami diversi. Il percorso è calcolato dalla gerarchia.

Un Context non definisce il significato dei Concept contenuti nelle note. Spostare una Note o un ramo Context non duplica né rinomina Concept.

### 3.6 Tag

Metadata libero usato per classificazione e gestione.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | opaque ID | primary key |
| `name` | string | obbligatorio; memorizzato senza necessità del prefisso `#` |

Un Tag non ha canonical name, Alias, occurrence o relazioni semantiche. Il nome normalizzato è unico tra i Tag. Il carattere `#` è una convenzione di presentazione e ricerca.

### 3.7 NoteTag

Associazione molti-a-molti tra Note e Tag.

| Campo | Tipo logico | Regole |
|---|---|---|
| `note_id` | opaque ID | FK verso Note |
| `tag_id` | opaque ID | FK verso Tag |

La coppia (`note_id`, `tag_id`) è la chiave primaria logica. La duplicazione dell'assegnazione non è ammessa.

### 3.8 Relation

Arco semantico diretto tra due Concept.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | opaque ID | primary key |
| `source_concept_id` | opaque ID | FK verso Concept |
| `target_concept_id` | opaque ID | FK verso Concept |
| `relation_type` | string | obbligatorio; tipi noti o custom |
| `description` | text | opzionale |

Ogni Concept può partecipare a molte Relation come sorgente e destinazione. Più relazioni tra la stessa coppia sono consentite se esprimono predicati o descrizioni differenti. Direzione, simmetria e inverso sono proprietà del tipo di relazione e non vanno inferiti dal solo ordine alfabetico dei Concept.

La provenance tramite occurrence verrà aggiunta in una fase successiva con un'associazione dedicata, non con una lista serializzata nel record.

### 3.9 Comment (solo modello concettuale)

Un commento è una discussione ancorata a contenuto della Note, non a semplici offset assoluti. Il modello previsto separa:

- `CommentThread`: identità, `note_id`, anchor strutturale versionata, stato `open`/`resolved`, timestamp;
- `CommentMessage`: identità, `thread_id`, corpo, timestamp ed eventuale autore futuro.

L'anchor dovrà usare strutture ProseMirror robuste e una strategia di remapping. Il formato preciso verrà scelto nella FASE 18 dopo test dedicati. In questa fase non vengono create tabelle o API.

### 3.10 Source (solo modello concettuale)

Una Source rappresenta una provenienza riutilizzabile, con tipi iniziali:

`url`, `book`, `pdf`, `image`, `figure`, `document`, `other`.

Metadata previsti: titolo, autore, URL, anno, ISBN, file path e note. Un `SourceLocator` separato descriverà page, chapter, section, paragraph, quote e coordinate/anchor. Concept, Occurrence, Relation e Note useranno tabelle di associazione distinte per conservare foreign key reali; non si userà un'unica foreign key polimorfica non verificabile da SQLite.

Il modello è documentato ora ma sarà implementato nella FASE 16.

## 4. Relazioni e cardinalità

- Note → Context: molte Note possono avere lo stesso Context; ogni Note ne ha al massimo uno.
- Context → Context: ogni Context ha al massimo un parent e può avere molti figli.
- Concept → ConceptAlias: uno-a-molti.
- Concept → Occurrence: uno-a-molti; anche zero.
- Note → Occurrence: uno-a-molti; anche zero.
- Note ↔ Tag: molti-a-molti tramite NoteTag.
- Concept ↔ Concept: molti-a-molti diretto e tipizzato tramite Relation.

## 5. Query fondamentali

### Per Concept

Si seleziona il Concept per ID, canonical name o Alias e si seguono le Occurrence attive verso le Note. Il testo non determina l'identità.

### Per Context

Si selezionano Note assegnate al Context; una query può includere esplicitamente i discendenti. Da quelle Note si ricavano testo, Concept e occurrence.

### Per Tag

Si selezionano Note via NoteTag. Non vengono creati collegamenti semantici impliciti.

### Combinata Concept × Context × Tag

Una Note/Occurrence soddisfa il filtro se:

1. contiene un'Occurrence attiva del Concept richiesto;
2. la Note appartiene al Context richiesto secondo la modalità exact/subtree scelta;
3. la Note possiede il Tag richiesto.

Il risultato deve indicare separatamente quale dimensione ha prodotto ogni match.

## 6. Verifica degli scenari richiesti

| Scenario | Supporto del modello |
|---|---|
| Concept senza occurrence | cardinalità 0..n e stato `active`/`orphan` |
| più occurrence dello stesso Concept | record Occurrence distinti con stesso `concept_id` |
| più Alias | relazione Concept 1:n ConceptAlias |
| testo occurrence diverso dagli Alias | testo solo nel documento; nessun vincolo di uguaglianza |
| relazioni molti-a-molti | Relation con due FK verso Concept |
| Context gerarchici | `parent_id` con controllo anti-ciclo |
| ricerca distinta | tabelle e percorsi query distinti |
| Concept × Context × Tag | join Occurrence → Note → Context/NoteTag |
| stessa stringa in ruoli diversi | namespace e ID indipendenti |

## 7. Decisioni risolutive adottate

Le criticità individuate nella FASE 0 sono risolte dalle decisioni seguenti e non restano opzioni aperte per l'implementazione iniziale.

### ID assegnati solo dal server

Un ID auto-incrementale rende paste, undo e creazione editoriale dipendenti dalla rete. Tutte le entità usano UUIDv7 canonici, client-generabili e globalmente univoci, conservati come `TEXT`.

### Assenza di revisione sulla Note

Con soli `updated_at` e documento completo, due salvataggi potrebbero riconciliare occurrence in ordine errato. `revision` è quindi un campo obbligatorio di Note e abilita optimistic concurrency control.

### Significato ambiguo di `orphan`

Un Concept è autorizzato a nascere con zero occurrence, quindi “zero occurrence = orphan” sarebbe contraddittorio. `orphan` è uno stato di lifecycle applicato dall'evento di perdita dell'ultima occurrence; la macchina a stati è definita nella sezione Concept.

### Cut/paste non sempre distinguibile dal copy/paste

La clipboard standard non garantisce una prova affidabile dell'intenzione dell'utente. L'ID viene mantenuto soltanto per cut interno verificato con token effimero one-shot; viene rigenerato in ogni caso ambiguo.

### Un mark può essere frammentato da ProseMirror

La formattazione interna può produrre più text node con gli stessi attributi. L'estrattore raggruppa frammenti contigui nello stesso textblock; il modello iniziale non permette a una occurrence di attraversare più textblock, vieta il riuso dello stesso ID in intervalli disgiunti e vieta overlap.

### `conceptId` duplicato tra documento e DB

La ridondanza può divergere. `Occurrence.concept_id` è canonico; il mark è un'asserzione validata. I conflitti bloccano il salvataggio con errore di integrità e non vengono sanati silenziosamente.

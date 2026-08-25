# Architettura

## 1. Obiettivi e confini

Nectrix è un'applicazione locale-first a singolo utente. L'editor deve rimanere simile a un normale word processor; la struttura semantica viene aggiunta esplicitamente e non deve interrompere la scrittura.

L'architettura iniziale è un monolite modulare:

```text
Browser
  Svelte + TipTap/ProseMirror
          │ JSON REST
          ▼
API PHP minimale
  dominio + validazione + repository
          │
          ▼
SQLite + FTS5
```

Non sono previsti, nelle prime fasi, autenticazione complessa, microservizi, graph database, vector database, Elasticsearch o funzionalità AI.

## 2. Fonti di verità

La proprietà dei dati è deliberatamente separata.

| Informazione | Fonte autorevole | Rappresentazione derivata |
|---|---|---|
| contenuto e formattazione della nota | `Note.document_json` TipTap | `Note.plain_text`, preview, indice FTS |
| identità e appartenenza di una occurrence | record `Occurrence` | attributi del mark nel documento |
| testo corrente di una occurrence | intervallo marcato nel documento | estrazione temporanea per UI/ricerca |
| Concept, Alias, Context, Tag, Relation | database relazionale | indici e viste di ricerca |

Il testo di una occurrence non viene memorizzato come copia autorevole nel database. Quando serve, viene estratto dal `document_json` corrente. Un eventuale indice testuale delle occurrence è una cache ricostruibile e deve includere la revisione del documento da cui deriva.

Il mark contiene sia `occurrenceId` sia `conceptId`. Il secondo è una ridondanza utile a rendering e ispezione offline, non può cambiare l'associazione canonica registrata nel database. Un conflitto tra mark e record DB è un errore di consistenza da segnalare, non da correggere silenziosamente.

## 3. Identificatori

Tutte le entità di dominio usano **UUIDv7** nel formato testuale canonico lowercase `8-4-4-4-12`, memorizzato in SQLite come `TEXT`. Gli ID sono opachi per il dominio, globalmente univoci e possono essere generati dal client con una sorgente casuale crittograficamente sicura. Non sono ammessi ID auto-incrementali o formati alternativi nello schema iniziale.

La generazione client-side è necessaria in particolare per le occurrence perché:

- il comando editoriale deve applicare il mark senza attendere un round trip;
- undo/redo deve poter ripristinare lo stesso ID;
- il paste deve poter generare nuovi ID prima di inserire il contenuto;
- gli ID non devono collidere tra note o dispositivi.

L'API valida forma e unicità dell'UUID, ma non ne interpreta timestamp o ordinamento come dato di dominio.

## 4. Documento editoriale

`document_json` è un documento TipTap JSON valido. La FASE 1 abilita soltanto i nodi e mark esplicitamente elencati nella roadmap. L'highlight della FASE 2 è un mark puramente visuale e non condivide attributi o lifecycle con `conceptOccurrence`.

Il mark semantico pianificato è:

```json
{
  "type": "conceptOccurrence",
  "attrs": {
    "conceptId": "opaque-id",
    "occurrenceId": "opaque-id"
  }
}
```

Un occurrence ID identifica **un solo intervallo logico contiguo, non vuoto, in un solo textblock di una sola nota**. L'intervallo può attraversare più text node ProseMirror a causa di formattazioni inline interne; questi frammenti costituiscono una sola occurrence se sono adiacenti e hanno gli stessi attributi. Lo stesso ID in intervalli disgiunti è invalido. Una selezione che attraversa paragraph, heading o altri textblock non può essere convertita in una singola occurrence nella versione iniziale.

Le occurrence semantiche non possono sovrapporsi o annidarsi. Questa restrizione iniziale elimina ambiguità di selezione, cancellazione, rendering e riconciliazione. Potrà cambiare solo con una decisione architetturale e nuovi test di invariante.

Comportamento iniziale dei bordi:

- digitare all'interno del range estende l'occurrence e conserva l'ID;
- digitare esattamente prima o dopo il range non ne fa parte;
- una modifica interna può dividere il testo in più text node, ma non l'identità logica;
- la cancellazione parziale conserva mark e ID sul testo residuo;
- la cancellazione dell'intero range rimuove il mark dal documento.

Queste regole richiedono configurazione esplicita dell'inclusività del mark e test a livello ProseMirror; non vanno affidate ai default della libreria.

## 5. Lifecycle delle occurrence

### 5.1 Stati persistenti

`Occurrence.status` assume i valori:

- `active`: l'ID è presente una volta nel documento della nota assegnata;
- `detached`: l'ID non è più presente nell'ultima revisione salvata, ma il record è conservato per undo, diagnostica e ripristino;
- `deleted`: eliminazione logica esplicita e non ordinaria; non è prodotta dalla semplice cancellazione del testo.

Una cancellazione nell'editor non modifica immediatamente il database. Al salvataggio, la riconciliazione porta l'occurrence assente ad `detached`. Se undo la reintroduce e una revisione successiva viene salvata, lo stesso record torna `active`.

La rimozione fisica è una futura operazione di garbage collection con retention e non appartiene al normale salvataggio.

### 5.2 Creazione atomica

Il comando “crea Concept” prepara un nuovo Concept, una nuova Occurrence e il mark corrispondente come unica unità logica. Il salvataggio API deve essere transazionale: non deve lasciare un record `active` privo di mark né un mark accettato senza record corrispondente.

Per associare un Concept esistente viene creato soltanto un nuovo record Occurrence e un nuovo mark. Il testo selezionato non viene trasformato automaticamente in Alias.

### 5.3 Undo e redo

La storia ProseMirror è la fonte della sequenza editoriale. Undo/redo ripristina o rimuove il mark con gli stessi attributi originali. La sincronizzazione persistente è idempotente:

- ID presente e record `detached` → riattivazione;
- ID presente e record `active` coerente → nessuna duplicazione;
- ID assente e record `active` → detach;
- ripetere lo stesso salvataggio → nessun cambiamento ulteriore.

La revisione monotona della nota (`revision`) è parte del modello minimo e impedisce che una risposta o un salvataggio obsoleto annulli una riconciliazione più recente.

### 5.4 Copy/paste

Il copy serializza il contenuto ma il paste deve riscrivere **ogni** `occurrenceId` in ingresso con un nuovo ID, mantenendo `conceptId`. La riscrittura avviene una volta per ciascun vecchio ID: frammenti contigui appartenenti alla stessa occurrence copiata ricevono lo stesso nuovo ID.

Il paste non deve fidarsi degli ID presenti in HTML, JSON o clipboard esterni. Se il Concept non esiste, il client rimuove soltanto il mark `conceptOccurrence`, conserva testo e formattazione ordinaria e mostra un avviso non bloccante. Non viene mai creato implicitamente un Concept.

### 5.5 Cut/paste

La preferenza “cut/paste nello stesso documento mantiene l'ID” è realizzabile solo quando il client può provare che si tratta dello stesso taglio. La decisione è:

1. al cut interno, generare un nonce casuale one-shot e registrare in memoria documento, revisione editoriale, occurrence coinvolte e impronta del payload;
2. inserire il nonce anche in un formato clipboard custom Nectrix; il solo HTML non prova un cut;
3. al paste nello stesso documento, mantenere gli ID solo se nonce e payload corrispondono, il token non è già stato consumato, gli originali non sono più presenti e ogni ID produce un unico intervallo;
4. consumare il token al primo paste riuscito e invalidarlo su nuovo copy/cut, reload o distruzione dell'editor;
5. tra note, senza formato custom, con token scaduto oppure in qualsiasi caso ambiguo, trattare l'operazione come copy/paste e generare nuovi ID;
6. se un ID risulta ancora presente, generare sempre un nuovo ID per evitare duplicati.

Il token non è persistenza di dominio e scade con la sessione editoriale. Questa policy privilegia la coerenza rispetto alla conservazione dell'identità nei casi non dimostrabili.

## 6. Salvataggio e riconciliazione

Il salvataggio di una nota deve essere un'unica transazione SQLite e ricevere almeno:

- ID e revisione base della nota;
- titolo e documento JSON completi;
- operazioni/record nuovi necessari per Concept e Occurrence creati nel client.

Pipeline prevista:

1. validare lo schema TipTap supportato;
2. estrarre gli intervalli `conceptOccurrence` normalizzando i text node contigui;
3. rifiutare ID duplicati in intervalli disgiunti, overlap e attributi incompleti;
4. verificare che ciascun ID esistente appartenga alla stessa nota e allo stesso Concept;
5. creare in modo idempotente i nuovi record dichiarati;
6. marcare `active` gli ID validi presenti;
7. marcare `detached` gli ID prima attivi e ora assenti;
8. aggiornare `document_json`, derivare `plain_text`, incrementare `revision` e aggiornare FTS;
9. aggiornare lo stato `orphan` dei Concept interessati secondo le regole di dominio;
10. eseguire commit o rollback dell'intera operazione.

Non si inferiscono nuovi record da un mark sconosciuto senza una manifestazione esplicita della creazione. Questo impedisce che documenti manipolati creino entità silenziosamente.

## 7. Consistenza e conflitti

Sono errori bloccanti del salvataggio:

- due range disgiunti con lo stesso `occurrenceId`;
- lo stesso ID presente in note diverse;
- `conceptId` del mark diverso da quello dell'Occurrence registrata;
- occurrence sovrapposte;
- riferimento a entità inesistente senza una creazione valida nella stessa transazione;
- revisione base obsoleta.

L'API restituisce `409 Conflict` per revisione obsoleta e `422 Unprocessable Content` per violazioni strutturali o semantiche del documento. La risposta contiene un codice macchina stabile e i soli riferimenti necessari a localizzare il problema. Nessun errore modifica parzialmente i dati. La UI conserva la bozza locale e offre recupero; non risolve il conflitto cancellando dati o scegliendo silenziosamente una delle due rappresentazioni.

## 8. Ricerca e dimensioni indipendenti

Concept, Context e Tag hanno tabelle, endpoint e filtri separati. Non esiste una tabella generica `labels` né un ID condiviso.

La combinazione `Concept × Context × Tag` si ottiene con join espliciti:

```text
Concept
  ← Occurrence → Note → Context (principale, inclusi discendenti se richiesto)
                      ↘ NoteTag → Tag
```

Il filtro Concept seleziona note/occurrence tramite identità semantica, non tramite uguaglianza testuale. Il filtro Context seleziona l'ambito assegnato alla nota. Il filtro Tag seleziona metadata organizzativi. Una stringa uguale in tutte e tre le tabelle produce tre risultati distinti.

FTS5 indicizza il plain text derivato delle note. Le ricerche per canonical name, alias, Context, Tag e Relation restano query strutturate; la UI potrà aggregarne i risultati senza fonderne il significato.

## 9. Context gerarchici

La gerarchia iniziale usa una self-reference `Context.parent_id`. Sono vietati cicli e auto-parenting. Il percorso è derivato dai nodi e non salvato come unica rappresentazione autorevole. Spostare un Context cambia il percorso dei discendenti senza cambiare i loro ID.

Ogni nota ha zero o un Context principale. `NULL` significa “nessun contesto assegnato”, non un Context speciale. La semantica dei filtri sui discendenti deve essere esplicita nell'API.

## 10. Sicurezza dei dati e osservabilità

- foreign key SQLite abilitate per ogni connessione;
- transazioni per ogni modifica multi-entità;
- timestamp UTC in formato definito globalmente;
- nessuna cancellazione a cascata da Concept verso note o testo;
- validazione server-side anche quando il client ha già validato;
- backup e migrazioni prima di introdurre operazioni distruttive.

I log diagnostici non devono diventare una seconda fonte di verità.

## 11. Decisioni differite

Restano intenzionalmente fuori dalla FASE 0:

- schema eseguibile e forma precisa degli endpoint;
- libreria per migration e test PHP;
- ancoraggio robusto dei Comment;
- retention degli oggetti `detached`;
- indicizzazione derivata del testo delle occurrence;
- multi-context per nota;
- gestione file e SourceLocator;
- renderer della Knowledge Map;
- qualsiasi integrazione AI.

Queste decisioni devono essere prese nella fase che le richiede, senza invalidare le invarianti qui definite.

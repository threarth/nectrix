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

Struttura eseguibile introdotta nella FASE 1:

```text
src/                 frontend Svelte, client API ed editor TipTap
api/public/          front controller JSON REST
api/src/             validazione, servizio e repository Document
api/migrations/      migrazioni SQLite incrementali
api/tests/           test PHP senza framework applicativo
data/                database locale escluso da Git
docs/                contratti eseguibili documentati
```

L'API espone nella FASE 1 health, list, create, get e update di Document. `PUT` richiede `baseRevision`; non esiste ancora un endpoint di cancellazione. Il database predefinito è `data/nectrix.sqlite`, sostituibile tramite `NECTRIX_DB_PATH`. Il runner PHP è intenzionalmente dependency-free; Vitest copre lo schema editoriale reale nel DOM simulato.

## 2. Fonti di verità

La proprietà dei dati è deliberatamente separata.

| Informazione | Fonte autorevole | Rappresentazione derivata |
|---|---|---|
| contenuto e formattazione principale del Document | `Document.document_json` TipTap | parte principale di `Document.plain_text`, preview, indice FTS |
| gerarchia editoriale del libro | `Document.parent_document_id`, ruolo e ordine | indice aggregato e sequenza di export |
| identità e appartenenza di una occurrence | record `Occurrence` | attributi del mark nel documento |
| testo corrente di una occurrence | intervallo marcato nel documento | estrazione temporanea per UI/ricerca |
| Concept, Alias, Context, Tag, Relation | database relazionale | indici e viste di ricerca |
| catalogo e metadata delle fonti | record `Source` e `SourceLocator` | label e risultati di ricerca |
| ancoraggio di una fonte a un range | record `SourceAnchor` + mark nel documento | testo corrente estratto dal documento |
| file immagine e metadata tecnici | record `Asset` + file locale | nodo immagine nel documento |
| destinazione e lifecycle di un link tra Document | record `DocumentLink` | mark e label nel documento sorgente, backlink |
| identità di una destinazione interna | record `DocumentAnchor` + attributo nel nodo | indice navigabile e posizione corrente |
| formule | sorgente nel nodo inline/block del documento | rendering visuale ed export |
| corpo di footnote/endnote | `DocumentNote.body_json` | `DocumentNote.plain_text`, contributo a `Document.plain_text`, numero, collocazione e rendering |
| dati bibliografici | `Source`, contributor, identifier e locator | citazioni formattate e bibliografia |
| item e opzioni di una citazione | `BibliographicCitation` e relativi item | label inline secondo lo stile attivo |

Il testo di una occurrence non viene memorizzato come copia autorevole nel database. Quando serve, viene estratto dal `document_json` corrente. Un eventuale indice testuale delle occurrence è una cache ricostruibile e deve includere la revisione del documento da cui deriva.

Il mark contiene sia `occurrenceId` sia `conceptId`. Il secondo è una ridondanza utile a rendering e ispezione offline, non può cambiare l'associazione canonica registrata nel database. Un conflitto tra mark e record DB è un errore di consistenza da segnalare, non da correggere silenziosamente.

## 3. Identificatori

Tutte le entità di dominio usano **UUIDv7** nel formato testuale canonico lowercase `8-4-4-4-12`, memorizzato in SQLite come `TEXT`. Gli ID sono opachi per il dominio, globalmente univoci e possono essere generati dal client con una sorgente casuale crittograficamente sicura. Non sono ammessi ID auto-incrementali o formati alternativi nello schema iniziale.

La generazione client-side è necessaria in particolare per le occurrence perché:

- il comando editoriale deve applicare il mark senza attendere un round trip;
- undo/redo deve poter ripristinare lo stesso ID;
- il paste deve poter generare nuovi ID prima di inserire il contenuto;
- gli ID non devono collidere tra Document o dispositivi.

L'API valida forma e unicità dell'UUID, ma non ne interpreta timestamp o ordinamento come dato di dominio.

## 4. Documento editoriale

`document_json` è un documento TipTap JSON valido. La FASE 1 abilita soltanto i nodi e mark definiti in `docs/DOCUMENT_SCHEMA_V1.md`; frontend e API applicano la stessa allowlist concettuale e il server rifiuta campi non riconosciuti. L'highlight della FASE 2 è un mark puramente visuale e non condivide attributi o lifecycle con `conceptOccurrence`.

Il modello di dominio è prospettico, ma lo schema eseguibile è incrementale: la FASE 1 crea solo i campi e i vincoli necessari ai flussi minimi di creazione, lettura e aggiornamento del Document. Context, gerarchia editoriale, note, link e citazioni entrano esclusivamente con le migrazioni delle rispettive fasi.

Il corpo “Normale” è rappresentato da `paragraph`. Heading, liste e blockquote descrivono struttura e significato editoriale; bold, italic e underline sono formattazioni inline. Font, dimensioni, interlinea, margini e colori ordinari appartengono al tema di presentazione e non vengono copiati su ogni nodo. Eventuali stili nominati aggiuntivi richiederanno una allowlist semantica e una mappatura esplicita per editor ed export, non CSS arbitrario persistito nel documento.

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

Un occurrence ID identifica **un solo intervallo logico contiguo, non vuoto, in un solo textblock di un solo Document**. L'intervallo può attraversare più text node ProseMirror a causa di formattazioni inline interne; questi frammenti costituiscono una sola occurrence se sono adiacenti e hanno gli stessi attributi. Lo stesso ID in intervalli disgiunti è invalido. Una selezione che attraversa paragraph, heading o altri textblock non può essere convertita in una singola occurrence nella versione iniziale.

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

- `active`: l'ID è presente una volta nel Document assegnato;
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

La revisione monotona del Document (`revision`) è parte del modello minimo e impedisce che una risposta o un salvataggio obsoleto annulli una riconciliazione più recente.

### 5.4 Copy/paste

Il copy serializza il contenuto ma il paste deve riscrivere **ogni** `occurrenceId` in ingresso con un nuovo ID, mantenendo `conceptId`. La riscrittura avviene una volta per ciascun vecchio ID: frammenti contigui appartenenti alla stessa occurrence copiata ricevono lo stesso nuovo ID.

Il paste non deve fidarsi degli ID presenti in HTML, JSON o clipboard esterni. Se il Concept non esiste, il client rimuove soltanto il mark `conceptOccurrence`, conserva testo e formattazione ordinaria e mostra un avviso non bloccante. Non viene mai creato implicitamente un Concept.

### 5.5 Cut/paste

La preferenza “cut/paste nello stesso documento mantiene l'ID” è realizzabile solo quando il client può provare che si tratta dello stesso taglio. La decisione è:

1. al cut interno, generare un nonce casuale one-shot e registrare in memoria documento, revisione editoriale, occurrence coinvolte e impronta del payload;
2. inserire il nonce anche in un formato clipboard custom Nectrix; il solo HTML non prova un cut;
3. al paste nello stesso documento, mantenere gli ID solo se nonce e payload corrispondono, il token non è già stato consumato, gli originali non sono più presenti e ogni ID produce un unico intervallo;
4. consumare il token al primo paste riuscito e invalidarlo su nuovo copy/cut, reload o distruzione dell'editor;
5. tra Document, senza formato custom, con token scaduto oppure in qualsiasi caso ambiguo, trattare l'operazione come copy/paste e generare nuovi ID;
6. se un ID risulta ancora presente, generare sempre un nuovo ID per evitare duplicati.

Il token non è persistenza di dominio e scade con la sessione editoriale. Questa policy privilegia la coerenza rispetto alla conservazione dell'identità nei casi non dimostrabili.

## 6. Salvataggio e riconciliazione

Il salvataggio di un Document deve essere un'unica transazione SQLite e ricevere almeno:

- ID e revisione base del Document;
- titolo e documento JSON completi;
- operazioni/record nuovi necessari per Concept e Occurrence creati nel client.

Pipeline prevista:

1. validare lo schema TipTap supportato;
2. estrarre gli intervalli `conceptOccurrence` normalizzando i text node contigui;
3. rifiutare ID duplicati in intervalli disgiunti, overlap e attributi incompleti;
4. verificare che ciascun ID esistente appartenga allo stesso Document e allo stesso Concept;
5. creare in modo idempotente i nuovi record dichiarati;
6. marcare `active` gli ID validi presenti;
7. marcare `detached` gli ID prima attivi e ora assenti;
8. aggiornare `document_json`, derivare `plain_text`, incrementare `revision` e aggiornare FTS;
9. aggiornare lo stato `orphan` dei Concept interessati secondo le regole di dominio;
10. eseguire commit o rollback dell'intera operazione.

Non si inferiscono nuovi record da un mark sconosciuto senza una manifestazione esplicita della creazione. Questo impedisce che documenti manipolati creino entità silenziosamente.

Uno split/move strutturale tra Document è un endpoint distinto dal normale salvataggio. Riceve revisioni base di sorgente, destinazione e parent, opera solo su blocchi completi e in un'unica transazione trasferisce contenuto, ownership dei record incorporati (inclusi i futuri CommentThread), ordine dei figli e destinazioni dei DocumentLink che puntano a DocumentAnchor spostati. Un conflitto o un riferimento non trasferibile causa rollback completo e conserva le bozze.

## 7. Consistenza e conflitti

Sono errori bloccanti del salvataggio:

- due range disgiunti con lo stesso `occurrenceId`;
- lo stesso ID presente in Document diversi;
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
  ← Occurrence → Document → Context (principale, inclusi discendenti se richiesto)
                      ↘ DocumentTag → Tag
```

Il filtro Concept seleziona Document/occurrence tramite identità semantica, non tramite uguaglianza testuale. Il filtro Context seleziona l'ambito assegnato al Document. Il filtro Tag seleziona metadata organizzativi. Una stringa uguale in tutte e tre le tabelle produce tre risultati distinti.

FTS5 indicizza una volta il `Document.plain_text` aggregato, che dalla FASE 21 comprende i body delle DocumentNote attive in ordine di riferimento. `DocumentNote.plain_text` resta un derivato utile alla composizione e alla diagnostica, ma non viene indicizzato anche separatamente nello stesso indice evitando risultati e conteggi duplicati. Un eventuale indice per-nota futuro dovrà essere distinto esplicitamente. Le ricerche per canonical name, alias, Context, Tag e Relation restano query strutturate; la UI potrà aggregarne i risultati senza fonderne il significato.

## 9. Context gerarchici

La gerarchia usa una self-reference `Context.parent_id` e supporta un numero arbitrario di livelli:

```text
Finanza
└── Fixed Income
    └── Bond Pricing
        └── Yield Measures
```

Ogni nodo può essere radice, Context o sub-context: non sono entità differenti. Sono vietati cicli e auto-parenting. Il percorso è derivato ricorsivamente dai nodi e non salvato come unica rappresentazione autorevole. SQLite usa query ricorsive (`WITH RECURSIVE`) per antenati e discendenti. Spostare un Context cambia il percorso dei discendenti senza cambiare i loro ID.

Ogni Document ha zero o un Context principale, che può trovarsi a qualsiasi profondità. `NULL` significa “nessun contesto assegnato”, non un Context speciale. Ogni filtro dichiara la modalità `exact` oppure `subtree`; `subtree` include il nodo scelto e tutti i discendenti.

La cancellazione ordinaria è consentita solo per un Context foglia senza Document assegnati. Negli altri casi l'utente deve prima spostare o riassegnare figli e Document. Non si applicano cancellazioni a cascata.

## 10. Fonti e citazioni

`Source` è un elemento riutilizzabile del reference manager. Un libro viene registrato una sola volta con metadata strutturati, contributor ordinati e identificatori normalizzati; il dialog “Aggiungi fonte” cerca innanzitutto nel catalogo per tipo, titolo, contributor e identificatore. Dal dialog l'utente può selezionare una Source esistente oppure crearla e usarla immediatamente. Deduplicazione e merge sono sempre assistiti e confermati.

La provenienza è separata in tre livelli:

```text
Source          opera o risorsa (es. un libro)
SourceLocator   punto nella fonte (es. capitolo 4, pagina 122)
Association     dove Nectrix usa quella fonte
```

Un collegamento all'intero Document usa `DocumentSource`. Un collegamento a una parte del testo usa un `SourceAnchor` persistente rappresentato nel documento da:

```json
{
  "type": "sourceAnchor",
  "attrs": {
    "sourceAnchorId": "uuid-v7"
  }
}
```

Il record `SourceAnchor` identifica il range e il Document; una tabella `SourceCitation` collega l'anchor a una o più coppie Source/SourceLocator. In questo modo lo stesso passaggio può avere più fonti senza accumulare liste di ID nel mark.

Come per le occurrence, il testo del range non è duplicato autorevolmente nel database e gli offset assoluti non ne costituiscono l'identità. Nella prima implementazione l'anchor è non vuoto e limitato a un textblock. Editing interno, cancellazione, undo/redo, copy/paste, salvataggio e reload devono avere test dedicati. La cancellazione completa porta l'anchor a `detached`; non elimina Source o SourceLocator.

Un copy/paste crea un nuovo `sourceAnchorId` e duplica le associazioni alle stesse fonti. Un cut/paste interno verificato può mantenere l'ID con la stessa policy prudente delle occurrence.

La provenance di range (`SourceCitation`) e la citazione bibliografica visibile (`BibliographicCitation`) hanno scopi diversi. La prima documenta l'evidence di un passaggio; la seconda produce un richiamo formattato e alimenta la bibliografia. Entrambe riusano Source e SourceLocator senza duplicarli.

## 11. Immagini inline

Le immagini nel testo sono nodi TipTap/ProseMirror che contengono soltanto riferimenti e proprietà editoriali:

```json
{
  "type": "image",
  "attrs": {
    "assetId": "uuid-v7",
    "alt": "descrizione accessibile",
    "title": null,
    "width": null
  }
}
```

Il file binario non viene incorporato come base64 nel `document_json`. Viene conservato nello storage locale con nome non controllato dall'utente; il database mantiene un record `Asset` con MIME type verificato, dimensione, hash e dimensioni in pixel. Il documento resta autorevole per posizione, ordine, alt text e dimensione di visualizzazione.

La prima versione accetta PNG, JPEG e WebP dopo verifica del contenuto reale, applica limiti configurabili di byte e pixel e non accetta SVG attivi. Eliminare un nodo immagine non elimina immediatamente l'Asset: l'asset non più referenziato diventa candidato a garbage collection dopo un periodo di retention. Undo deve poterlo ripristinare.

Copiare un'immagine già presente in Nectrix può riusare lo stesso `assetId`, perché un Asset è una risorsa immutabile condivisibile e non un'occurrence. Incollare o caricare un nuovo file crea invece un nuovo Asset. Un'immagine può successivamente essere collegata a una Source/figura senza fondere `Asset` (file) e `Source` (provenienza).

## 12. Ipertesto, indice, formule ed export

### 12.1 Anchor e indice navigabile

Gli heading diventano destinazioni stabili tramite un attributo `documentAnchorId` validato contro `DocumentAnchor`:

```json
{
  "type": "heading",
  "attrs": {
    "level": 2,
    "role": "section",
    "documentAnchorId": "uuid-v7"
  },
  "content": []
}
```

L'indice del Document è una vista ordinata derivata dagli heading presenti nel `document_json`: ruolo (`part`, `chapter`, `section`), livello, label corrente e posizione non vengono duplicati in una tabella. Il record `DocumentAnchor` conserva soltanto identità, appartenenza e lifecycle. Rinomina o spostamento dell'heading mantengono l'anchor; delete completo lo porta a `detached`; undo lo riattiva.

### 12.2 Link tra Document

Un collegamento nel testo usa il mark:

```json
{
  "type": "documentLink",
  "attrs": {
    "documentLinkId": "uuid-v7",
    "targetDocumentId": "uuid-v7",
    "targetDocumentAnchorId": null
  }
}
```

`DocumentLink` è canonico per sorgente, destinazione e lifecycle; il mark è validato e conserva gli attributi necessari alla navigazione e al rendering offline. Il testo visibile resta nel documento. Il mark può convivere con formattazioni inline e `conceptOccurrence`: un collegamento editoriale non crea una Relation tra Concept e non trasforma un Document in Concept.

Il salvataggio estrae e riconcilia i link nella stessa transazione del Document, considerando insieme `document_json` e i `body_json` delle DocumentNote attive. Rifiuta ID duplicati nello stesso o in differenti contenitori, mismatch con il record, target inesistenti e anchor appartenenti a un altro Document. `source_document_id` identifica il Document aggregato; il contenitore effettivo del mark si ricava dalla fonte JSON autorevole e non viene duplicato nel record. Copy/paste genera nuovi `documentLinkId`; un cut/paste verificato nello stesso Document può conservarli con la policy prudente già adottata per le occurrence. I backlink si ottengono interrogando i record attivi e sono ricostruibili dal contenuto autorevole.

La cancellazione fisica di un Document referenziato non avviene a cascata: è bloccata finché i link entranti attivi non vengono gestiti esplicitamente. Se un anchor diventa `detached`, il link conserva la destinazione originale, viene segnalato come non risolto e può ripiegare all'inizio del Document; nessun salvataggio lo riscrive silenziosamente.

### 12.3 Formule

Le formule sono nodi TipTap/ProseMirror `inlineMath` e `blockMath`. Il nodo conserva il sorgente testuale della formula nel `document_json`; anteprima, accessibilità e output nei vari formati sono rappresentazioni derivate. Il plain text applica una trasformazione deterministica che conserva una forma testuale ricercabile del sorgente.

Il renderer non è parte della fonte di verità e non produce immagini autorevoli della formula. La scelta di una libreria gratuita e della sintassi supportata avviene nella fase dedicata, insieme a validazione, limiti, errori di parsing, copy/paste, undo/redo e round trip.

### 12.4 Document lunghi, capitoli e note editoriali

Un `Document` con `document_type = book` può essere monolitico oppure radice di una gerarchia editoriale. Nel caso monolitico, parti, capitoli e sezioni sono heading con `role` e `documentAnchorId`. Nel caso composto, `parent_document_id`, `structural_role` e `sort_order` definiscono figli ordinati e annidabili. Il titolo principale e il sottotitolo sono metadata del Document radice, non un heading duplicato obbligatorio.

La gerarchia Document è aciclica e separata dalla gerarchia Context. Context e Tag non si ereditano implicitamente dal parent: eventuali viste aggregate dichiarano esplicitamente se includono i discendenti. Un parent con figli non viene cancellato a cascata e un figlio non può comparire sotto più parent.

L'indice di un libro composto concatena ricorsivamente i Document secondo `sort_order` e poi gli heading interni; ogni voce continua a puntare a UUID stabili. Editing e revisione restano isolati per Document. Lo split/move strutturale preserva gli ID solo attraverso il comando transazionale dedicato descritto nella sezione 6; il normale clipboard tra Document crea nuove identità quando previsto dalle invarianti.

Il riferimento a footnote/endnote è un nodo inline atomico:

```json
{
  "type": "documentNoteReference",
  "attrs": {
    "documentNoteId": "uuid-v7"
  }
}
```

Il corpo ricco vive in `DocumentNote.body_json`, validato con una allowlist incrementale: nella FASE 21 ammette paragrafi, formattazione inline, formule e DocumentLink; nella FASE 22 aggiunge BibliographicCitation. Note annidate, heading e anchor di range restano vietati. Riferimento, record, body, `plain_text`, revisione del Document e indici cambiano nella stessa transazione. Un riferimento sconosciuto non crea una nota implicitamente.

Numerazione e collocazione sono derivate dall'ordine dei riferimenti. Per `footnote`, la posizione a piè di pagina esiste solo in preview paginata ed export; l'editor continuo usa popover o pannello. Per `endnote`, lo scope `chapter` usa il Document figlio con ruolo `chapter` oppure il più vicino heading precedente con quel ruolo; `document` raccoglie le note alla fine del Document proprietario; `book` richiede una radice/antenato `book` e aggrega le note alla fine dell'opera.

Copy/paste duplica body e riferimento con un nuovo UUID e rigenera ricorsivamente gli ID di DocumentLink e, dalla FASE 22, BibliographicCitation incorporati, riusando destinazioni, Source e locator. Cut/paste verificato nello stesso Document conserva le identità solo in assenza di duplicati. Tra Document viene sempre creata una nuova DocumentNote e i nuovi record incorporati appartengono alla destinazione. Delete completo produce `detached` sia per la nota sia per le manifestazioni incorporate; undo le riattiva con gli stessi ID e nessuna rimozione fisica avviene nel normale salvataggio.

### 12.5 Reference manager e bibliografia

Il reference manager è un modulo del monolite, non un servizio esterno. Usa `Source` come record bibliografico unico, `SourceContributor` per persone/enti e ruoli, `SourceIdentifier` per ISBN/DOI e altri identificatori, e `SourceLocator` per pagine o sezioni. Import e deduplicazione propongono candidati ma non fondono record senza conferma.

Nel testo una citazione usa un nodo atomico:

```json
{
  "type": "bibliographicCitation",
  "attrs": {
    "citationId": "uuid-v7"
  }
}
```

Il record `BibliographicCitation` e gli item ordinati sono autorevoli per fonti, locator, prefissi, suffissi e opzioni. I nodi possono vivere nel contenuto principale o nei body delle DocumentNote; ogni `citationId` compare una sola volta nell'aggregato del Document e `document_id` ne identifica il proprietario comune. La label resa nell'editor non viene salvata come testo autorevole. `BibliographySettings` seleziona stile e locale; il nodo `bibliography` è un placeholder per una vista rigenerabile dalle citazioni attive e dalle Source incluse esplicitamente. Nell'export di un Document `book` contenitore, le impostazioni del root governano la bibliografia aggregata dei discendenti; un figlio applica le proprie solo quando esportato autonomamente.

La provenance (`SourceAnchor`/`SourceCitation`) resta distinta dalla citazione bibliografica: un passaggio può avere evidence senza un richiamo visibile, oppure una citazione può comparire come elemento editoriale senza creare un range di provenance. Entrambe condividono la stessa Source.

Il modulo deve funzionare offline e con componenti gratuiti. Formati di import/export bibliografico e motore di stile vengono scelti nella fase dedicata dopo verifica delle licenze; nessun lookup remoto è necessario per il funzionamento di base.

### 12.6 Confine di export

L'export legge il `document_json` validato, i `DocumentNote.body_json` e i record semantici/bibliografici necessari tramite un trasformatore intermedio e produce artefatti derivati. Non legge `plain_text` come sostituto del contenuto e non modifica Document, link, anchor o altri record.

I formati previsti sono:

- HTML;
- DOCX;
- OpenDocument Text (`.odt`, formato testuale della famiglia ODF);
- LaTeX (`.tex`).

Ogni exporter dichiara una mappatura per nodi, mark, stili semantici, parti/capitoli, indice, formule, immagini, footnote/endnote, citazioni, bibliografia, occurrence e link interni. Un elemento non rappresentabile non viene eliminato silenziosamente: l'export fallisce oppure produce una diagnostica esplicita secondo il profilo scelto.

Per l'export di un singolo Document, i link verso Document esterni all'artefatto restano riferimenti diagnosticati e non vengono inventati URL pubblici. L'export di un Document contenitore percorre ricorsivamente i figli nell'ordine autorevole ed equivale a un export multi-Document. Destinazioni e anchor vengono convertiti in link relativi, bookmark o label del formato. Il bundle conserva una mappa di export senza trasformarla in identità di dominio.

La scelta di librerie avviene solo nella fase di export, dopo verifica di licenza e capacità reale sui quattro formati; non vengono introdotti servizi di conversione a pagamento.

## 13. Sicurezza dei dati e osservabilità

- foreign key SQLite abilitate per ogni connessione;
- transazioni per ogni modifica multi-entità;
- timestamp `TEXT` RFC 3339 UTC canonici con millisecondi (`YYYY-MM-DDTHH:mm:ss.SSSZ`);
- nessuna cancellazione a cascata da Concept verso Document o testo;
- validazione server-side anche quando il client ha già validato;
- backup e migrazioni prima di introdurre operazioni distruttive;
- file caricati fuori dal percorso pubblico, serviti da un endpoint che valida l'Asset richiesto;
- nomi originali trattati come metadata, mai come path di storage.

I log diagnostici non devono diventare una seconda fonte di verità.

## 14. Decisioni differite

Restano intenzionalmente fuori dalla FASE 0:

- schema eseguibile e forma precisa degli endpoint;
- libreria per migration e test PHP;
- ancoraggio robusto dei Comment;
- retention degli oggetti `detached`;
- indicizzazione derivata del testo delle occurrence;
- multi-context per Document;
- forma precisa degli endpoint di upload e limiti dimensionali predefiniti;
- retention e garbage collection di SourceAnchor e Asset non referenziati;
- renderer della Knowledge Map;
- qualsiasi integrazione AI;
- sintassi e renderer delle formule;
- forma del trasformatore intermedio e librerie di export;
- profili content-only/metadata e packaging multi-Document;
- soglie e strategia UI per suggerire lo split di Document molto grandi;
- stile di numerazione e raggruppamento visuale delle endnote nell'export di un libro composto.

Queste decisioni devono essere prese nella fase che le richiede, senza invalidare le invarianti qui definite.

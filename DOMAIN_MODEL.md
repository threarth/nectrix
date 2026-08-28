# Modello di dominio

## 1. Linguaggio ubiquitario

```text
KnowledgeObject = identità interrogabile che è esclusivamente Concept oppure Entity
Concept         = conoscenza astratta / di che cosa sto parlando
Entity          = cosa specifica sulla quale raccolgo dati
Context         = in quale ambito lo sto studiando o usando
Tag             = come voglio classificare o gestire il materiale
SemanticBlock   = dati strutturati riferiti a una Entity secondo un Template
Document        = unità editoriale, da un testo breve fino a un libro
```

`KnowledgeObject` è una generalizzazione chiusa usata soltanto per identità, occurrence e relazioni comuni:

```text
KnowledgeObject
├── Concept
└── Entity
```

Concept ed Entity sono binari paralleli: il primo rappresenta conoscenza astratta, la seconda una cosa specifica. Nessuno dei due è un Context o un Tag. Concept, Entity, Context e Tag restano dimensioni distinte; l'uguaglianza dei loro nomi non implica uguaglianza semantica o referenziale. Document è l'unità editoriale che le ospita o le organizza.

## 2. Diagramma logico iniziale

```text
Context (parent) 1 ──< Context (children)
Context 1 ──< Document >──< DocumentTag >── 1 Tag

KnowledgeObject <|── Concept ──< ConceptAlias
                <|── Entity >── 1 EntityType
                         └──< EntityIdentifier

Document 1 ──< KnowledgeOccurrence >── 1 KnowledgeObject
KnowledgeObject 1 ──< KnowledgeRelation (source/target)

Entity 1 ──< SemanticBlock >── 1 Template ──< TemplateField
SemanticBlock 1 ──< FieldValue >── 1 TemplateField
FieldValue ──> 0..1 Concept (collegamento semantico opzionale)

Source/SourceLocator ── associazioni dedicate ──>
  Document | KnowledgeObject | KnowledgeOccurrence |
  SemanticBlock | FieldValue | KnowledgeRelation

Document (parent) 1 ──< Document (children)
Document 1 ──< DocumentAnchor
Document (source) 1 ──< DocumentLink >── 1 Document (target)
DocumentLink ──> 0..1 DocumentAnchor (target)

Document 1 ──< DocumentNote
          ├──< BibliographicCitation >──< BibliographicCitationItem >── 1 Source
          └── 0..1 BibliographySettings

 Source 1 ──< SourceContributor
        └──< SourceIdentifier
```

Comment, Source, Asset, DocumentNote e citazioni bibliografiche sono definiti concettualmente, ma non entrano nello schema eseguibile della Phase 1.1. `KnowledgeObject`, Concept, Entity, occurrence, relazioni e dati strutturati entrano invece con la migration additiva della Phase 1.1, senza API o UI premature.

## 3. Entità

### 3.1 Document

Rappresenta un documento editabile.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `title` | string | può essere vuoto, non nullo |
| `subtitle` | string | opzionale |
| `document_type` | enum | `standard` o `book`; influenza UI/export, non crea entità diverse |
| `parent_document_id` | UUIDv7 nullable | self-FK; raggruppamento editoriale opzionale, aciclico |
| `structural_role` | enum | `standalone`, `front_matter`, `part`, `chapter`, `section`, `back_matter` |
| `sort_order` | integer nullable | ordine tra fratelli; obbligatorio quando esiste un parent |
| `language` | string nullable | lingua principale del contenuto |
| `document_json` | JSON | fonte autorevole del contenuto |
| `plain_text` | text | derivato dal contenuto principale e dalle note editoriali attive, non modificabile direttamente |
| `context_id` | UUIDv7 nullable | FK verso Context; un solo Context principale |
| `status` | enum | `active`, `archived`, `trashed`; introdotto nella FASE 6.1 |
| `revision` | integer | parte da `0`, cresce di uno a ogni salvataggio accettato |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato a ogni modifica persistita |

`revision` fa parte del modello minimo: il client deve inviare la revisione letta e l'API deve rifiutare un update se nel frattempo il Document è cambiato.

La tabella descrive il modello di arrivo, non lo schema da creare tutto insieme. La FASE 1 introduce soltanto `id`, `title`, `document_json`, `plain_text`, `revision` e timestamp. `status` entra nella FASE 6.1, `context_id` nella FASE 8; `subtitle`, `document_type`, `parent_document_id`, `structural_role`, `sort_order` e `language` entrano con migrazioni nella FASE 21. Fino a quella fase `plain_text` deriva dal solo contenuto principale; dalla FASE 21 include anche le DocumentNote attive nell'ordine dei riferimenti.

Dalla FASE 2 il JSON può contenere il mark `highlight` con il solo attributo visuale opzionale `color` come `#RRGGBB`; assenza e valori storici `yellow`, `green`, `blue`, `pink` restano leggibili. La palette configurabile dell'editor è una preferenza locale da 4 a 10 colori, non un'entità di dominio. Highlight non possiede identità di dominio, foreign key o lifecycle separato; il suo testo continua a contribuire normalmente a `plain_text`.

Tutti i timestamp di dominio sono stringhe `TEXT` RFC 3339 in UTC con millisecondi e forma canonica `YYYY-MM-DDTHH:mm:ss.SSSZ`. `created_at` e `updated_at` coincidono alla creazione; un update accettato modifica `updated_at`, mentre un tentativo rifiutato non modifica alcun timestamp.

Un Document può contenere zero o più occurrence e avere zero o più Tag. Può rappresentare un testo breve, un elaborato o un libro senza cambiare identità o tabella. I collegamenti ipertestuali usano l'ID stabile del Document, mai il titolo, come destinazione autorevole.

Un Document può avere al massimo un parent e molti figli, a profondità arbitraria. La gerarchia è editoriale, non un Context: raggruppa parti e capitoli senza cambiare il significato dei KnowledgeObject o l'ambito di studio. Sono vietati cicli, auto-parenting e duplicazioni dello stesso ordine tra fratelli.

Archiviare o spostare nel trash un Document non elimina il contenuto né i KnowledgeObject collegati e non cambia lo stato persistente delle sue KnowledgeOccurrence. Un Document `archived` è in sola lettura nei flussi ordinari, escluso dalle liste predefinite ma ricercabile con scope esplicito; un Document `trashed` compare soltanto nella vista di recupero. Il purge fisico è un comando di manutenzione distinto, con preview, backup e verifica transazionale di figli, link, evidence e associazioni; solo allora elimina il Document e le manifestazioni Document-owned, senza eliminare KnowledgeObject, SemanticBlock o FieldValue. Non è un endpoint CRUD ordinario.

Un Document senza parent usa `structural_role = standalone` e `sort_order = NULL`. Un figlio usa un ruolo strutturale non `standalone` e un ordine univoco tra i fratelli.

Un libro piccolo può rimanere un singolo Document con capitoli rappresentati da heading semantici. Quando serve, un Document `book` può diventare contenitore di Document figli ordinati. Il contenuto proprio del parent, se presente, precede i figli nell'export; front matter e back matter strutturati usano preferibilmente figli con ruolo esplicito.

La suddivisione di contenuto già esistente usa un comando strutturale esplicito e transazionale. Il comando sposta blocchi completi e trasferisce l'ownership di KnowledgeOccurrence di entrambi i sottotipi, SourceAnchor, DocumentAnchor, DocumentLink sorgenti, DocumentNote, BibliographicCitation e CommentThread testuali coinvolti; aggiorna inoltre `target_document_id` dei link che puntano ad anchor trasferiti. Gli ID restano invariati. SemanticBlock e FieldValue restano appartenenti alla Entity e non vengono copiati; cambiano soltanto eventuali associazioni documentali esplicite. Il normale cut/paste tra Document non gode di questa eccezione e segue le regole prudenti di nuova identità.

### 3.2 KnowledgeObject

Super-tipo persistito e chiuso di Concept ed Entity. Conserva soltanto identità, discriminator e timestamp comuni; non contiene un nome generico né assorbe Context o Tag.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key globale del KnowledgeObject |
| `object_type` | enum | `concept` oppure `entity`; immutabile |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato con il relativo sottotipo |

Ogni record ha esattamente un record di sottotipo coerente. Il discriminator viene ripetuto soltanto dove consente a SQLite di applicare foreign key composte e non è una scorciatoia per introdurre nuovi sottotipi.

### 3.3 Concept

Rappresenta conoscenza astratta globale, indipendente da Document e parole usate per esprimerla. È un sottotipo di KnowledgeObject distinto da Entity.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key e FK verso KnowledgeObject `concept` |
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

### 3.4 ConceptAlias

Denominazione alternativa di un Concept; non è una occurrence.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `concept_id` | UUIDv7 | FK obbligatoria verso Concept |
| `alias` | string | obbligatorio, non vuoto |

Lo stesso Concept non può avere due alias uguali dopo la normalizzazione definita per la ricerca. Lo stesso alias può appartenere a Concept diversi perché il linguaggio può essere ambiguo. Aggiungere o rimuovere un Alias non modifica il testo e non crea/distrugge occurrence.

### 3.5 KnowledgeOccurrence

Identità persistente di un intervallo vivo di testo dichiarato manifestazione di un Concept oppure di una Entity. Nel linguaggio UI può essere abbreviata in “Occurrence”.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 globale | primary key; presente nel mark |
| `knowledge_object_id` | UUIDv7 | FK obbligatoria verso KnowledgeObject; immutabile per l'identità corrente |
| `object_type` | enum | `concept` o `entity`; deve coincidere con il KnowledgeObject e con il mark |
| `document_id` | UUIDv7 | FK obbligatoria verso Document; cambia solo con un comando strutturale atomico di split/move |
| `status` | enum | `active`, `detached`, `deleted` |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato ai cambi di stato |

Non contiene testo, posizione assoluta o copia autorevole del range. Il testo corrente e la posizione si estraggono dal documento del Document cercando il mark con il suo ID.

Nel contenuto il mark `knowledgeOccurrence` serializza su uno `span` con i soli attributi `data-occurrence-id`, `data-knowledge-object-id` e `data-object-type`. Il parsing lo accetta soltanto con i tre attributi presenti, ID UUIDv7 canonici e discriminator `concept` oppure `entity`: un frammento HTML incompleto o manipolato conserva testo e formattazione ordinaria e perde il mark, senza creare alcun record. Lo stesso ID copre una sola sequenza di text node adiacenti dentro un unico textblock; intervalli disgiunti vengono rifiutati al salvataggio.

Cambiare KnowledgeObject o passare un range da Concept a Entity non muta l'associazione in place: termina/detacha l'occurrence precedente e ne crea una nuova. L'identità esprime anche l'associazione semantica, non soltanto una decorazione visuale.

Transizioni ammesse:

```text
creazione con mark nella stessa transazione ──→ active
active + mark assente al salvataggio ─────────→ detached
detached + stesso mark ripristinato ──────────→ active
active|detached + eliminazione esplicita ─────→ deleted
```

`deleted` non deriva mai dalla normale cancellazione editoriale ed è terminale nel modello iniziale. Un eventuale ripristino futuro dovrà creare una nuova Occurrence oppure introdurre una transizione esplicita con relativa migrazione delle invarianti.

### 3.6 EntityType

Classificazione configurabile di Entity, mai hardcoded nella UI o nel codice applicativo.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `name` | string | obbligatorio e univoco case-insensitive |
| `description` | text | opzionale |
| `status` | enum | `active` oppure `archived` |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato a ogni modifica |

Esempi sono `Company`, `Lesion`, `Drug`, `Book`, `Paper`, `Person` e `Security`. La Phase 1.1 persiste il tipo senza anticiparne il lifecycle UI; la FASE 6 aggiunge la colonna `status` con una migration additiva e i comandi espliciti di archive e restore. Una EntityType usata da Entity non viene cancellata: può essere archiviata per impedirne nuove assegnazioni, restando valida per le Entity esistenti. Sostituzione e merge sono comandi distinti, confermati e transazionali.

### 3.7 Entity

Rappresenta una cosa specifica sulla quale raccogliere dati, per esempio `Rocket Lab USA`, `Lesione #1` o `Libro Y`.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key e FK verso KnowledgeObject `entity` |
| `entity_type_id` | UUIDv7 | FK obbligatoria verso EntityType |
| `name` | string | nome di visualizzazione obbligatorio |
| `description` | text | opzionale |
| `status` | enum | `active` oppure `archived`; non usa lo stato Concept `orphan` |

Una Entity può esistere senza occurrence e senza SemanticBlock, può avere più blocchi anche dello stesso Template e non diventa automaticamente un Concept. La perdita dell'ultima occurrence non ne modifica lo stato: resta `active` finché l'utente non la archivia esplicitamente. Una Source bibliografica può in futuro essere collegata a una Entity, ma non coincide automaticamente con essa: `Libro Y` come oggetto studiato e il record Source usato per una citazione hanno lifecycle e responsabilità differenti.

### 3.8 EntityIdentifier (modello per la FASE 7)

Identificatore strutturato di una Entity; non è un ConceptAlias e non sostituisce un FieldValue descrittivo.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `entity_id` | UUIDv7 | FK obbligatoria verso Entity |
| `scheme` | string | tipo normalizzato, per esempio `ticker`, `cik`, `lei`, `internal_clinical_id` |
| `value` | string | forma originale validata e non vuota |
| `normalized_value` | string | derivato per ricerca e deduplicazione |
| `authority_or_namespace` | string nullable | autorità che rende interpretabile lo scheme, per esempio `NASDAQ` o `SEC` |

La combinazione normalizzata (`entity_id`, `scheme`, `authority_or_namespace`, `normalized_value`) è univoca nella stessa Entity. `scheme` è una chiave lowercase stabile; authority assente è rappresentata da `NULL`, non da stringa vuota, ed è obbligatoria quando richiesta dallo scheme. `normalized_value` deriva da una policy versionata per scheme che dichiara anche case-sensitivity; non esiste una normalizzazione universale implicita. La stessa identità dichiarata su Entity differenti genera un candidato duplicato, mai un merge automatico. Un ticker usa l'exchange come authority/namespace; proprietà come settore o exchange visualizzato possono anche essere FieldValue, ma non diventano Alias. Creare una Entity o una KnowledgeOccurrence non crea EntityIdentifier dal testo.

### 3.9 Template e TemplateField

Un Template è una definizione utente di struttura riutilizzabile e globale. Dalla FASE 10.1 EntityType e Template possono avere raccomandazioni molti-a-molti ordinate per guidare la UI; non sono vincoli di compatibilità e un Template non raccomandato resta applicabile con scelta esplicita.

| Campo Template | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `name` | string | obbligatorio e univoco case-insensitive |
| `description` | text | opzionale |
| `status` | enum | `active` oppure `archived` |
| `created_at`, `updated_at` | timestamp | lifecycle standard |

Ogni TemplateField appartiene a un solo Template:

| Campo TemplateField | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key stabile; il nome può cambiare senza cambiare i valori |
| `template_id` | UUIDv7 | FK obbligatoria verso Template |
| `name` | string | label utente obbligatoria |
| `field_type` | enum | tipo immutabile quando esistono valori |
| `is_required` | boolean | obbligatorietà applicata ai blocchi completi |
| `is_searchable` | boolean | il valore partecipa alle viste di ricerca |
| `is_indexed` | boolean | dichiara la necessità di indice/query efficiente |
| `sort_order` | integer | univoco nel Template |
| `options_json` | JSON nullable | opzioni validate per enum/multi-enum |
| `default_value_json` | JSON nullable | configurazione tipizzata, non un FieldValue già assegnato |

Tipi previsti:

```text
text, rich_text, number, boolean, enum, date, measurement,
currency, percentage, entity_reference, concept_reference, url,
source_reference, multi_enum, multi_entity_reference,
multi_concept_reference
```

`source_reference` può essere dichiarato in Phase 1.1, ma un relativo FieldValue non è persistibile finché la FASE Sources non introduce una FK verificabile verso Source. Non viene accettato nel frattempo un UUID privo di vincolo.

### 3.10 SemanticBlock

Istanza di un Template appartenente a una sola Entity. Non è un range evidenziato, una KnowledgeOccurrence o una copia incorporata nel `document_json`. La FASE 10.1.1 può introdurre soltanto un nodo di riferimento e rendering; record SemanticBlock/FieldValue restano autorevoli.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `entity_id` | UUIDv7 | FK obbligatoria verso Entity |
| `template_id` | UUIDv7 | FK obbligatoria verso Template |
| `sort_order` | integer | ordine univoco dei blocchi nella Entity |
| `created_at`, `updated_at` | timestamp | lifecycle standard |

Una Entity può avere più SemanticBlock basati su Template differenti o ripetuti. Il blocco non duplica la definizione dei field: i suoi valori puntano agli ID stabili dei TemplateField.

Dalla FASE 10.1.1 un nodo `semanticBlockReference` può riferire il blocco tramite UUID e renderizzarne una vista derivata. Ogni collocazione possiede un `referenceId` distinto; copy/paste rigenera questo ID mantenendo la destinazione. Il nodo non contiene una copia dei FieldValue e non cambia l'ownership Entity del blocco. Un analogo `entityReference` può riferire una Entity senza trasformarsi in KnowledgeOccurrence: è un riferimento atomico, non la dichiarazione che un range testuale sia una manifestazione dell'Entity.

### 3.11 FieldValue

Valore tipizzato e interrogabile di un TemplateField in un SemanticBlock.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `semantic_block_id` | UUIDv7 | FK verso SemanticBlock |
| `template_field_id` | UUIDv7 | deve appartenere al Template del blocco |
| `field_type` | enum | deve coincidere con il TemplateField |
| `ordinal` | integer | `0` per tipi singoli; ordina i valori dei tipi multi |
| payload tipizzato | colonne dedicate | esattamente la rappresentazione ammessa dal tipo |
| `linked_concept_id` | UUIDv7 nullable | collegamento semantico opzionale a Concept |
| `origin` | enum | `manual`, `provider`, `derived`, `ai_suggested` |
| `provider_id` | string nullable | identificatore stabile del provider configurato futuro |
| `retrieved_at` | timestamp nullable | obbligatorio con origin `provider` |
| `created_at`, `updated_at` | timestamp | lifecycle standard |

Testo/enum/URL, rich text JSON, numeri, boolean, date, unità di misura, valuta e reference usano colonne distinte. `measurement` è una coppia numero/unità; misure multidimensionali possono usare field distinti o una cardinalità multi definita esplicitamente dal Template, non una stringa opaca. `percentage` usa un rapporto decimale canonico (`0.125` = `12,5%`) senza limitarlo all'intervallo 0..1, così restano rappresentabili variazioni negative o superiori al 100%. I tipi multi sono rappresentati da più righe ordinate, quindi restano interrogabili.

`linked_concept_id` è indipendente dal valore: per esempio `DWI = restricted` può collegarsi al Concept `Restricted diffusion`. Il collegamento è opzionale e non crea Concept automaticamente. Per `concept_reference`, invece, la reference è il valore stesso.

La provenienza da Source verrà aggiunta contestualmente alla FASE Sources con una FK verificabile. Un provider non aggiorna in place un valore `manual`: propone un nuovo valore o una modifica da confermare. Provider reali, autocomplete e mapping multi-field restano fuori dalla Phase 1.1.

#### Provider configurabile (modello per la FASE 22.1)

`provider_id` diventerà una FK verso una configurazione Provider persistente quando il registry verrà introdotto. Un Provider dichiara tipo di adapter, stato e capacità; mapping dedicati associano le sue chiavi esterne a TemplateField senza cambiare il modello FieldValue. Configurazione precisa, segreti e credenziali restano decisioni della fase e non vengono salvati in chiaro per comodità. Il core non dipende dalla rete e nessun adapter crea tabelle di dominio proprie.

### 3.12 Context

Ambito gerarchico nel quale un Document viene studiato o utilizzato.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `name` | string | obbligatorio, non vuoto |
| `parent_id` | UUIDv7 nullable | self-FK; `NULL` per radice |

Sono vietati cicli e auto-parenting. La profondità non ha un limite di dominio. È raccomandata l'unicità del nome normalizzato tra fratelli (`parent_id`, nome normalizzato), mentre lo stesso nome può comparire in rami diversi. Il percorso e la profondità sono calcolati dalla gerarchia e non sono identità persistenti.

Un sub-context è semplicemente un Context con `parent_id`; non richiede una seconda tabella. Un Context non definisce il significato dei KnowledgeObject osservati nei Document. Concept, Entity, EntityType e SemanticBlock vengono raggiunti inizialmente tramite Context→Document→KnowledgeOccurrence→KnowledgeObject e, per i dati strutturati, →Entity→SemanticBlock. Spostare un Document o un ramo Context non duplica, rinomina o riassegna direttamente KnowledgeObject.

### 3.13 Tag

Metadata libero usato per classificazione e gestione.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `name` | string | obbligatorio; memorizzato senza necessità del prefisso `#` |

Un Tag non ha canonical name, Alias, occurrence o relazioni semantiche. Il nome normalizzato è unico tra i Tag. Il carattere `#` è una convenzione di presentazione e ricerca.

### 3.14 DocumentTag

Associazione molti-a-molti tra Document e Tag.

| Campo | Tipo logico | Regole |
|---|---|---|
| `document_id` | UUIDv7 | FK verso Document |
| `tag_id` | UUIDv7 | FK verso Tag |

La coppia (`document_id`, `tag_id`) è la chiave primaria logica. La duplicazione dell'assegnazione non è ammessa.

### 3.15 KnowledgeRelation

Arco semantico diretto tra due KnowledgeObject. Supporta Concept→Concept, Entity→Entity ed Entity↔Concept senza coinvolgere Context o Tag.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `source_knowledge_object_id` | UUIDv7 | FK verso KnowledgeObject |
| `source_object_type` | enum | discriminator verificato della sorgente |
| `target_knowledge_object_id` | UUIDv7 | FK verso KnowledgeObject |
| `target_object_type` | enum | discriminator verificato della destinazione |
| `relation_type` | string | obbligatorio; tipi noti o custom |
| `description` | text | opzionale |

Ogni KnowledgeObject può partecipare a molte Relation come sorgente e destinazione. Più relazioni tra la stessa coppia sono consentite se esprimono predicati o descrizioni differenti. Direzione, simmetria e inverso sono proprietà del tipo di relazione e non vanno inferiti dal nome o dal sottotipo degli estremi.

La provenance tramite occurrence verrà aggiunta in una fase successiva con un'associazione dedicata, non con una lista serializzata nel record.

### 3.16 Comment (solo modello concettuale)

Un commento è una discussione associata a un soggetto stabile. Il modello previsto distingue:

- `TextCommentThread`: identità, `document_id`, anchor strutturale versionata, eventuale associazione verificata alla KnowledgeOccurrence nel range, stato e timestamp;
- thread su oggetto strutturato, collegati tramite associazioni dedicate a KnowledgeObject, KnowledgeRelation, SemanticBlock o FieldValue senza simulare un range testuale;
- `CommentMessage`: identità, `thread_id`, corpo, timestamp ed eventuale autore futuro.

L'anchor testuale dovrà usare strutture ProseMirror robuste e una strategia di remapping. I subject strutturati usano FK reali e non una coppia generica `target_type`/`target_id`. Il formato preciso verrà scelto nella FASE 18 dopo test dedicati. In questa fase non vengono create tabelle o API.

### 3.17 Source e provenance (modello per la FASE Sources)

Una Source rappresenta una provenienza riutilizzabile nel catalogo personale.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `type` | enum | `url`, `book`, `pdf`, `image`, `figure`, `document`, `other`; `document` indica una fonte esterna, non l'entità editoriale Document |
| `title` | string | obbligatorio, non vuoto |
| `subtitle` | string | opzionale |
| `container_title` | string | opzionale; rivista, raccolta o opera contenitore |
| `publisher` | string | opzionale |
| `edition` | string | opzionale |
| `volume` | string | opzionale |
| `issue` | string | opzionale |
| `pages` | string | opzionale; pagine dell'opera nel contenitore |
| `year` | integer nullable | anno bibliografico, validato |
| `month` | integer nullable | mese bibliografico validato quando presente |
| `day` | integer nullable | giorno bibliografico validato quando presente |
| `language` | string nullable | lingua della fonte |
| `url` | string nullable | URL validato quando presente |
| `file_path` | string nullable | riferimento locale gestito dal sistema |
| `notes` | text | opzionale |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato a ogni modifica |

Il catalogo dei libri non è una seconda entità bibliografica: è la vista delle Source con `type = book`. Una Source può essere collegata a una Entity che rappresenta lo stesso paper/libro studiato, ma non ne condivide identità o lifecycle. Il dialog può cercare e riusare una Source esistente oppure crearla, evitando duplicazioni bibliografiche.

`SourceContributor` conserva autori, curatori, traduttori e altri contributori in ordine bibliografico:

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `source_id` | UUIDv7 | FK obbligatoria verso Source |
| `role` | enum | `author`, `editor`, `translator`, `other` |
| `given_name` | string nullable | nome personale strutturato |
| `family_name` | string nullable | cognome personale strutturato |
| `literal_name` | string nullable | autore collettivo/istituzionale o forma non scomponibile |
| `ordinal` | integer | ordine stabile nella stessa Source |

Ogni contributore usa o la forma personale o `literal_name`; non entrambe come rappresentazioni concorrenti. `ordinal` è univoco nella stessa Source. La stringa bibliografica visualizzata è derivata dallo stile citazionale.

`SourceIdentifier` consente più identificatori senza colonne sparse o duplicazioni:

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `source_id` | UUIDv7 | FK obbligatoria verso Source |
| `scheme` | string | per esempio `isbn`, `doi`, `issn`, `pmid` |
| `value` | string | valore originale validato |
| `normalized_value` | string | derivato per ricerca e deduplicazione |

La coppia normalizzata (`scheme`, `normalized_value`) è univoca nella stessa Source. Se compare in Source diverse viene segnalata come possibile duplicato, ma una fusione richiede sempre conferma esplicita.

SourceIdentifier resta distinto da EntityIdentifier: il primo identifica un record bibliografico (`doi`, `isbn`, `pmid`), il secondo una cosa specifica nel suo namespace (`ticker/NASDAQ`, `cik/SEC`, `lei`). Se una Source e una Entity descrivono lo stesso oggetto, una loro associazione esplicita non fonde gli identificatori.

`SourceLocator` identifica una porzione della Source:

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `source_id` | UUIDv7 | FK obbligatoria verso Source |
| `page` | string nullable | supporta pagina singola o intervallo |
| `chapter` | string nullable | capitolo/numero/titolo |
| `section` | string nullable | sezione |
| `paragraph` | string nullable | paragrafo |
| `quote` | text nullable | estratto non autorevole |
| `coordinates` | JSON nullable | coordinate o anchor specifico del formato |

`DocumentSource` collega una Source, e opzionalmente un suo locator, all'intero Document:

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `document_id` | UUIDv7 | FK verso Document |
| `source_id` | UUIDv7 | FK verso Source |
| `source_locator_id` | UUIDv7 nullable | deve appartenere alla stessa Source |
| `include_in_bibliography` | boolean | include esplicitamente una fonte non citata nella bibliografia del Document |

`SourceAnchor` identifica un range vivo del Document senza copiarne il testo:

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key e `sourceAnchorId` nel mark |
| `document_id` | UUIDv7 | FK obbligatoria verso Document |
| `status` | enum | `active`, `detached`, `deleted` |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato ai cambi di stato |

`SourceCitation` associa una o più fonti allo stesso anchor:

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `source_anchor_id` | UUIDv7 | FK verso SourceAnchor |
| `source_id` | UUIDv7 | FK verso Source |
| `source_locator_id` | UUIDv7 nullable | deve appartenere alla stessa Source |

`SourceAnchor`/`SourceCitation` esprimono provenance di un passaggio e restano distinti da un marcatore bibliografico visibile nel testo. Una stessa Source può essere contemporaneamente evidence di un range e item di una citazione bibliografica senza duplicare il record Source.

Document, KnowledgeObject, KnowledgeOccurrence, SemanticBlock, FieldValue e KnowledgeRelation usano associazioni dedicate verso Source/SourceLocator nelle fasi previste. Le associazioni comuni a Concept/Entity possono riferire KnowledgeObject; gli altri subject hanno join table proprie. Non si usa una foreign key polimorfica non verificabile da SQLite. La FASE 16 abilita inoltre il payload FieldValue `source_reference` soltanto insieme alla FK Source verificabile.

### 3.18 Asset immagine (modello per la relativa fase)

Un Asset rappresenta un file immagine locale riutilizzabile; non rappresenta automaticamente una Source, un KnowledgeObject, un SemanticBlock o un FieldValue.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key e `assetId` nel nodo immagine |
| `media_type` | enum | inizialmente `image/png`, `image/jpeg`, `image/webp` |
| `original_filename` | string | solo metadata, mai path di storage |
| `storage_key` | string | nome interno opaco e univoco |
| `size_bytes` | integer | dimensione verificata |
| `sha256` | string | hash del contenuto |
| `width_px` | integer | larghezza intrinseca positiva |
| `height_px` | integer | altezza intrinseca positiva |
| `created_at` | timestamp | immutabile |

Il nodo immagine nel documento conserva posizione, `assetId`, alt text, titolo e proprietà di presentazione. Più nodi possono riferirsi allo stesso Asset. Associazioni verso KnowledgeObject, SemanticBlock, FieldValue, Source e SourceLocator sono esplicite e verificabili. La rimozione dell'ultimo nodo non cancella immediatamente il file, così undo e recupero restano possibili.

### 3.19 DocumentAnchor e DocumentLink (modello per la relativa fase)

`DocumentAnchor` identifica una destinazione navigabile stabile all'interno di un Document. Nella prima implementazione gli anchor sono applicati agli heading; un link all'intero Document non richiede un anchor.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key e `documentAnchorId` nel nodo editoriale |
| `document_id` | UUIDv7 | FK obbligatoria verso il Document proprietario |
| `kind` | enum | inizialmente `heading` |
| `status` | enum | `active`, `detached`, `deleted` |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato ai cambi di stato |

L'anchor non conserva titolo, testo o offset autorevoli. Il testo visibile, il livello dell'heading e la posizione corrente si estraggono dal `document_json`. La rimozione dell'heading porta l'anchor a `detached`; undo può riattivarlo con lo stesso ID. `deleted` richiede un comando esplicito.

`DocumentLink` identifica un collegamento ipertestuale presente nel documento di un Document sorgente.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key e `documentLinkId` nel mark editoriale |
| `source_document_id` | UUIDv7 | FK obbligatoria verso il Document che contiene il link |
| `target_document_id` | UUIDv7 | FK obbligatoria verso il Document destinazione; mai derivata dal titolo |
| `target_document_anchor_id` | UUIDv7 nullable | FK verso DocumentAnchor; se presente deve appartenere a `target_document_id` |
| `status` | enum | `active`, `detached`, `deleted` |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato ai cambi di stato |

Il testo cliccabile e la posizione del link appartengono al documento, non al record. Un link può puntare all'intero Document oppure a un suo anchor. I backlink sono una query sui `DocumentLink` attivi, non contenuto duplicato nel Document destinazione.

Il contenuto sorgente può essere `Document.document_json` oppure il `body_json` di una DocumentNote appartenente allo stesso `source_document_id`. Non serve una FK nullable verso DocumentNote: la collocazione editoriale si ricava dalla presenza del mark nella fonte autorevole. Lo stesso `documentLinkId` deve però comparire in un solo intervallo logico considerando insieme contenuto principale e tutte le note attive del Document; una duplicazione tra questi contenitori è invalida.

La normale rimozione del testo marcato porta il link a `detached`; non elimina né modifica la destinazione. Anche il detach della DocumentNote che contiene il mark detacha i suoi link incorporati; undo li riattiva con gli stessi ID. Copy/paste crea un nuovo `documentLinkId` mantenendo la destinazione. Solo un cut/paste verificato all'interno dello stesso Document può conservare l'ID. La cancellazione fisica di un Document con link entranti attivi è bloccata finché l'utente non li riassegna o rimuove esplicitamente; non esistono cascade dal target verso il contenuto dei Document sorgenti.

Un anchor `detached` rende non risolvibile la destinazione interna, ma non riscrive il link. La UI segnala il problema e può navigare prudentemente all'inizio del Document destinazione. Un input manipolato non può creare implicitamente Document o anchor.

Indice navigabile e formule non richiedono entità relazionali proprie. L'indice è derivato dagli heading e dai relativi DocumentAnchor; il sorgente delle formule inline o block appartiene al `document_json` e il rendering è derivato.

### 3.20 DocumentNote (modello per la relativa fase)

`DocumentNote` rappresenta una nota editoriale a piè di pagina o una nota finale. È parte del contenuto di un solo Document, ma il corpo può contenere più paragrafi e richiede quindi un proprio frammento JSON autorevole.

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key e `documentNoteId` nel nodo reference |
| `document_id` | UUIDv7 | FK obbligatoria verso il Document proprietario |
| `kind` | enum | `footnote` oppure `endnote` |
| `endnote_scope` | enum nullable | `chapter`, `document` o `book`; obbligatorio solo per `endnote` |
| `body_json` | JSON | fonte autorevole del corpo della nota; allowlist dedicata |
| `plain_text` | text | derivato da `body_json` |
| `status` | enum | `active`, `detached`, `deleted` |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato ai cambi di stato |

Nel testo principale compare un nodo inline atomico `documentNoteReference` con `documentNoteId`. Nella prima versione ogni DocumentNote ha esattamente un riferimento attivo: ordine, numero visualizzato e collocazione non sono memorizzati, ma derivano dalla posizione del riferimento e da `kind`/`endnote_scope`. L'allowlist evolve per fase: nella FASE 21 `body_json` ammette paragrafi, formattazione inline, formule e DocumentLink; la FASE 22 aggiunge BibliographicCitation. Heading, altre DocumentNote e anchor di range restano vietati nella prima versione.

Per un libro, il capitolo di una endnote con scope `chapter` è il Document antenato/figlio con ruolo `chapter` oppure, nel modello monolitico, il più vicino heading precedente con quel ruolo. Lo scope `book` richiede un Document radice/antenato con `document_type = book` e colloca la nota alla fine dell'opera aggregata. Spostare il riferimento ricalcola la collocazione senza cambiare l'identità della nota. Una footnote viene mostrata a piè di pagina solo nei renderer paginati; nell'editor può usare popover o pannello senza fingere una paginazione stabile.

La cancellazione del riferimento porta la DocumentNote a `detached`; undo la riattiva. Copy/paste duplica riferimento e corpo con un nuovo ID e rigenera anche gli ID delle manifestazioni incorporate, come DocumentLink e, dalla FASE 22, BibliographicCitation, mantenendone le destinazioni o Source. Un cut/paste verificato nello stesso Document conserva gli ID soltanto se non produce duplicati. Il paste tra Document crea sempre una nuova DocumentNote appartenente alla destinazione e aggiorna `source_document_id`/`document_id` dei nuovi record incorporati. DocumentNote annidate dentro `body_json` non sono ammesse nella prima versione.

### 3.21 Reference manager, citazioni e bibliografia (modello per la relativa fase)

Il reference manager è il servizio applicativo che gestisce il catalogo Source, contributori, identificatori, deduplicazione assistita, citazioni e bibliografie. Non introduce una seconda entità “Reference” parallela a Source.

`BibliographicCitation` identifica un marcatore citazionale visibile nel contenuto principale del Document o nel body di una sua DocumentNote:

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key e `citationId` nel nodo inline |
| `document_id` | UUIDv7 | FK obbligatoria verso il Document proprietario |
| `status` | enum | `active`, `detached`, `deleted` |
| `created_at` | timestamp | immutabile |
| `updated_at` | timestamp | aggiornato ai cambi di stato |

`BibliographicCitationItem` conserva una o più fonti nello stesso marcatore:

| Campo | Tipo logico | Regole |
|---|---|---|
| `id` | UUIDv7 | primary key |
| `citation_id` | UUIDv7 | FK obbligatoria verso BibliographicCitation |
| `source_id` | UUIDv7 | FK obbligatoria verso Source |
| `source_locator_id` | UUIDv7 nullable | deve appartenere alla stessa Source |
| `ordinal` | integer | ordine dell'item nella citazione |
| `prefix` | string | opzionale |
| `suffix` | string | opzionale |
| `suppress_author` | boolean | opzione citazionale, non modifica la Source |

`ordinal` è univoco nella stessa BibliographicCitation. Il nodo inline `bibliographicCitation` contiene soltanto `citationId`; label, autore/anno, numerazione e punteggiatura sono rendering derivati dai dati Source e dallo stile attivo. Lo stesso `citationId` compare una sola volta considerando insieme contenuto principale e DocumentNote attive del Document; la sua collocazione si ricava dal nodo, non da una seconda colonna di ownership.

`BibliographySettings` è una configurazione uno-a-zero/uno del Document:

| Campo | Tipo logico | Regole |
|---|---|---|
| `document_id` | UUIDv7 | primary key e FK verso Document |
| `style_id` | string | identificatore di uno stile citazionale disponibile localmente |
| `locale` | string nullable | locale bibliografico |
| `include_uncited_sources` | boolean | include i DocumentSource esplicitamente marcati per bibliografia |

La bibliografia è una vista derivata dalle BibliographicCitation attive e, se richiesto, dai DocumentSource con `include_in_bibliography`. Per un Document `book` contenitore, la bibliografia aggregata percorre ricorsivamente i figli; le BibliographySettings del root governano l'export dell'opera, mentre un figlio usa le proprie impostazioni solo quando viene esportato autonomamente. Può essere mostrata tramite un nodo placeholder `bibliography` e rigenerata in editor o export; il testo formattato non viene salvato come fonte autorevole.

Copy/paste di una citazione crea una nuova BibliographicCitation che riusa le stesse Source e locator; cut/paste verificato nello stesso Document può mantenere l'ID. Se la DocumentNote che la contiene diventa `detached`, anche la citazione incorporata diventa `detached` e undo la riattiva con lo stesso ID. Eliminare una citazione non elimina Source, contributori, identificatori o altre associazioni. Import, merge e deduplicazione non fondono record automaticamente: mostrano candidati e richiedono conferma.

## 4. Relazioni e cardinalità

- Document → Context: molti Document possono avere lo stesso Context; ogni Document ne ha al massimo uno.
- Document → Document: ogni Document ha al massimo un parent e può avere figli ordinati a profondità arbitraria.
- Context → Context: ogni Context ha al massimo un parent e può avere molti figli.
- KnowledgeObject → Concept|Entity: specializzazione totale ed esclusiva determinata da `object_type`.
- Concept → ConceptAlias: uno-a-molti.
- EntityType → Entity: uno-a-molti; ogni Entity ha esattamente un EntityType.
- EntityType ↔ Template: molti-a-molti ordinato come raccomandazione UI, senza vincolo di compatibilità.
- Entity → EntityIdentifier: uno-a-molti; anche zero, con unicità normalizzata nello scope della Entity.
- KnowledgeObject → KnowledgeOccurrence: uno-a-molti; anche zero.
- Document → KnowledgeOccurrence: uno-a-molti; anche zero.
- Entity → SemanticBlock: uno-a-molti; anche zero.
- Template → TemplateField: uno-a-molti ordinato.
- Template → SemanticBlock: uno-a-molti.
- Document.document_json → Entity/SemanticBlock: riferimenti editoriali dalla FASE 10.1.1, con identità di collocazione distinta dalla destinazione.
- SemanticBlock → FieldValue: uno-a-molti tipizzato e ordinato per i field multi.
- FieldValue → Concept: zero-o-uno come collegamento semantico opzionale, oltre alle reference che costituiscono il valore.
- Document ↔ Tag: molti-a-molti tramite DocumentTag.
- KnowledgeObject ↔ KnowledgeObject: molti-a-molti diretto e tipizzato tramite KnowledgeRelation.
- Document ↔ Source: molti-a-molti tramite DocumentSource.
- Document → SourceAnchor → SourceCitation → Source: provenienza di range testuali, anche con più fonti per range.
- Document.document_json → Asset: molti nodi possono riusare lo stesso file immagine.
- Document → DocumentAnchor: uno-a-molti; ogni anchor appartiene a un solo Document.
- Document (source) → DocumentLink: uno-a-molti; ogni link ha un solo Document sorgente.
- Document (target) ← DocumentLink: uno-a-molti; più Document possono puntare allo stesso Document o allo stesso suo anchor.
- Document → DocumentNote: uno-a-molti; ogni nota editoriale appartiene a un solo Document.
- Document → BibliographicCitation → BibliographicCitationItem → Source: citazioni inline, anche con più Source per marcatore.
- Document → BibliographySettings: zero-o-uno.
- Source → SourceContributor: uno-a-molti ordinato.
- Source → SourceIdentifier: uno-a-molti.
- Source/SourceLocator → Document, KnowledgeObject, KnowledgeOccurrence, SemanticBlock, FieldValue e KnowledgeRelation: associazioni dedicate e verificabili per ciascuna famiglia di subject.

## 5. Query fondamentali

### Per Concept

Si seleziona il Concept per ID, canonical name o Alias e si seguono le KnowledgeOccurrence attive di tipo `concept` verso i Document. Si includono separatamente FieldValue collegati e KnowledgeRelation esplicite. Il testo non determina l'identità.

### Per Entity

Si seleziona la Entity per ID, nome, EntityType o EntityIdentifier e si combinano tre percorsi dichiarati: occurrence testuali attive, FieldValue/linked Concept dei suoi SemanticBlock e KnowledgeRelation esplicite. Un match lungo un percorso non crea record sugli altri. L'uguaglianza di un Identifier tra Entity differenti segnala un candidato duplicato, non autorizza un merge.

### Strutturata

Si selezionano Template, TemplateField e payload tipizzati. I filtri su numero, boolean, data, enum e reference operano sulle colonne corrispondenti, non su rendering testuali. Full text, Concept/Entity, Context e Tag possono aggiungersi con join espliciti e ogni risultato dichiara il percorso di match.

### Per Context

Si selezionano Document assegnati al Context; una query può includere esplicitamente i discendenti. Da quei Document si ricavano testo e KnowledgeOccurrence, quindi Concept/Entity e, attraverso le Entity, SemanticBlock/FieldValue. Il risultato è derivato: Context non viene assegnato direttamente a questi oggetti.

### Per Tag

Si selezionano Document via DocumentTag e da essi si raggiungono eventualmente KnowledgeObject e dati strutturati tramite KnowledgeOccurrence. Non vengono create assegnazioni dirette o relazioni semantiche implicite.

### Combinata full text × knowledge × structured × Context × Tag

Un Document/Occurrence soddisfa il filtro se:

1. contiene una KnowledgeOccurrence attiva del Concept/Entity richiesto oppure soddisfa il percorso semantico esplicitamente selezionato;
2. il Document appartiene al Context richiesto secondo la modalità exact/subtree scelta;
3. il Document possiede il Tag richiesto.

I filtri strutturati selezionano Entity tramite SemanticBlock/FieldValue; il full text seleziona Document tramite il `plain_text` derivato. Per combinare Entity e Document serve un collegamento dichiarato, normalmente una KnowledgeOccurrence attiva di tipo `entity` nel Document o una futura associazione esplicita, non l'uguaglianza del nome.

Il risultato deve indicare separatamente quale dimensione ha prodotto ogni match.

## 6. Verifica degli scenari richiesti

| Scenario | Supporto del modello |
|---|---|
| Concept senza occurrence | cardinalità 0..n e stato `active`/`orphan` |
| Entity senza occurrence | cardinalità 0..n, senza trasformazione in Concept |
| EntityType configurabile | tabella EntityType e FK obbligatoria da Entity |
| Template raccomandati per EntityType | relazione molti-a-molti ordinata e non restrittiva |
| identificatori di Entity | EntityIdentifier distinti da Alias, FieldValue e SourceIdentifier |
| occurrence Concept ed Entity | KnowledgeOccurrence comune con FK/discriminator verificati |
| più occurrence dello stesso Concept | KnowledgeOccurrence distinte con stesso `knowledge_object_id` |
| più Alias | relazione Concept 1:n ConceptAlias |
| testo occurrence diverso dagli Alias | testo solo nel documento; nessun vincolo di uguaglianza |
| relazioni molti-a-molti | KnowledgeRelation comune tra combinazioni Concept/Entity |
| più blocchi per Entity | SemanticBlock distinti, ciascuno istanza di un Template |
| Entity/SemanticBlock nel documento | nodi di riferimento senza copia dei dati autorevoli |
| field definiti dall'utente | TemplateField ordinati, tipizzati e configurabili |
| query strutturate | payload FieldValue in colonne tipizzate indicizzabili |
| valore collegato a Concept | `linked_concept_id` opzionale e mai creato automaticamente |
| provenance provider | origin/provider/retrieved_at senza overwrite automatico dei manuali |
| Context/Tag su Entity e blocchi | filtri derivati da Document→KnowledgeOccurrence, non ownership diretta |
| Source su dati semantici | associazioni dedicate con FK verso ogni famiglia di subject |
| commenti testuali e strutturati | anchor ProseMirror per range, FK dedicate per object subject |
| Context gerarchici | `parent_id` con controllo anti-ciclo |
| ricerca distinta | tabelle e percorsi query distinti |
| Concept × Context × Tag | join KnowledgeOccurrence → Document → Context/DocumentTag |
| Entity × Context × Tag | stesso percorso con `object_type = entity`, senza assegnazione diretta |
| stessa stringa in ruoli diversi | namespace e ID indipendenti |
| corpo e stili strutturali | paragraph “Normale”, heading, liste e blockquote nel documento |
| indice navigabile | vista derivata dagli heading con DocumentAnchor stabili |
| link tra Document e backlink | DocumentLink con target UUIDv7 e query inverse |
| link a una sezione | FK opzionale verso DocumentAnchor del Document destinazione |
| libro con parti/capitoli/sezioni | un Document `book` con heading semantici e anchor stabili |
| libro composto da più Document | self-FK aciclica, ruolo strutturale e ordine tra figli |
| split di un libro monolitico | comando atomico che preserva ID e aggiorna ownership/link entranti |
| note a piè pagina | DocumentNote `footnote`, posizione/numero derivati dal riferimento inline |
| note finali di capitolo o libro | DocumentNote `endnote` con scope derivato e dichiarato |
| citazione con più fonti | BibliographicCitation con item ordinati verso Source/SourceLocator |
| bibliografia | vista derivata da citazioni attive e fonti incluse esplicitamente |
| autori e identificatori multipli | SourceContributor ordinati e SourceIdentifier normalizzati |
| formule | nodi inline/block nel documento; rendering derivato |
| export documentale | trasformazione derivata da `document_json`, body delle note e record necessari, non nuova fonte di verità |

## 7. Decisioni risolutive adottate

Le criticità individuate nella FASE 0 sono risolte dalle decisioni seguenti e non restano opzioni aperte per l'implementazione iniziale.

### ID dipendenti dal server

Un ID auto-incrementale rende paste, undo e creazione editoriale dipendenti dalla rete. Tutte le entità usano UUIDv7 canonici, client-generabili e globalmente univoci, conservati come `TEXT`.

### Assenza di revisione sul Document

Con soli `updated_at` e documento completo, due salvataggi potrebbero riconciliare occurrence in ordine errato. `revision` è quindi un campo obbligatorio di Document e abilita optimistic concurrency control.

### Significato ambiguo di `orphan`

Un Concept è autorizzato a nascere con zero occurrence, quindi “zero occurrence = orphan” sarebbe contraddittorio. `orphan` è uno stato di lifecycle applicato dall'evento di perdita dell'ultima occurrence; la macchina a stati è definita nella sezione Concept.

### Cut/paste non sempre distinguibile dal copy/paste

La clipboard standard non garantisce una prova affidabile dell'intenzione dell'utente. L'ID viene mantenuto soltanto per cut interno verificato con token effimero one-shot; viene rigenerato in ogni caso ambiguo.

### Un mark può essere frammentato da ProseMirror

La formattazione interna può produrre più text node con gli stessi attributi. L'estrattore raggruppa frammenti contigui nello stesso textblock; il modello iniziale non permette a una occurrence di attraversare più textblock, vieta il riuso dello stesso ID in intervalli disgiunti e vieta overlap.

### Occurrence comune o tabelle separate

ConceptOccurrence ed EntityOccurrence avrebbero foreign key semplici e API esplicite, ma duplicherebbero integralmente lifecycle TipTap/ProseMirror, riconciliazione, clipboard e test, rendendo più facile una divergenza futura. La FASE 3 adotta quindi `KnowledgeOccurrence` e un solo mark `knowledgeOccurrence`, con `knowledgeObjectId`, `objectType` e `occurrenceId`. La coppia ID/discriminator è verificata da una foreign key composta verso KnowledgeObject; il discriminator nel mark è ridondante e un mismatch blocca il salvataggio. Il super-tipo resta chiuso a Concept/Entity, quindi non diventa una generica label.

### Relation comune o tabelle separate

Tre famiglie separate di Relation garantirebbero gli estremi tramite FK ma replicherebbero predicati, provenance e query. `KnowledgeRelation` comune usa invece due coppie ID/discriminator verificate verso KnowledgeObject e copre Concept↔Concept, Entity↔Entity ed Entity↔Concept. Context, Tag, DocumentLink e SourceCitation non entrano in questa tabella.

### Valori tipizzati e modifica dei Template

Un unico JSON per blocco sarebbe semplice da serializzare ma indebolirebbe vincoli e query strutturate. FieldValue usa colonne tipizzate e righe ordinate per i tipi multi; foreign key composte garantiscono che blocco, TemplateField e `field_type` concordino. Rinominare un field conserva l'ID; cambiarne il tipo quando esistono valori viene bloccato e richiederà una migrazione applicativa esplicita e atomica.

### Identificatori, Alias e proprietà

Alias, EntityIdentifier e FieldValue rispondono a domande differenti. ConceptAlias è una denominazione alternativa; EntityIdentifier individua una Entity secondo scheme e authority; FieldValue descrive una proprietà in un Template. Tenerli separati consente normalizzazione e deduplicazione prudenti senza trasformare ticker, DOI, ISBN o exchange in sinonimi testuali.

### Associazioni trasversali verificabili

Un generico `target_type`/`target_id` semplificherebbe provenance, commenti e Asset, ma SQLite non potrebbe verificare l'esistenza e il sottotipo del target. Si usano quindi KnowledgeObject quando Concept/Entity condividono davvero il contratto e join table dedicate per Document, KnowledgeOccurrence, SemanticBlock, FieldValue e KnowledgeRelation. La ripetizione controllata dello schema è preferita a riferimenti non verificabili.

### Identità del KnowledgeObject duplicata tra documento e DB

La ridondanza può divergere. `KnowledgeOccurrence.knowledge_object_id` e `object_type` sono canonici; il mark è un'asserzione validata. I conflitti bloccano il salvataggio con errore di integrità e non vengono sanati silenziosamente.

### Titoli o slug come destinazioni ipertestuali

Titoli e slug sono leggibili ma mutabili, non un'identità sicura. I link interni usano `target_document_id`; un eventuale slug resta una rappresentazione derivata per UI o export. Gli anchor interni usano UUIDv7 e non testo dell'heading o offset assoluti. Questo preserva i collegamenti durante rinomina e normale editing.

### Libro monolitico o gerarchia di Document

Il modello supporta entrambi senza entità Book separata. Un'opera piccola usa un solo Document con heading semantici; un'opera grande usa un Document `book` come radice e Document figli ordinati. Questo preserva un'identità unica per il contenitore e revisioni isolate per capitolo. La gerarchia non implica ereditarietà silenziosa di Context o Tag.

La conversione non viene simulata con copy/paste: un comando di split/move valida un confine di blocco e trasferisce atomicamente ownership e riferimenti. In caso di errore nessun documento o record semantico cambia. L'ordine dei figli è dato esplicito del parent e non viene inferito da titolo o data di creazione.

### Testo generato di citazioni e bibliografia

Salvare la stringa formattata renderebbe Source e stile citazionale concorrenti con il testo. Citazioni, numeri di nota e bibliografia sono quindi viste derivate: si persistono identità, item, locator, opzioni e stile, mentre punteggiatura e label vengono rigenerate. Le degradazioni di export devono essere dichiarate.

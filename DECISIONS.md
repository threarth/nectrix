# Registro delle decisioni architetturali

Questo documento è il registro unico delle decisioni trasversali di Nectrix. Le decisioni `adopted` sono vincolanti; quelle `scheduled` devono essere chiuse prima di iniziare la fase indicata. Una voce risolta non resta nell'elenco delle decisioni aperte.

## Stati

- `adopted`: decisione presa e normativa;
- `scheduled`: decisione intenzionalmente rinviata alla fase che possiede i dati o la tecnologia necessari;
- `superseded`: decisione sostituita, conservata soltanto se serve tracciabilità storica.

## Decisioni adottate

### ADR-001 — Lifecycle dei KnowledgeObject

**Stato:** `adopted`

La perdita dell'ultima KnowledgeOccurrence non elimina mai il KnowledgeObject. Un Concept `active` che perde l'ultima occurrence diventa `orphan`, salvo che sia già `archived`; una Entity resta `active`. Archiviazione, ripristino e cancellazione sono sempre comandi espliciti. Non esiste cascade dal KnowledgeObject verso Document, testo, occurrence, SemanticBlock o FieldValue.

### ADR-002 — Retention dei record detached

**Stato:** `adopted`

Il normale salvataggio non elimina fisicamente record `detached`. Nella prima versione vengono conservati senza scadenza automatica per undo, recupero e diagnostica. Un futuro comando di purge è manutenzione esplicita: deve mostrare l'impatto, verificare riferimenti ed evidence, creare o richiedere un backup e operare transazionalmente. Le soglie temporali specifiche di SourceAnchor e Asset restano decisioni delle rispettive fasi.

### ADR-003 — Normalizzazione degli EntityIdentifier

**Stato:** `adopted`

`scheme` usa una chiave lowercase stabile conforme a `[a-z][a-z0-9._-]{0,63}`. Stringa vuota e sola spaziatura sono invalide. `authority_or_namespace` è `NULL`, mai stringa vuota, quando lo scheme la dichiara opzionale; è obbligatoria per scheme come `ticker`. `normalized_value` è prodotto da una policy versionata per scheme, non da una trasformazione universale. Il valore originale resta conservato. La FASE 7 introduce almeno policy esplicite per `ticker`, `cik`, `lei` e `internal_clinical_id`; uno scheme sconosciuto deve dichiarare case-sensitivity e normalizzazione prima dell'uso.

### ADR-004 — Template globali e raccomandazioni per EntityType

**Stato:** `adopted`

I Template restano globali e applicabili a qualsiasi Entity. La FASE 10.1 può introdurre una relazione molti-a-molti ordinata di raccomandazione `EntityType ↔ Template` per guidare la UI, senza trasformarla in un vincolo di compatibilità. Applicare un Template non raccomandato richiede una scelta esplicita ma resta valido. Eventuali restrizioni rigide richiederebbero una nuova decisione e una strategia di migrazione dei blocchi esistenti.

### ADR-005 — Context e Tag restano Document-owned

**Stato:** `superseded` da ADR-016 per la parte Context; resta vincolante per i Tag.

Nella roadmap corrente Context e Tag sono assegnati esclusivamente ai Document. Concept, Entity, EntityType, SemanticBlock e FieldValue li raggiungono tramite query derivate da Document e KnowledgeOccurrence. Non verranno introdotte associazioni dirette Context/Tag→KnowledgeObject o dati strutturati senza un nuovo caso d'uso che definisca ownership, cardinalità, conflitti e lifecycle.

### ADR-006 — Riferimenti editoriali a Entity e SemanticBlock

**Stato:** `adopted`

La FASE 10.1.1 introduce riferimenti editoriali espliciti dopo il Template System. `entityReference` e `semanticBlockReference` sono nodi di rendering/riferimento: conservano un proprio `referenceId` e l'ID stabile della destinazione, ma non copiano payload Entity, Template o FieldValue nel `document_json`. Il server valida la destinazione; copy/paste genera un nuovo `referenceId` mantenendo la destinazione, mentre cut/paste interno verificato può conservarlo. Delete e undo seguono il lifecycle prudente delle altre manifestazioni editoriali. I record Entity, SemanticBlock e FieldValue restano le sole fonti autorevoli dei dati.

### ADR-007 — Lifecycle e cancellazione dei Document

**Stato:** `adopted`

La FASE 6.1 introduce `active`, `archived` e `trashed`, con restore e trash non distruttivi. `archived` è in sola lettura nei flussi ordinari, escluso dalle liste predefinite ma includibile esplicitamente in ricerca; `trashed` compare soltanto nella vista di recupero. Nessuno dei due stati cambia lo stato persistente delle KnowledgeOccurrence. Il purge fisico non è un normale CRUD: è un comando di manutenzione separato, con preview, backup, verifica di figli, riferimenti entranti ed evidence. Solo dopo tali verifiche elimina atomicamente il Document e le manifestazioni possedute dal Document, incluse le KnowledgeOccurrence, senza eliminare i KnowledgeObject o i dati Entity-owned. Non esiste `DELETE` distruttivo implicito dalla FASE 1.

### ADR-008 — Associazioni trasversali verificabili

**Stato:** `adopted`

Ogni famiglia usa join table dedicate con due foreign key reali. Il pattern nominale è `<subject>_sources`, `<subject>_assets`, `<subject>_comments` o `<derived>_<evidence>_evidence`, con subject espliciti quali Document, KnowledgeObject, KnowledgeOccurrence, SemanticBlock, FieldValue e KnowledgeRelation. Non esiste una coppia generica `target_type`/`target_id`. Colonne aggiuntive, cardinalità e indici vengono definiti nella fase proprietaria, senza cambiare questo principio.

### ADR-009 — Indicizzazione del testo delle occurrence

**Stato:** `adopted`

La FASE 10 non introduce inizialmente una copia testuale per KnowledgeOccurrence. FTS seleziona i Document e l'applicazione estrae i range dal `document_json` della revisione corrente. Una cache per-occurrence può essere aggiunta solo dopo evidenza di un problema prestazionale; deve essere interamente ricostruibile e vincolata a `document_id`, `occurrence_id` e `document_revision`.

### ADR-010 — Test end-to-end in browser reale

**Stato:** `adopted`

I test end-to-end reali diventano obbligatori dalla FASE 3 e nei gate delle FASI 3–6 per creazione, editing, clipboard, salvataggio, reload e inspector delle KnowledgeOccurrence. La FASE 2 resta coperta da test TipTap/ProseMirror vicini alle trasformazioni e dal round trip API; un test browser viene aggiunto anche lì soltanto se emerge un comportamento non rappresentabile affidabilmente in jsdom. La dipendenza E2E deve essere gratuita e verificata per licenza al momento dell'introduzione.

### ADR-011 — Confine delle API semantiche

**Stato:** `adopted`

Concept, Entity, EntityType, Template e altri aggregate hanno endpoint tipizzati e non un endpoint polimorfico universale. La ricerca può esporre un risultato unificato categorizzato. Creazione e riconciliazione delle KnowledgeOccurrence avvengono nel comando transazionale di salvataggio del Document; non esiste CRUD indipendente di occurrence che possa divergere dal mark. Comandi multi-entità espliciti usano endpoint applicativi dedicati e idempotenti.

### ADR-012 — Introduzione incrementale dei CRUD

**Stato:** `adopted`

La FASE 3 introduce solo create/search/read minimi di Concept, Entity ed EntityType necessari a creare o associare occurrence. La FASE 6 aggiunge modifica e inspector dei dati già disponibili; la FASE 7 possiede Alias ed EntityIdentifier; la FASE 10.1 possiede Template, TemplateField, SemanticBlock e FieldValue. Nessuna fase anticipa placeholder o CRUD di aggregate appartenenti a una fase successiva.

### ADR-013 — Evoluzione di TemplateField

**Stato:** `adopted`

Rinominare un TemplateField conserva l'identità. Il CRUD ordinario blocca cambi di tipo o cardinalità quando esistono FieldValue. Una trasformazione dei dati, se necessaria, è un comando distinto con preview degli incompatibili, mapping esplicito, transazione atomica e rollback completo; non è una conversione silenziosa durante l'update del field.

### ADR-014 — Archiviazione di EntityType e KnowledgeObject

**Stato:** `adopted`

Concept, Entity ed EntityType referenziati non vengono eliminati fisicamente dal CRUD ordinario. Concept ed Entity possono essere archiviati e ripristinati esplicitamente; un EntityType usato può essere archiviato per impedirne nuove assegnazioni, ma resta valido per le Entity esistenti. Sostituzione o merge sono comandi separati, confermati e transazionali.

### ADR-015 — Renderer della Knowledge Map

**Stato:** `adopted` (chiude ADR-P15-01)

La Knowledge Map della FASE 15 usa **Cytoscape.js** (MIT, 3.34.x, nessuna dipendenza runtime), non un renderer scritto in casa. La libreria porta layout, selezione, stile ed eventi; il codice di Nectrix resta quello che traduce KnowledgeObject e KnowledgeRelation in nodi e archi. Sigma.js — pari licenza e manutenzione — è stata scartata perché il suo vantaggio è il WebGL su decine di migliaia di nodi, una scala che Nectrix non prevede, al prezzo di graphology, di un pacchetto di layout separato e di più codice di integrazione.

Il vincolo di dominio resta quello della roadmap: nodi principali soltanto KnowledgeObject, archi soltanto KnowledgeRelation dichiarate; SemanticBlock, TemplateField, FieldValue, Context, Tag e KnowledgeOccurrence non diventano nodi, e Context e Tag restano filtri, grouping e coloring. Un cambio di libreria richiederebbe una nuova decisione.

### ADR-016 — Il Context organizza frammenti, non Document

**Stato:** `adopted` (sostituisce ADR-005 per il Context)

Il Context si applica a un range di testo, come Concept ed Entity: i tre sono gli organizzatori del caos e vivono tutti sul frammento. Il Document non possiede un Context e non ne è consapevole, perché è un contenitore disordinato — appunti sparsi, la stessa cosa riscritta più volte — e un contenitore consapevole trasmetterebbe il proprio disordine all'indice.

Un range di Context può attraversare più paragrafi, restando contiguo. L'appartenenza di Concept ed Entity a un Context è derivata dal contenimento totale del frammento e materializzata in una tabella ricostruibile: la co-presenza nello stesso Document non dichiara nulla. I Tag restano Document-owned come stabilisce ADR-005: classificano il contenitore, non il frammento.

## Decisioni programmate

| ID | Decisione da chiudere | Vincoli già stabiliti | Scadenza |
|---|---|---|---|
| ADR-P16-01 | retention/purge di SourceAnchor non referenziati | nessuna rimozione nel normale salvataggio; backup e preview | prima della FASE 16 |
| ADR-P17-01 | contratto upload, limiti byte/pixel e retention Asset | verifica contenuto reale, storage non pubblico, niente base64 | prima della FASE 17 |
| ADR-P18-01 | formato e remapping degli anchor testuali dei Comment | niente offset assoluti autorevoli; test matrice editoriale | prima della FASE 18 |
| ADR-P20-01 | sintassi, parser e renderer delle formule | sorgente autorevole nel documento; libreria gratuita | prima della FASE 20 |
| ADR-P21-01 | soglie e UX per suggerire lo split di Document lunghi | split mai automatico e sempre transazionale | prima della FASE 21 |
| ADR-P21-02 | numerazione e raggruppamento delle endnote composte | collocazione derivata, nessuna label autorevole persistita | prima della FASE 21 |
| ADR-P22-01 | formati import/export e motore di stile bibliografico | offline, gratuito, Source autorevole | prima della FASE 22 |
| ADR-P22.1-01 | registry, adapter, mapping e gestione credenziali Provider | core offline; segreti non in chiaro; proposte confermabili | prima della FASE 22.1 |
| ADR-P23-01 | trasformatore intermedio e librerie dei quattro exporter | nessun servizio a pagamento; degradazioni diagnosticate | prima della FASE 23 |
| ADR-P23-02 | profili content-only/metadata e packaging multi-Document | nessuna perdita silenziosa; notice delle licenze inclusi | prima della FASE 23 |
| ADR-P25-01 | provider, policy dati e contratti dell'integrazione AI | AI opzionale; propone soltanto; provenance obbligatoria | prima della FASE 25 |

## Regola di manutenzione

Quando una decisione programmata viene presa, la voce viene spostata tra le decisioni adottate con motivazione e conseguenze. Non deve restare duplicata in `ARCHITECTURE.md`, `BACKLOG.md` o `ROADMAP.md` come generico elemento “da valutare”.

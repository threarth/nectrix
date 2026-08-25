# Invarianti

Questo documento è normativo. Una fase non è completata se una delle invarianti applicabili è violata o priva dei test richiesti.

## A. Documento e persistenza

**INV-DOC-01 — Documento autorevole**  
`Note.document_json` è la fonte autorevole di contenuto, formattazione e testo corrente delle occurrence.

**INV-DOC-02 — Plain text derivato**  
`Note.plain_text` è ottenuto deterministicamente dal documento e non viene modificato indipendentemente.

**INV-DOC-03 — Round trip**  
Salvare e ricaricare senza modifiche conserva semanticamente lo stesso documento TipTap, inclusi mark e attributi supportati.

**INV-DOC-04 — Revisione monotona**  
Ogni salvataggio accettato incrementa una revisione della Note; un salvataggio basato su una revisione obsoleta non può sovrascrivere quello più recente.

**INV-DOC-05 — Atomicità**  
Documento, plain text, occurrence, stati Concept e indice di ricerca interessati da un salvataggio cambiano nella stessa transazione o non cambiano affatto.

**INV-DOC-06 — Identificatori uniformi**  
Tutte le entità usano UUIDv7 testuali canonici; l'API rifiuta formati non validi e collisioni.

## B. Concept e Alias

**INV-CON-01 — Globalità**  
Un Concept non appartiene a una Note o a un Context.

**INV-CON-02 — Esistenza autonoma**  
Un Concept può esistere con zero occurrence.

**INV-CON-03 — Persistenza dopo l'ultima occurrence**  
La perdita dell'ultima occurrence non elimina il Concept; lo porta a `orphan`, salvo che sia `archived`.

Un Concept creato autonomamente resta invece `active` anche con zero occurrence: `orphan` è una transizione di lifecycle, non il risultato automatico di `count = 0`.

**INV-CON-04 — Nome canonico**  
Ogni Concept ha un canonical name non vuoto.

**INV-ALS-01 — Alias non occurrence**  
Creare, modificare o eliminare un Alias non crea, modifica o elimina occurrence.

**INV-ALS-02 — Testo libero dell'occurrence**  
Il testo di un'occurrence non deve coincidere con canonical name o Alias.

**INV-ALS-03 — Nessuna promozione automatica**  
Il testo di un'occurrence non diventa Alias senza un comando esplicito dell'utente.

## C. Identità delle occurrence

**INV-OCC-01 — Identità globale**  
Ogni `occurrenceId` identifica al massimo una Occurrence persistente in tutto il sistema.

**INV-OCC-02 — Associazione stabile**  
Un'Occurrence appartiene a un solo Concept e a una sola Note. Riassegnare semanticamente un range crea una nuova Occurrence.

**INV-OCC-03 — Doppia rappresentazione coerente**  
Per un'Occurrence `active`, record DB e mark concordano su `occurrenceId`, `conceptId` e `noteId` implicito.

**INV-OCC-04 — Nessun testo autorevole nel DB**  
Il record Occurrence non conserva una copia autorevole del testo o offset assoluti come identità.

**INV-OCC-05 — Un solo intervallo logico**  
Lo stesso ID può coprire text node contigui nello stesso textblock, ma non due intervalli disgiunti o più textblock. Occurrence diverse non si sovrappongono né si annidano.

**INV-OCC-06 — Editing interno**  
Modificare il testo all'interno di un'occurrence conserva `occurrenceId` e `conceptId`.

**INV-OCC-07 — Cancellazione parziale**  
Se resta almeno un contenuto marcato nel range, l'Occurrence conserva identità e stato attivo al successivo salvataggio.

**INV-OCC-08 — Cancellazione totale non distruttiva**  
Se il range scompare, il mark scompare e al salvataggio il record diventa `detached`; il Concept non viene cancellato.

**INV-OCC-09 — Undo/redo**  
Undo ripristina gli stessi ID rimossi dall'operazione annullata; redo riproduce lo stesso cambiamento. La riconciliazione è idempotente.

**INV-OCC-10 — Copy/paste**  
Ogni Occurrence copiata e incollata riceve un nuovo `occurrenceId` e mantiene il `conceptId`, purché il Concept sia valido.

**INV-OCC-11 — Cut/paste prudente**  
L'ID si conserva soltanto per un movimento verificato all'interno della stessa Note e se non genera duplicati; altrimenti viene creato un nuovo ID.

**INV-OCC-12 — Serializzazione stabile**  
Serializzazione e parsing non rigenerano ID. La rigenerazione avviene solo in operazioni che creano una nuova manifestazione, come il paste da copia.

**INV-OCC-13 — Reload stabile**  
Dopo salvataggio e reload ogni occurrence attiva conserva gli stessi ID e associazioni.

**INV-OCC-14 — Nuova occurrence distinta**  
Due range dello stesso Concept hanno ID differenti, anche se il testo è identico.

**INV-OCC-15 — Nessuna creazione implicita da input non fidato**  
Un mark sconosciuto proveniente da clipboard o documento manipolato non può creare implicitamente Concept o record persistenti.

**INV-OCC-16 — Mismatch bloccante**  
Se `conceptId` nel mark non coincide con `Occurrence.concept_id`, il salvataggio fallisce atomicamente; nessuna delle due rappresentazioni viene riscritta in automatico.

**INV-OCC-17 — Deleted esplicito e terminale**  
La normale cancellazione editoriale produce `detached`, mai `deleted`. Nella versione iniziale `deleted` è raggiungibile solo con comando esplicito e non torna `active`.

## D. Concept, Context e Tag

**INV-CCT-01 — Tipi distinti**  
Concept, Context e Tag sono entità e tabelle distinte; non sono varianti di una generica label.

**INV-CCT-02 — Namespace indipendenti**  
La stessa stringa può nominare simultaneamente un Concept, un Context e un Tag senza collegamento implicito.

**INV-CCT-03 — Responsabilità del Concept**  
Il Concept risponde a “di che cosa sto parlando?” e può avere occurrence, Alias e Relation.

**INV-CCT-04 — Responsabilità del Context**  
Il Context risponde a “in quale ambito?”; è gerarchico e non modifica l'identità dei Concept osservati.

**INV-CCT-05 — Responsabilità del Tag**  
Il Tag è metadata organizzativo libero; non ha occurrence, Alias o archi automatici nella Knowledge Map.

**INV-CCT-06 — Un Context principale**  
Nella prima versione ogni Note ha al massimo un Context principale. Il multi-context non viene simulato tramite Tag.

**INV-CCT-07 — Gerarchia valida**  
La gerarchia Context è aciclica e ogni nodo ha al massimo un parent.

**INV-CCT-08 — Query separate e combinabili**  
Concept, Context e Tag possono essere interrogati separatamente e congiunti tramite filtri `Concept × Context × Tag`, senza ridurli a string matching.

**INV-CCT-09 — Spostamenti non semantici**  
Cambiare Context a una Note o spostare un Context nella gerarchia non crea, fonde o rinomina Concept.

## E. Relation, Comment e Source

**INV-REL-01 — Estremi Concept**  
Una Relation collega due Concept esistenti; non collega automaticamente Context o Tag.

**INV-REL-02 — Molteplicità**  
Un Concept può avere molte relazioni in entrata e in uscita e più predicati verso lo stesso Concept.

**INV-COM-01 — Anchor non ridotto a offset assoluti**  
Un Comment ancorato a un range non usa una coppia di posizioni assolute come unica identità dell'anchor.

**INV-SRC-01 — Provenance distinta**  
Source e SourceLocator sono distinti: la fonte identifica l'opera/risorsa, il locator una sua porzione.

## F. Matrice minima di test

Quando le relative fasi saranno implementate, devono esistere almeno questi test regressivi:

| Caso | Livello minimo | Invarianti |
|---|---|---|
| round trip documento formattato | frontend + API | DOC-01..05 |
| modifica interna occurrence | editor | OCC-03, 06, 12 |
| cancellazione parziale | editor + sync | OCC-07 |
| cancellazione totale e save | integrazione | OCC-08, CON-03 |
| undo/redo prima e dopo save | editor + integrazione | OCC-09, 13 |
| copy/paste singola e multipla | editor | OCC-05, 10, 14 |
| cut/paste verificato e ambiguo | editor | OCC-11 |
| reload con più occurrence stesso Concept | end-to-end | OCC-13, 14 |
| documento con ID duplicato | API | OCC-01, 05, DOC-05 |
| mismatch `conceptId` | API | OCC-02, 03, 16 |
| UUID malformato o collisione | dominio/API | DOC-06, OCC-01 |
| Concept senza occurrence | dominio/API | CON-02 |
| Alias diverso dal testo | dominio/API | ALS-01..03 |
| omonimo Concept/Context/Tag | API/search | CCT-01, 02 |
| filtro Concept × Context × Tag | integrazione | CCT-08 |
| ciclo Context | dominio/API | CCT-07 |

Ogni bug editoriale corretto deve produrre un nuovo test regressivo nella suite più vicina alla causa e, se necessario, un test end-to-end del comportamento osservabile.

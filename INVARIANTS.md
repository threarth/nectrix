# Invarianti

Questo documento è normativo. Una fase non è completata se una delle invarianti applicabili è violata o priva dei test richiesti.

## A. Documento e persistenza

**INV-DOC-01 — Documento autorevole**  
`Document.document_json` è la fonte autorevole del contenuto principale, della formattazione e del testo corrente delle occurrence; `DocumentNote.body_json` è autorevole per il corpo delle note editoriali.

**INV-DOC-02 — Plain text derivato**  
`Document.plain_text` è ottenuto deterministicamente dal contenuto principale e dai body delle DocumentNote nell'ordine dei riferimenti; non viene modificato indipendentemente.

**INV-DOC-03 — Round trip**  
Salvare e ricaricare senza modifiche conserva semanticamente lo stesso documento TipTap, inclusi mark e attributi supportati.

**INV-DOC-04 — Revisione monotona**  
Ogni salvataggio accettato incrementa una revisione del Document; un salvataggio basato su una revisione obsoleta non può sovrascrivere quello più recente.

**INV-DOC-05 — Atomicità**  
Documento, plain text, KnowledgeOccurrence, link, anchor, note editoriali, citazioni, stati KnowledgeObject e indice di ricerca interessati da un salvataggio cambiano nella stessa transazione o non cambiano affatto.

**INV-DOC-06 — Identificatori uniformi**  
Tutte le entità usano UUIDv7 testuali canonici; l'API rifiuta formati non validi e collisioni.

**INV-DOC-07 — Gerarchia editoriale valida**
Ogni Document ha al massimo un parent, non può essere parent di sé stesso e la gerarchia è aciclica. Un figlio compare in una sola posizione e gli ordini tra fratelli non collidono.

**INV-DOC-08 — Gerarchia distinta dal Context**
Parent, ruolo strutturale e ordine editoriale non implicano Context o Tag ereditati e non modificano l'identità dei KnowledgeObject.

**INV-DOC-09 — Split/move strutturale atomico**
Uno split/move tra Document opera su blocchi completi e trasferisce atomicamente ownership di KnowledgeOccurrence di entrambi i tipi, anchor, link sorgenti, note, citazioni e commenti testuali, aggiornando i link entranti agli anchor spostati. SemanticBlock e FieldValue restano Entity-owned e non vengono duplicati. O preserva tutti gli ID e la coerenza, oppure non modifica nulla.

**INV-DOC-10 — Clipboard non migrazione**
Il normale cut/paste tra Document non è uno split strutturale verificato e non può usare l'eccezione di trasferimento ownership.

**INV-DOC-11 — Cancellazione del contenitore prudente**
Un Document con figli non viene eliminato a cascata; i figli devono essere spostati o rimossi con comandi espliciti.

**INV-DOC-12 — Timestamp canonici**
I timestamp di dominio sono RFC 3339 UTC con millisecondi nella forma canonica `YYYY-MM-DDTHH:mm:ss.SSSZ`. Un'operazione rifiutata non li modifica.

**INV-DOC-13 — Lifecycle non distruttivo**
Archive e trash non eliminano contenuto, KnowledgeObject o dati collegati e non cambiano lo stato persistente delle KnowledgeOccurrence. Un Document `archived` è in sola lettura e ricercabile soltanto con scope esplicito; un Document `trashed` è ripristinabile e visibile soltanto nella vista di recupero. Il purge fisico è un comando di manutenzione distinto con preview, backup e transazione atomica.

**INV-DOC-14 — Riferimenti prima del purge**
Un Document con figli, link entranti, evidence o altre associazioni attive non viene eliminato fisicamente finché ogni riferimento non è stato gestito esplicitamente. Il purge rimuove le manifestazioni Document-owned soltanto dopo le verifiche e non applica cascade ai KnowledgeObject o ai dati Entity-owned.

## A.1 Highlight normale

**INV-HLT-01 — Sola formattazione**
`highlight` è un mark visuale nel `document_json`; il solo attributo ammesso è `color` in formato `#RRGGBB` (assenza e quattro valori storici restano compatibili). La palette UI locale può contenere da 4 a 10 colori e non crea, modifica o riferisce KnowledgeObject, Concept, Entity, KnowledgeOccurrence, SemanticBlock o FieldValue.

**INV-HLT-02 — Lifecycle editoriale**
Input interno conserva/estende Highlight; input esattamente ai bordi resta non evidenziato. Delete parziale conserva Highlight sul testo residuo, delete totale lo rimuove. Undo/redo, copy/paste, cut/paste, serializzazione e reload preservano il comportamento del mark senza identità aggiuntive.

**INV-HLT-03 — Persistenza neutra**
Save/reload conserva semanticamente Highlight e il suo colore nel JSON e nel rendering. `plain_text` non cambia per effetto del mark e il salvataggio non scrive tabelle semantiche.

## B. Concept e Alias

**INV-CON-01 — Globalità**  
Un Concept non appartiene a un Document o a un Context.

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

## C. Identità delle KnowledgeOccurrence

**INV-OCC-01 — Identità globale**  
Ogni `occurrenceId` identifica al massimo una Occurrence persistente in tutto il sistema.

**INV-OCC-02 — Associazione stabile**  
Una KnowledgeOccurrence appartiene a un solo KnowledgeObject (Concept o Entity) e a un solo Document. Riassegnare semanticamente un range, incluso cambiarne il sottotipo, crea una nuova KnowledgeOccurrence; soltanto uno split/move strutturale atomico può cambiare `document_id` preservando l'identità.

**INV-OCC-03 — Doppia rappresentazione coerente**  
Per una KnowledgeOccurrence `active`, record DB e mark concordano su `occurrenceId`, `knowledgeObjectId`, `objectType` e `documentId` implicito.

**INV-OCC-04 — Nessun testo autorevole nel DB**  
Il record Occurrence non conserva una copia autorevole del testo o offset assoluti come identità.

**INV-OCC-05 — Un solo intervallo logico**  
Lo stesso ID può coprire text node contigui nello stesso textblock, ma non due intervalli disgiunti o più textblock. Occurrence diverse non si sovrappongono né si annidano.

**INV-OCC-06 — Editing interno**  
Modificare il testo all'interno di un'occurrence conserva `occurrenceId`, `knowledgeObjectId` e `objectType`.

**INV-OCC-07 — Cancellazione parziale**  
Se resta almeno un contenuto marcato nel range, l'Occurrence conserva identità e stato attivo al successivo salvataggio.

**INV-OCC-08 — Cancellazione totale non distruttiva**  
Se il range scompare, il mark scompare e al salvataggio il record diventa `detached`; il KnowledgeObject non viene cancellato.

**INV-OCC-09 — Undo/redo**  
Undo ripristina gli stessi ID rimossi dall'operazione annullata; redo riproduce lo stesso cambiamento. La riconciliazione è idempotente.

**INV-OCC-10 — Copy/paste**  
Ogni KnowledgeOccurrence copiata e incollata riceve un nuovo `occurrenceId` e mantiene `knowledgeObjectId`/`objectType`, purché il KnowledgeObject sia valido.

**INV-OCC-11 — Cut/paste prudente**  
L'ID si conserva soltanto per un movimento verificato all'interno dello stesso Document e se non genera duplicati; altrimenti viene creato un nuovo ID.

**INV-OCC-12 — Serializzazione stabile**  
Serializzazione e parsing non rigenerano ID. La rigenerazione avviene solo in operazioni che creano una nuova manifestazione, come il paste da copia.

**INV-OCC-13 — Reload stabile**  
Dopo salvataggio e reload ogni occurrence attiva conserva gli stessi ID e associazioni.

**INV-OCC-14 — Nuova occurrence distinta**  
Due range dello stesso KnowledgeObject hanno ID differenti, anche se il testo è identico.

**INV-OCC-15 — Nessuna creazione implicita da input non fidato**  
Un mark sconosciuto proveniente da clipboard o documento manipolato non può creare implicitamente Concept, Entity o record persistenti.

**INV-OCC-16 — Mismatch bloccante**  
Se `knowledgeObjectId` o `objectType` nel mark non coincide con la KnowledgeOccurrence, il salvataggio fallisce atomicamente; nessuna delle rappresentazioni viene riscritta in automatico.

**INV-OCC-17 — Deleted esplicito e terminale**  
La normale cancellazione editoriale produce `detached`, mai `deleted`. Nella versione iniziale `deleted` è raggiungibile solo con comando esplicito e non torna `active`.

**INV-OCC-18 — Retention recuperabile**
Il normale salvataggio non elimina fisicamente le KnowledgeOccurrence `detached`. Un purge futuro è esplicito, preceduto da backup e verifica delle evidence e non può rendere incoerente un Document recuperabile.

**INV-OCC-19 — Testo indicizzato derivato**
La ricerca non tratta una copia del testo dell'occurrence come fonte autorevole. Un'eventuale cache è ricostruibile e identifica la revisione del Document dalla quale è stata estratta.

## D. KnowledgeObject, Entity e dati strutturati

**INV-KNO-01 — Gerarchia chiusa**
Ogni KnowledgeObject è esattamente un Concept o una Entity. Context e Tag non sono sottotipi e non condividono l'identità del KnowledgeObject.

**INV-KNO-02 — Discriminator coerente**
Il discriminator ripetuto in sottotipi, occurrence e relazioni coincide sempre con il record KnowledgeObject; un mismatch viene rifiutato, mai corretto silenziosamente.

**INV-ENT-01 — Specificità e tipo configurabile**
Ogni Entity rappresenta una cosa specifica e ha esattamente un EntityType persistito e configurabile, non hardcoded.

**INV-ENT-02 — Autonomia**
Una Entity può esistere senza occurrence o SemanticBlock e non viene trasformata automaticamente in Concept o Source.

**INV-ENT-03 — Persistenza senza occurrence**
La perdita dell'ultima KnowledgeOccurrence non elimina, archivia o rinomina la Entity. La Entity resta `active` fino a un comando esplicito dell'utente.

**INV-ENT-04 — Tipi referenziati non distruttivi**
Un EntityType usato non viene eliminato. Può essere archiviato senza invalidare le Entity esistenti; sostituzione e merge richiedono conferma e transazione atomica.

**INV-EID-01 — Identifier distinto**
Un EntityIdentifier non è un ConceptAlias, un SourceIdentifier o un FieldValue. Scheme, valore normalizzato e authority/namespace restano campi strutturati.

**INV-EID-02 — Unicità nello scope**
La stessa combinazione normalizzata scheme/authority/value non compare due volte nella medesima Entity. La stessa combinazione tra Entity differenti produce un candidato duplicato, mai un merge automatico.

**INV-EID-03 — Creazione esplicita**
Nome, testo di una occurrence e FieldValue non diventano EntityIdentifier senza un comando esplicito dell'utente o una proposta confermata.

**INV-EID-04 — Authority significativa**
Quando uno scheme richiede un namespace, come un ticker, l'authority partecipa all'identità e alla deduplicazione; non viene ricavata silenziosamente da testo o Context.

**INV-EID-05 — Normalizzazione dichiarata**
`scheme` usa una chiave lowercase stabile; authority assente è `NULL`, non stringa vuota. Ogni scheme dichiara una policy versionata per normalizzazione e case-sensitivity prima di accettare valori.

**INV-TPL-01 — Definizione separata dall'istanza**
Template e TemplateField definiscono la struttura; SemanticBlock e FieldValue la istanziano per una Entity. Un SemanticBlock non è un'evidenziazione testuale.

**INV-TPL-02 — Appartenenza e ordine**
Ogni FieldValue usa un TemplateField appartenente al Template del proprio SemanticBlock. Ordini di field, blocchi e valori multi non collidono nel rispettivo scope.

**INV-TPL-03 — Evoluzione prudente**
Rinominare un TemplateField ne conserva l'ID. Tipo o cardinalità non cambiano in presenza di valori senza una migrazione esplicita, validata e atomica.

**INV-TPL-04 — Fuori dal documento autorevole**
SemanticBlock e FieldValue non sono copie incorporate nel `document_json`. I nodi editoriali della FASE 10.1.1 conservano soltanto riferimenti verificabili e il rendering resta derivato dai record Entity-owned.

**INV-TPL-05 — Raccomandazione non restrizione**
Una relazione EntityType→Template guida e ordina le proposte della UI, ma non rende invalido un SemanticBlock basato su un Template globale non raccomandato.

**INV-TPL-06 — Riferimenti editoriali senza copia**
Ogni `entityReference` o `semanticBlockReference` ha un `referenceId` distinto e una destinazione esistente. Copy/paste genera un nuovo reference ID mantenendo la destinazione; il nodo non contiene copie autorevoli di Entity o FieldValue.

**INV-FLD-01 — Valore tipizzato**
Ogni FieldValue usa esattamente il payload previsto dal `field_type`; numero, boolean, data, misura, valuta e reference non vengono ridotti a testo opaco. Le percentuali usano il rapporto decimale canonico (`0.125` = `12,5%`).

**INV-FLD-02 — Cardinalità**
I tipi singoli hanno un solo valore con ordinal `0`; i tipi multi usano righe distinte con ordinal univoco e non liste testuali serializzate.

**INV-FLD-03 — Collegamento Concept opzionale**
Un FieldValue può collegarsi esplicitamente a un Concept esistente. Field, valore e testo non creano né aggiornano automaticamente Concept o Alias.

**INV-FLD-04 — Reference verificabili**
Entity e Concept reference puntano al sottotipo corretto tramite foreign key. `source_reference` non accetta valori finché la fase Sources non introduce una FK verificabile.

**INV-FLD-05 — Provenance e precedenza manuale**
`origin`, provider e timestamp di recupero restano interrogabili. Un provider, una derivazione o un suggerimento AI non sovrascrive in place un valore manuale senza conferma esplicita.

**INV-PRV-01 — Provider opzionale**
Il core resta pienamente funzionante offline e senza Provider configurati. Un errore o indisponibilità di rete non rende il dato manuale illeggibile o non modificabile.

**INV-PRV-02 — Proposta tracciata**
Ogni valore proposto da Provider conserva provider, origin, timestamp di recupero e Source/SourceLocator quando disponibile; il mapping esterno non modifica l'identità del TemplateField.

## E. Concept, Entity, Context e Tag

**INV-CCT-01 — Tipi distinti**  
Concept, Entity, Context e Tag sono entità e tabelle distinte; solo Concept ed Entity condividono la base chiusa KnowledgeObject e non sono varianti di una generica label.

**INV-CCT-02 — Namespace indipendenti**  
La stessa stringa può nominare simultaneamente un Concept, una Entity, un Context e un Tag senza collegamento implicito.

**INV-CCT-03 — Responsabilità del Concept**  
Il Concept risponde a “di che cosa sto parlando?” e può avere occurrence, Alias e Relation.

**INV-CCT-04 — Responsabilità del Context**  
Il Context risponde a “in quale ambito?”; è gerarchico e non modifica l'identità dei Concept osservati.

**INV-CCT-05 — Responsabilità del Tag**  
Il Tag è metadata organizzativo libero; non ha occurrence, Alias o archi automatici nella Knowledge Map.

**INV-CCT-06 — Responsabilità della Entity**
La Entity risponde a “quale cosa specifica?”; può avere occurrence, SemanticBlock e Relation ma non assorbe il ruolo astratto del Concept, l'ambito del Context o la classificazione del Tag.

**INV-CCT-07 — Un Context principale**
Nella prima versione ogni Document ha al massimo un Context principale. Il multi-context non viene simulato tramite Tag.

**INV-CCT-08 — Gerarchia valida**
La gerarchia Context è aciclica, ogni nodo ha al massimo un parent e supporta profondità arbitraria. Un sub-context è un Context, non un nuovo tipo di entità.

**INV-CCT-09 — Query separate e combinabili**
Concept, Entity, Context e Tag possono essere interrogati separatamente e congiunti tramite filtri `KnowledgeObject × Context × Tag`, senza ridurli a string matching.

**INV-CCT-10 — Spostamenti non semantici**
Cambiare Context a un Document o spostare un Context nella gerarchia non crea, fonde o rinomina KnowledgeObject.

**INV-CCT-11 — Cancellazione gerarchica prudente**

Un Context con figli o Document assegnati non viene eliminato a cascata; richiede prima spostamento o riassegnazione espliciti.

**INV-CCT-12 — Filtri trasversali derivati**
Context e Tag raggiungono Concept, Entity, SemanticBlock e FieldValue attraverso Document e KnowledgeOccurrence. Il risultato della query non crea ownership o associazioni dirette persistenti.

**INV-CCT-13 — Nessuna assegnazione diretta implicita**
Entity, EntityType, SemanticBlock e FieldValue non ricevono `context_id` o Tag per effetto della loro presenza in un Document. Un modello diretto futuro richiede una decisione architetturale esplicita.

## F. Relation, Comment e Source

**INV-REL-01 — Estremi KnowledgeObject**
Una KnowledgeRelation collega due KnowledgeObject esistenti e supporta le combinazioni Concept/Entity; non collega Context o Tag.

**INV-REL-02 — Molteplicità**  
Un KnowledgeObject può avere molte relazioni in entrata e in uscita e più predicati verso lo stesso KnowledgeObject.

**INV-COM-01 — Anchor non ridotto a offset assoluti**  
Un Comment ancorato a un range non usa una coppia di posizioni assolute come unica identità dell'anchor.

**INV-COM-02 — Range e subject distinti**
Un commento su testo usa un anchor ProseMirror robusto; un commento su KnowledgeObject, KnowledgeRelation, SemanticBlock o FieldValue usa un'associazione dedicata verificabile e non simula un range vuoto o fittizio.

**INV-SRC-01 — Provenance distinta**  
Source e SourceLocator sono distinti: la fonte identifica l'opera/risorsa, il locator una sua porzione.

**INV-SRC-02 — Catalogo riutilizzabile**

Collegare una fonte selezionata dal catalogo non duplica la Source; crea una nuova associazione verso la stessa identità.

**INV-SRC-03 — Ambito esplicito**

Una fonte collegata all'intero Document e una collegata a un range usano associazioni distinte e interrogabili.

**INV-SRC-04 — Anchor senza testo duplicato**

Il testo corrente di un SourceAnchor si legge dal documento; testo copiato e offset assoluti non sono la sua identità autorevole.

**INV-SRC-05 — Cancellazione non distruttiva**

Eliminare un SourceAnchor o una sua citazione non elimina automaticamente Source, SourceLocator o altri collegamenti.

**INV-SRC-06 — Locator coerente**

Ogni SourceLocator usato da DocumentSource o SourceCitation appartiene alla stessa Source dell'associazione.

**INV-SRC-07 — Ordine contributor non ambiguo**

Ogni `SourceContributor.ordinal` è univoco nella propria Source; la modifica dell'ordine non cambia l'identità del contributor.

**INV-SRC-08 — Identificatori normalizzati prudenti**

La stessa coppia normalizzata schema/valore non compare due volte nella medesima Source. Tra Source diverse genera un candidato duplicato, mai un merge automatico.

**INV-SRC-09 — Source distinta da Entity**
Una Source bibliografica e una Entity che rappresentano lo stesso paper, libro o soggetto mantengono identità, identificatori e lifecycle separati; un collegamento esplicito non le fonde.

**INV-SRC-10 — Associazioni verificabili**
Provenance verso Document, KnowledgeObject, KnowledgeOccurrence, SemanticBlock, FieldValue e KnowledgeRelation usa associazioni dedicate con FK verificabili. Non si usa una generica coppia target type/ID.

**INV-SRC-11 — Source reference abilitata insieme alla FK**
Un FieldValue `source_reference` e la provenance FieldValue→Source vengono accettati soltanto quando Source e l'eventuale SourceLocator possono essere validati nella stessa transazione.

**INV-AST-01 — Binario esterno al documento**

Il `document_json` contiene `assetId` e attributi editoriali, mai il file immagine codificato base64.

**INV-AST-02 — Asset distinto dalla provenance**

Asset identifica un file; Source identifica una provenienza; KnowledgeObject e dati strutturati identificano conoscenza. Collegamenti espliciti non fondono queste entità.

**INV-AST-03 — Eliminazione recuperabile**

Rimuovere l'ultimo nodo che usa un Asset non elimina immediatamente il file; undo/redo e retention restano possibili.

**INV-AST-04 — Validazione del contenuto**

Il server determina tipo e dimensioni dal contenuto reale, applica limiti e non usa il nome originale come percorso.

## G. Ipertesto, struttura ed export

**INV-LNK-01 — Destinazione per identità**
Un DocumentLink punta a `target_document_id` e, opzionalmente, a `target_document_anchor_id`; titolo, slug, testo e posizione non sono identità autorevoli.

**INV-LNK-02 — Doppia rappresentazione coerente**
Per un DocumentLink `active`, record e mark concordano su ID, Document sorgente, Document destinazione e anchor opzionale. Un mismatch blocca atomicamente il salvataggio.

**INV-LNK-03 — Anchor appartenente alla destinazione**
Se un link specifica un DocumentAnchor, l'anchor appartiene allo stesso Document indicato come destinazione.

**INV-LNK-04 — Contenuto nel documento**
Testo cliccabile e posizione del link restano nel `document_json` o nel `DocumentNote.body_json` proprietario; testo e livello dell'heading restano nel `document_json`. Record di link e anchor non ne conservano copie autorevoli.

**INV-LNK-05 — Backlink derivato**
I backlink derivano dai DocumentLink attivi e sono ricostruibili dai documenti; non vengono scritti come contenuto nel Document destinazione.

**INV-LNK-06 — Cancellazione prudente**
La cancellazione della destinazione o di un anchor non cancella né riscrive a cascata il contenuto dei Document sorgenti. La cancellazione fisica di un Document con link entranti attivi richiede gestione esplicita.

**INV-LNK-07 — Copy/paste e cut/paste**
Il paste da copia crea nuovi `documentLinkId` mantenendo la destinazione. L'ID si conserva soltanto per cut/paste verificato nello stesso Document e senza duplicati.

**INV-LNK-08 — Input non fidato**
Link o anchor provenienti da documento manipolato o clipboard non creano implicitamente Document, DocumentLink o DocumentAnchor e non possono riferire destinazioni inesistenti.

**INV-LNK-09 — Collocazione univoca nell'aggregato**
Un `documentLinkId` attivo compare in un solo intervallo logico considerando insieme `Document.document_json` e i `DocumentNote.body_json` attivi del Document sorgente. La copia di una nota rigenera gli ID dei link incorporati; un trasferimento verificato li conserva solo senza duplicati.

**INV-LNK-10 — Lifecycle subordinato della nota**
I DocumentLink incorporati in una DocumentNote ne seguono attivazione e detach senza perdere identità; un link in una nota detached non produce un backlink attivo.

**INV-TOC-01 — Indice derivato**
L'indice navigabile è derivato ricorsivamente dall'ordine dei Document figli e dagli heading correnti con i loro DocumentAnchor; non è una seconda copia autorevole di struttura o titoli.

**INV-STY-01 — Stili semantici**
Paragraph “Normale”, heading, liste e blockquote esprimono struttura nel documento. Ruoli di parte/capitolo/sezione sono semantici. Tema visuale e CSS non diventano una seconda fonte autorevole né stili arbitrari persistiti sui nodi.

**INV-NTE-01 — Corpo autorevole**
Il corpo di una footnote/endnote è `DocumentNote.body_json`; il numero, la label e il rendering non sono testo autorevole persistito.

**INV-NTE-02 — Un riferimento attivo**
Ogni DocumentNote `active` ha esattamente un `documentNoteReference` nel Document proprietario. Riferimenti duplicati, sconosciuti o appartenenti ad altro Document bloccano il salvataggio.

**INV-NTE-03 — Numero e collocazione derivati**
Numerazione, pagina, capitolo e posizione finale derivano dall'ordine dei riferimenti, dalla gerarchia Document e dallo scope. Lo scope `book` richiede una radice/antenato `book`; nessuna collocazione è identità persistente.

**INV-NTE-04 — Lifecycle prudente**
Delete del riferimento produce `detached`; undo riattiva. Copy/paste duplica riferimento e body con nuovo UUID, mentre cut/paste verificato nello stesso Document può conservare l'identità.

**INV-NTE-05 — Nessuna ricorsione iniziale**
Il body di una DocumentNote non contiene altre DocumentNote nella prima versione.

**INV-NTE-06 — Copia profonda delle identità incorporate**
La copia di una DocumentNote crea una nuova nota e nuove manifestazioni incorporate, inclusi DocumentLink e BibliographicCitation quando supportate, mantenendo destinazioni e Source ma senza riusare i loro ID. Tra Document i nuovi record appartengono alla destinazione.

**INV-REF-01 — Catalogo bibliografico unico**
Il reference manager usa Source, SourceContributor, SourceIdentifier e SourceLocator; non crea una seconda copia della fonte per ogni citazione o Document.

**INV-REF-02 — Citazione derivata**
BibliographicCitation e item sono autorevoli per Source, locator, ordine e opzioni. Label, punteggiatura e numerazione sono derivate dallo stile e non vengono persistite come testo concorrente.

**INV-REF-03 — Bibliografia derivata**
La bibliografia deriva dalle citazioni attive e dalle Source incluse esplicitamente; quella di un Document `book` percorre i discendenti in ordine. Modificarne lo stile non modifica Source o contenuto citazionale.

**INV-REF-04 — Provenance distinta**
SourceCitation su un range e BibliographicCitation nel testo sono associazioni diverse, anche quando riusano la stessa Source.

**INV-REF-05 — Cancellazione e merge prudenti**
Eliminare una citazione non elimina Source o metadata condivisi. Deduplicazione, merge e sostituzione di Source richiedono conferma esplicita e aggiornamento transazionale dei riferimenti.

**INV-REF-06 — Locator coerente**
Ogni SourceLocator usato da un BibliographicCitationItem appartiene alla Source dello stesso item.

**INV-REF-07 — Citazione collocata una volta**
Una BibliographicCitation attiva compare una sola volta considerando contenuto principale e DocumentNote attive del Document proprietario; duplicazioni tra contenitori bloccano atomicamente il salvataggio.

**INV-REF-08 — Ordine item non ambiguo**
Ogni `BibliographicCitationItem.ordinal` è univoco nella propria BibliographicCitation.

**INV-REF-09 — Lifecycle subordinato della nota**
Le BibliographicCitation incorporate in una DocumentNote ne seguono attivazione e detach con gli stessi ID; una citazione in una nota detached non alimenta la bibliografia attiva.

**INV-MATH-01 — Formula autorevole**
Il sorgente della formula appartiene al nodo inline/block del `document_json`; rendering, plain text e rappresentazioni esportate sono derivati deterministici.

**INV-EXP-01 — Export derivato**
Un export legge `document_json`, body delle note e record semantici/bibliografici autorevoli; non modifica alcun dato persistente né diventa una nuova fonte di verità.

**INV-EXP-02 — Nessuna perdita silenziosa**
Nodi, mark o metadata non rappresentabili nel formato scelto producono un errore o una diagnostica esplicita secondo il profilo di export.

**INV-EXP-03 — Link deterministici**
Nell'export di un Document contenitore, figli, destinazioni e anchor sono percorsi e mappati deterministicamente; nell'export singolo i riferimenti esterni non vengono trasformati in URL pubblici inventati.

**INV-EXP-04 — Struttura editoriale fedele**
Parti, capitoli, stili semantici, footnote/endnote, citazioni e bibliografia vengono mappati nativamente quando il formato lo consente; ogni degradazione è diagnosticata.

**INV-EXP-05 — Dati semantici e strutturati espliciti**
Ogni exporter dichiara la mappatura o degradazione di KnowledgeOccurrence Concept/Entity, riferimenti Entity/SemanticBlock, FieldValue, KnowledgeRelation e provenance. Non usa `plain_text` per ricostruirli e non li omette silenziosamente.

## H. Matrice minima di test

Quando le relative fasi saranno implementate, devono esistere almeno questi test regressivi:

| Caso | Livello minimo | Invarianti |
|---|---|---|
| round trip documento formattato | frontend + API | DOC-01..05 |
| Highlight: bordi, delete, undo/redo, clipboard e reload | editor + API | HLT-01..03, DOC-03 |
| Highlight: nessuna scrittura semantica | integrazione | HLT-01, 03, DOC-05 |
| modifica interna occurrence | editor | OCC-03, 06, 12 |
| cancellazione parziale | editor + sync | OCC-07 |
| cancellazione totale e save, per Concept ed Entity | integrazione | OCC-08, CON-03, ENT-03 |
| undo/redo prima e dopo save | editor + integrazione | OCC-09, 13 |
| copy/paste singola e multipla | editor | OCC-05, 10, 14 |
| cut/paste verificato e ambiguo | editor | OCC-11 |
| reload con più occurrence dello stesso Concept o Entity | end-to-end | OCC-13, 14 |
| documento con ID duplicato | API | OCC-01, 05, DOC-05 |
| mismatch `knowledgeObjectId`/`objectType` | API | OCC-02, 03, 16, KNO-02 |
| UUID malformato o collisione | dominio/API | DOC-06, OCC-01 |
| timestamp creato, aggiornato e update rifiutato | dominio/API | DOC-05, 12 |
| Concept senza occurrence | dominio/API | CON-02 |
| Concept ed Entity omonimi con ID distinti | dominio/schema | KNO-01, CCT-01, 02 |
| EntityType configurabile, archive/restore e cancellazione referenziata bloccata | dominio/schema | ENT-01..04 |
| EntityIdentifier con scheme/authority, normalizzazione e duplicati intra/inter-Entity | dominio + integrazione | EID-01..05 |
| occurrence comuni di Concept ed Entity e discriminator manipolato | API + integrazione | OCC-02, 03, KNO-02 |
| Relation Concept↔Concept, Entity↔Entity ed Entity↔Concept | dominio + integrazione | REL-01, 02, KNO-02 |
| Template con field ordinati, raccomandazioni EntityType e SemanticBlock multipli | dominio + integrazione | TPL-01..05 |
| riferimenti editoriali a Entity/SemanticBlock senza copia del payload | editor + API + integrazione | TPL-01, 04, 06, DOC-01 |
| FieldValue valido e payload/discriminator incompatibile | dominio/schema | FLD-01, 02, 04 |
| enum e reference multi con ordinal duplicato | dominio/schema | FLD-01, 02 |
| FieldValue collegato opzionalmente a Concept | integrazione | FLD-03 |
| import provider non sovrascrive valore manuale | integrazione | FLD-05 |
| provider assente/offline e proposta con provenance completa | integrazione | PRV-01, 02, FLD-05 |
| Alias diverso dal testo | dominio/API | ALS-01..03 |
| omonimo Concept/Entity/Context/Tag | API/search | CCT-01, 02 |
| filtro Concept/Entity × Context × Tag derivato da occurrence | integrazione | CCT-09, 12, 13 |
| ciclo Context | dominio/API | CCT-08 |
| gerarchia Context a più livelli e filtro subtree | integrazione | CCT-08, 09 |
| cancellazione Context non vuoto | dominio/API | CCT-11 |
| gerarchia Document: ordine, move, anti-ciclo e delete parent | dominio + integrazione | DOC-07, 08, 11 |
| archive, trash, restore e purge bloccato da riferimenti | integrazione + end-to-end | DOC-13, 14, OCC-18 |
| split di capitolo con occurrence, anchor, link, note, citazioni e commenti | integrazione + end-to-end | DOC-09, 10, OCC-02, LNK-03, COM-01 |
| rollback completo di split con riferimento invalido | integrazione | DOC-05, 09 |
| riuso libro dal catalogo in più Document | integrazione | SRC-01, 02, 03 |
| ordine contributor e identificatore duplicato nella stessa/altre Source | dominio + integrazione | SRC-02, 07, 08, REF-01, 05 |
| fonte su range: edit, delete, undo, reload | editor + integrazione | SRC-03..05 |
| Source e Entity dello stesso paper restano distinte | dominio + integrazione | SRC-09, EID-01 |
| Source su KnowledgeObject, occurrence, blocco, valore e Relation | integrazione | SRC-10, DOC-05 |
| source_reference prima/dopo disponibilità della FK Source | dominio + integrazione | FLD-04, SRC-11 |
| locator appartenente ad altra Source | API | SRC-06 |
| upload e round trip immagine inline | integrazione + end-to-end | AST-01, 04 |
| delete e undo dell'ultimo nodo immagine | editor + integrazione | AST-03 |
| Asset collegato a Entity/FieldValue/Source senza fusione | integrazione | AST-02, SRC-09 |
| paragraph Normale e heading mantengono struttura con temi diversi | editor | STY-01, DOC-03 |
| rinomina/spostamento heading e navigazione indice | editor + end-to-end | TOC-01, LNK-01, 04 |
| link a Document e backlink dopo save/reload | integrazione + end-to-end | LNK-01..05 |
| link a heading eliminato e undo | editor + integrazione | LNK-03, 06 |
| copy/paste e cut/paste di link interno | editor | LNK-02, 07, 08 |
| target o anchor manipolato/inesistente | API | LNK-02, 03, 08 |
| formula inline/block: edit, undo e round trip | editor + API | MATH-01, DOC-03 |
| commento testuale e commento su FieldValue usano subject distinti | integrazione | COM-01, 02 |
| footnote/endnote: edit, reorder, delete, undo e reload | editor + integrazione | NTE-01..05, LNK-10, REF-09 |
| endnote scope book senza/con radice valida | dominio + integrazione | NTE-03, DOC-07 |
| copy/paste di nota nello stesso e in altro Document | editor + integrazione | NTE-02, 04 |
| copy/paste di nota con link e citazione incorporati | editor + integrazione | NTE-06, LNK-09, REF-07 |
| link o citationId duplicato tra testo principale e body nota | API | DOC-05, LNK-09, REF-07 |
| citazione multi-Source con locator, ordine univoco e cambio stile | integrazione | REF-01..04, 06, 08 |
| deduplicazione/merge Source confermato e rollback | integrazione | REF-05, DOC-05 |
| bibliografia con fonti citate e incluse esplicitamente | integrazione | REF-01..03 |
| bibliografia aggregata di un libro con Document figli | integrazione | DOC-07, REF-03 |
| export fixture rappresentativa nei quattro formati | integrazione | EXP-01..04 |
| export con elemento non supportato | integrazione | EXP-02 |
| export Document contenitore con link e anchor | integrazione + end-to-end | EXP-03, 04, LNK-01, 03 |
| export con Entity, SemanticBlock e FieldValue supportati/degradati | integrazione | EXP-01, 02, 05 |

Ogni bug editoriale corretto deve produrre un nuovo test regressivo nella suite più vicina alla causa e, se necessario, un test end-to-end del comportamento osservabile.

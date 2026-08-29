# Roadmap

## Regola di avanzamento

Si implementa una fase per volta. Una fase può iniziare soltanto quando:

1. il criterio di uscita della fase precedente è soddisfatto;
2. build e test applicabili sono verdi;
3. le decisioni nuove sono riflesse nei documenti architetturali;
4. ogni bug noto che viola un'invariante è risolto o dichiarato blocco.

Una fase non può iniziare se in `DECISIONS.md` esiste una decisione `scheduled` con scadenza precedente o uguale a quella fase.

Le feature delle fasi successive non vengono anticipate “per comodità”. È ammesso predisporre nel modello soltanto quanto serve a non chiudere possibilità già richieste, senza esporre funzionalità premature.

## FASE 0 — Architettura (completata)

Deliverable:

- README e documenti di architettura, dominio, invarianti, roadmap e regole operative;
- decisione sul lifecycle delle occurrence;
- separazione formale Concept/Context/Tag, poi estesa dalla Phase 1.1 alla base chiusa Concept/Entity senza assorbire Context o Tag;
- verifica degli scenari del modello;
- decisioni risolutive per i problemi architetturali individuati.

Gate di uscita:

- nessun codice applicativo o dipendenza introdotta;
- tutte le entità richieste definite;
- identità in editing, delete, undo/redo, copy/paste, cut/paste, serialization e reload specificata;
- query `KnowledgeObject × Context × Tag` spiegabile tramite relazioni del modello, con Concept ed Entity distinguibili;
- ambiguità bloccanti risolte prima dello schema.

## FASE 1 — Bootstrap minimale (completata)

Creare struttura frontend/API/data/tests/docs, editor TipTap base, API PHP minimale e schema SQLite iniziale. Implementare soltanto i flussi minimi di creazione, elenco, apertura e aggiornamento del Document: titolo, contenuto, salvataggio e reload. La cancellazione non appartiene alla fase. Lo schema Document contiene solo `id`, `title`, `document_json`, `plain_text`, `revision`, `created_at` e `updated_at`; gli altri campi del modello arrivano con migrazioni nelle rispettive fasi. Il corpo usa `paragraph` come stile semantico “Normale”; heading, liste e blockquote esprimono struttura, mentre font, dimensioni e spaziature ordinarie appartengono al tema e non a stili inline arbitrari.

Prima di codificare:

- definire JSON schema/allowlist del documento;
- scegliere runner di test PHP senza introdurre framework applicativi.

Decisioni già vincolanti dalla FASE 0: UUIDv7 per tutte le entità, `Document.revision` obbligatoria, optimistic concurrency e policy di conflitto definite in `ARCHITECTURE.md`.

Gate: un Document con paragraph, heading, bold, italic, underline, liste, blockquote e history sopravvive esattamente a save/reload; test e build verdi.

## PHASE 1.1 — Domain Model Extension and Alignment (completata)

Fase intermedia additiva tra FASE 1 e FASE 2. Introdurre nel modello `KnowledgeObject` come base chiusa di Concept ed Entity, EntityType configurabili, KnowledgeOccurrence comuni, KnowledgeRelation comuni, Template, TemplateField, SemanticBlock e FieldValue tipizzati. Aggiornare insieme dominio, invarianti e architettura; aggiungere una migration incrementale senza modificare la tabella Document, le API o l'allowlist editoriale della FASE 1.

Decisioni vincolanti:

- Context e Tag restano fuori da KnowledgeObject;
- occurrence e Relation comuni usano ID/discriminator verificati, non foreign key polimorfiche deboli;
- SemanticBlock è un'istanza strutturata di Template riferita a Entity, non testo evidenziato;
- FieldValue usa payload tipizzati e interrogabili; i tipi multi usano righe ordinate;
- il collegamento FieldValue→Concept è opzionale e non genera Concept o Alias;
- provider reali, autocomplete, mappe, AI, API CRUD e UI avanzata non appartengono alla fase;
- `source_reference` può essere definito, ma i valori e la provenance Source attendono la migration della fase Sources per avere FK verificabili.

Gate: migration additiva e ripetibile; vincoli di sottotipo, tipo dei FieldValue e Relation coperti da test; schema/API/editor Document invariati; tutti i test FASE 1, type-check e build verdi.

## FASE 2 — Highlight normale (completata)

Introdotto il solo mark visuale di Highlight, con il solo attributo opzionale `color` in forma `#RRGGBB`; la palette editoriale locale è modificabile da 4 a 10 colori senza diventare dato di dominio. L'input interno estende il mark, quello esattamente ai bordi resta non evidenziato; delete parziale conserva la formattazione sul testo residuo e delete totale la rimuove. Sono coperti editing, undo/redo, copy/paste, cut/paste, serializzazione, salvataggio e reload. Highlight resta formattazione e non è una KnowledgeOccurrence, un SemanticBlock o un FieldValue; non crea Concept, Entity o dati strutturati.

Gate soddisfatto: comportamento stabile e nessuna scrittura nelle tabelle KnowledgeObject, Concept, Entity, KnowledgeOccurrence, SemanticBlock o FieldValue.

## FASE 3 — KnowledgeObject e Semantic Occurrences (completata)

Introdurre il mark comune `knowledgeOccurrence` per entrambi i sottotipi. Da una selezione l'utente può creare un nuovo Concept, associare un Concept esistente cercato per canonical name/Alias, creare una nuova Entity con EntityType configurabile oppure associare una Entity esistente. Ogni associazione crea un nuovo ID KnowledgeOccurrence; `knowledgeObjectId` e `objectType = concept|entity` vengono validati insieme.

Completato: il mark comune `knowledgeOccurrence` è validato lato editor/API; i comandi creano Concept o Entity con EntityType configurabile, oppure associano un oggetto esistente. Il salvataggio crea Document, KnowledgeObject/sottotipo e occurrence nella stessa transazione e rifiuta mark non persistiti o incoerenti senza modifiche parziali. La ricerca minima di Concept/Entity e la gestione minima EntityType sono disponibili. Test Playwright in browser reale coprono Concept e Entity con save/reload.

Gate soddisfatto: creazione atomica di KnowledgeObject, sottotipo, occurrence e mark; persistenza e rendering coerenti per entrambi i discriminator; nessuna conversione automatica Concept↔Entity e nessuna promozione del testo ad Alias o EntityIdentifier; flussi critici coperti da test end-to-end in browser reale.

## FASE 4 — Invarianti delle KnowledgeOccurrence (completata)

Implementare per entrambi i sottotipi editing interno e ai bordi, delete parziale/totale, undo/redo, copy/paste, cut/paste verificato e ambiguo, serializzazione, reload, frammentazione contigua e input manipolato. La perdita dell'ultima occurrence porta a `orphan` soltanto un Concept prima attivo; una Entity resta `active` e nessun KnowledgeObject viene eliminato. Il paste genera un nuovo `occurrenceId` mantenendo `knowledgeObjectId`/`objectType`.

Completato: il mark ha un round trip HTML esplicito su `data-occurrence-id`, `data-knowledge-object-id` e `data-object-type` e viene accettato soltanto con attributi completi e ben formati. Il paste riscrive ogni `occurrenceId` una volta per ID copiato, conserva `knowledgeObjectId`/`objectType` e verifica gli oggetti con `GET /api/knowledge-objects`, rimuovendo il solo mark non fidato con avviso non bloccante. Il cut/paste conserva gli ID unicamente con la prova `application/x-nectrix-slice` nello stesso Document, token non consumato, originali assenti e intervallo unico; ogni caso ambiguo genera nuovi ID. L'estrattore API rifiuta intervalli disgiunti, più textblock, attributi incompleti e attributi incoerenti per lo stesso ID; l'associazione a un KnowledgeObject inesistente fallisce con `knowledge_object_missing`. Le creazioni inviate al salvataggio sono derivate dai mark presenti nel documento, quindi un undo non lascia creazioni fantasma e un paste dichiara il proprio record.

Gate soddisfatto: `INV-OCC-01`, `INV-OCC-02`, `INV-OCC-03`, `INV-OCC-05`, `INV-OCC-06`, `INV-OCC-07`, `INV-OCC-10`, `INV-OCC-11`, `INV-OCC-12`, `INV-OCC-13`, `INV-OCC-14`, `INV-OCC-15` e `INV-OCC-16` coperti da test verdi per Concept ed Entity, inclusi ID globali e discriminator incoerenti, con copy/paste, cut/paste e undo in browser reale. `INV-OCC-04` resta garantito dallo schema.

Rinviato alla FASE 5, che ha come proprio deliverable gli stati `active`/`detached`/`deleted` e la riconciliazione transazionale: il lato database di `INV-OCC-08` e `INV-OCC-09`, cioè il passaggio ad `detached` degli ID assenti e la riattivazione idempotente, e con essi la transizione a `orphan` dei soli Concept. Nella FASE 4 la cancellazione totale rimuove il mark dal documento e undo lo ripristina con lo stesso ID, senza che il salvataggio elimini alcun record. `INV-OCC-17`, `INV-OCC-18` e `INV-OCC-19` restano fuori portata fino alle rispettive fasi.

## FASE 5 — Sincronizzazione DB ↔ documento (completata)

Implementare estrazione, validazione e riconciliazione transazionale di tutte le KnowledgeOccurrence: presenza nel documento e nel DB, nuovi ID dichiarati, ID assenti o duplicati, intervalli disgiunti, KnowledgeObject inesistenti, appartenenza errata, discriminator incoerenti, stati `active`/`detached`/`deleted` e optimistic concurrency. SemanticBlock e FieldValue non vengono inferiti dal documento; i riferimenti editoriali arrivano soltanto nella FASE 10.1.1 e non duplicano il dato autorevole.

Completato: il salvataggio riconcilia i record dell'intero Document nella stessa transazione. Gli ID validi presenti restano o tornano `active`, gli ID prima attivi e ora assenti diventano `detached` senza rimozione fisica, un record `deleted` è terminale e blocca il salvataggio invece di tornare attivo. Una creazione ridichiarata dopo un undo riattiva il record esistente anziché fallire come duplicato, mentre lo stesso ID con un'associazione o un Document differenti viene rifiutato. Il Concept che perde l'ultima occurrence attiva passa a `orphan` e torna `active` quando ne riacquista una; le Entity non usano quello stato e nessun KnowledgeObject viene mai eliminato. Un Concept archiviato resta archiviato. Il client conserva i KnowledgeObject creati e non ancora salvati anche dopo un salvataggio dello stesso Document, così un undo che ne ripristina il mark ne dichiara ancora la creazione.

Gate soddisfatto: salvataggi ripetuti idempotenti su contenuto invariato, su cancellazione e su ripristino; conflitto di revisione, occurrence frammentata, KnowledgeObject inesistente e occurrence eliminata falliscono atomicamente senza modifiche parziali né cancellazioni definitive. `INV-OCC-08`, `INV-OCC-09`, `INV-OCC-17` e `INV-OCC-18` sono ora coperti anche sul lato database, insieme alla transizione `orphan` dei soli Concept.

`INV-OCC-19`, cioè la cache del testo indicizzato, resta alla FASE 10 che introduce la ricerca full text. Il comando esplicito che porta una occurrence a `deleted` appartiene alle fasi di inspector e manutenzione: qui è coperto il fatto che quello stato non torni mai `active`.

## FASE 6 — Inspectors e popover (completata)

Introdurre Concept Inspector ed Entity Inspector e aprire quello appropriato dal popover di `knowledgeOccurrence`. Il Concept Inspector mostra inizialmente canonical name, descrizione e occurrence; l'Entity Inspector nome, EntityType e occurrence. Aggiungere archive/restore espliciti per Concept, Entity ed EntityType; i tipi referenziati archiviati restano validi per le Entity esistenti. Alias, EntityIdentifier, Context derivati, KnowledgeRelation, SemanticBlock e Source ampliano gli inspector soltanto dopo le rispettive fasi. Il testo delle occurrence viene sempre estratto dai Document.

Completato in seguito, con la FASE 7 gia chiusa: nome e descrizione di Concept ed Entity si modificano dall'inspector, completando il CRUD dei KnowledgeObject senza toccare occurrence, alias, identificatori e stato.

Completato: il mark `knowledgeOccurrence` ha ora un segno visivo discreto, distinto dal fondo colorato dell'Highlight, e il suo popover apre l'inspector. Il pannello si apre a destra dell'editor, che resta visibile e modificabile. Il Concept Inspector mostra canonical name, descrizione, stato e occurrence; l'Entity Inspector nome, EntityType con il suo stato, descrizione e occurrence. Il discriminator usato per scegliere l'inspector è quello autorevole del database, non quello del mark. Ogni occurrence è elencata con il Document di appartenenza, lo stato e il testo estratto al momento della lettura dal contenuto del Document; una occurrence `detached` compare senza testo invece di mostrarne una copia obsoleta. Il click su una occurrence apre il Document e porta il mark in vista. Archive e restore sono espliciti per Concept, Entity ed EntityType e non cancellano nulla: il restore di un Concept lo riporta ad `active` solo se ha ancora occurrence attive, altrimenti a `orphan`. Un EntityType archiviato resta valido per le Entity esistenti e rifiuta soltanto la creazione di nuove Entity, con errore `entity_type_archived`.

Gate soddisfatto: nessuna copia del testo o dato di presentazione persistito, verificato da un test in cui il testo dell'occurrence cambia insieme al Document; navigazione fra Document e occurrence dopo editing e reload; inspector coerente con `objectType` per entrambi i discriminator; archive e restore coperti da test backend ed end-to-end senza cancellazioni.

Alias, EntityIdentifier, Context derivati, KnowledgeRelation, SemanticBlock e Source ampliano gli inspector nelle rispettive fasi successive, come già previsto.

## FASE 6.1 — Lifecycle e cancellazione dei Document (completata)

Introdurre `Document.status = active|archived|trashed`, archive, trash e restore non distruttivi. Gli archiviati sono in sola lettura e ricercabili con scope esplicito; i trashed compaiono soltanto nella vista di recupero. Entrambi conservano contenuto, associazioni e stato delle KnowledgeOccurrence. Il purge fisico è un comando di manutenzione separato con preview, backup, controllo di figli, riferimenti entranti ed evidence; solo dopo tali verifiche rimuove le manifestazioni Document-owned senza eliminare KnowledgeObject o dati Entity-owned. Non è un `DELETE` CRUD implicito.

Completato: `Document.status` arriva con una migration additiva. Archive e trash sono reversibili e non toccano contenuto, associazioni né stato delle KnowledgeOccurrence; gli archiviati e i cestinati sono in sola lettura, l'editor ne nasconde i comandi e il salvataggio li rifiuta con `document_read_only`. Le liste sono per scope esplicito: `active` è il predefinito, `archived` e `trashed` compaiono solo se richiesti, con un selettore dedicato nella sidebar. Le transizioni ammesse sono verificate e quelle impossibili rifiutate con `invalid_document_transition`.

Il purge fisico è il comando di manutenzione `php api/bin/purge-document.php`, non un endpoint: senza `--apply` mostra soltanto la preview con impatto e impedimenti. Richiede un Document nel cestino, scrive un backup JSON del Document e delle sue occurrence prima di toccare i dati, poi rimuove in una sola transazione il Document e le manifestazioni che possiede, aggiornando lo stato dei soli Concept coinvolti. I riferimenti entranti non sono una lista scritta a mano: vengono scoperti interrogando lo schema, quindi qualunque tabella futura che punti a `documents` blocca automaticamente il purge finché non viene gestita esplicitamente.

Gate soddisfatto: archive, trash e restore coperti da test backend ed end-to-end; nessuna cascade verso KnowledgeObject o dati strutturati, verificata dopo il purge; purge bloccato sia da un Document non cestinato sia da un riferimento entrante, con rollback completo su errore e backup conservato in ogni caso.

## FASE 7 — ConceptAlias ed EntityIdentifier (completata)

Implementare CRUD ConceptAlias e EntityIdentifier come modelli distinti. EntityIdentifier conserva `scheme`, `value`, `normalized_value` e `authority_or_namespace`: ticker, CIK, LEI e identificatori clinici non sono Alias, mentre proprietà come exchange possono essere FieldValue. `scheme` è lowercase stabile, authority assente è `NULL` e ogni scheme dichiara una policy versionata di normalizzazione/case-sensitivity. La creazione di occurrence non promuove il testo ad Alias o Identifier.

Completato: ConceptAlias ed EntityIdentifier sono modelli distinti, aggiunti e rimossi solo da comandi espliciti dagli inspector. Un alias può ripetersi fra Concept diversi ma non dentro lo stesso; la ricerca lo usa restituendo Concept distinti, una sola volta ciascuno anche quando più alias corrispondono. Un EntityIdentifier conserva `scheme` lowercase, valore originale, valore normalizzato, authority nullable e la versione della policy applicata. Ogni scheme dichiara la propria policy versionata: `ticker` richiede l'authority, `cik` porta alla forma canonica a dieci cifre, gli scheme non dichiarati usano la policy predefinita che si limita a normalizzare gli spazi ed è case-insensitive. L'identità normalizzata non si ripete nella stessa Entity, mentre la stessa identità su un'altra Entity viene mostrata come candidato duplicato senza alcuna fusione. Aggiungere o rimuovere alias e identificatori non tocca occurrence né documenti.

Gate soddisfatto: ambiguità degli Alias verificata con due Concept che condividono un alias e con un Concept che ne ha due corrispondenti; duplicati normalizzati nella stessa Entity rifiutati anche con maiuscole, spazi e zeri iniziali differenti; collisione fra Entity che produce candidati duplicati con entrambe le Entity intatte; authority obbligatoria dove lo scheme la richiede e parte dell'identità, così lo stesso ticker su exchange diversi resta ammesso.

## FASE 8 — Context (completata)

CRUD di Context e sub-context a profondità arbitraria, breadcrumb, move aciclico di interi rami, assegnazione di un Context principale al Document, filtro `exact`/`subtree` e raggruppamento occurrence per Context. Concept, Entity ed EntityType vengono filtrati attraverso il percorso esplicito Context→Document→KnowledgeOccurrence→KnowledgeObject; SemanticBlock si aggiunge allo stesso percorso dopo la FASE 10.1. Nessuno riceve silenziosamente `context_id`. La cancellazione di nodi con figli o Document richiede riassegnazione esplicita.

Completato: la migration 006 aggiunge `contexts` con self-FK e `documents.context_id`. Un sub-context è un Context con `parent_id`, la profondità non ha limiti e percorso e breadcrumb si calcolano dalla gerarchia con query ricorsive, senza percorsi materializzati. Il nome è unico fra fratelli ma si ripete liberamente in rami diversi. Il move sposta l'intero ramo e rifiuta destinazioni dentro il ramo stesso, auto-parenting incluso. La cancellazione è prudente: un Context con sub-context o con Document assegnati va prima riassegnato. Il filtro `exact`/`subtree` seleziona i Document del ramo e la sidebar espone albero, modalità e comandi. Un Document riceve un Context solo con il comando dedicato, mai come effetto del salvataggio.

Concept ed Entity si raggiungono attraverso il percorso esplicito Context→Document→KnowledgeOccurrence→KnowledgeObject e il pannello li mostra come elenco derivato: nessuno riceve `context_id` e lo stesso oggetto presente in più Context compare una sola volta.

Gate soddisfatto: lo stesso Concept in due Context diversi restituito una volta sola; query derivate per entrambi i sottotipi, ricorsione su breadcrumb e subtree, move di ramo, anti-ciclo, filtro exact/subtree e cancellazione prudente coperti da test backend, più due end-to-end sul filtro e sul rifiuto della cancellazione.

## FASE 9 — Tag (completata)

CRUD e assegnazione/rimozione Tag ai Document, ricerca e filtro, sempre separati da Concept, Entity, EntityType, Template e FieldValue. Il filtro di KnowledgeObject è derivato tramite Document e KnowledgeOccurrence; SemanticBlock e FieldValue entrano dopo il Template System. Un'assegnazione diretta richiederebbe una nuova decisione architetturale.

Completato: la migration 007 aggiunge `tags` e l'associazione `document_tags` con chiave primaria sulla coppia. Il nome è unico normalizzato, il cancelletto è solo convenzione di scrittura e viene rimosso. L'assegnazione è idempotente, la rimozione non elimina il Tag e l'eliminazione è prudente: un Tag ancora assegnato va prima tolto dai Document. Un Document archiviato o nel cestino non accetta assegnazioni.

Il filtro per Tag richiede tutti i Tag selezionati e si combina con quello per Context sulla stessa lista di Document. I KnowledgeObject restano derivati: si raggiungono dai Document filtrati attraverso le occurrence attive, senza alcuna assegnazione diretta. La sidebar espone i Tag come chip con il numero di Document, e l'intestazione del documento permette di assegnarli e rimuoverli.

Gate soddisfatto: query per Tag, per Context e combinate verificate su Concept ed Entity, con lo stesso oggetto presente in più Document restituito una sola volta; il caso dei nomi uguali in dimensioni diverse è coperto da un test in cui Tag, Context e Concept si chiamano allo stesso modo e restano cose separate.

## FASE 10 — Full text e semantic search (completata)

Introdurre FTS5 su titolo e `Document.plain_text` e risultati categorizzati per Text, Concept, ConceptAlias, Entity, EntityIdentifier, EntityType, KnowledgeOccurrence, Context e Tag. KnowledgeRelation, DocumentNote, commenti e rich-text FieldValue entrano soltanto dopo le fasi che li introducono. Distinguere sempre string matching da matching per identità Concept/Entity.

Completato: la migration 008 aggiunge un indice FTS5 external content su titolo e `plain_text`, tenuto allineato da trigger e ricostruibile per intero dai dati autorevoli, che restano in `documents`. La ricerca restituisce risultati categorizzati per Document, Concept, Entity, EntityType, Context e Tag, e ciascuno dichiara come ha corrisposto: `full_text`, `name`, `alias`, `identifier` oppure `identity`.

La distinzione fra string matching e matching per identità è esplicita e verificabile: cercare un alias restituisce il Concept a cui appartiene, mostrando l'alias trovato; cercare un identificatore restituisce la Entity con il proprio scheme visibile; il comando «Dove compare» trova invece i Document attraverso le occurrence attive, anche quando le parole cercate non compaiono nel testo. I risultati Context dichiarano il proprio percorso derivato dalla gerarchia. KnowledgeRelation, DocumentNote, commenti e rich-text FieldValue entrano nelle rispettive fasi.

Gate soddisfatto: Alias e Identifier raggiungono il proprio KnowledgeObject senza contaminare le categorie, verificato anche in negativo; ogni risultato dichiara categoria e modo del match, con il percorso per i Context; l'indice svuotato e ricostruito ritrova gli stessi documenti.

## FASE 10.1 — Template System (completata)

Introdurre CRUD utente di Template/TemplateField, SemanticBlock multipli per Entity e FieldValue tipizzati, inclusi tipi multi e collegamento Concept opzionale. SemanticBlock resta Entity-owned e non è highlight, occurrence, range o copia nel `document_json`. Aggiungere raccomandazioni molti-a-molti ordinate EntityType↔Template per guidare la UI senza imporre compatibilità rigida. `source_reference` resta disabilitato fino alla FASE 16.

Completato: Template e TemplateField hanno CRUD completo con campi ordinati e riordinabili; la migration 009 aggiunge le raccomandazioni ordinate EntityType↔Template, che guidano la UI senza vincolare, tanto che un Template non raccomandato resta applicabile. Un'Entity può avere più SemanticBlock, ciascuno di un Template, e i FieldValue finiscono nella colonna tipizzata del proprio tipo: numeri, date, booleani, misure, valute e riferimenti non vengono mai confrontati come testo. La cardinalità è verificata, le opzioni di un campo enum sono vincolanti e un campo obbligatorio non può restare vuoto. `source_reference` resta rifiutato fino alla FASE 16.

La rinomina di Template e campi preserva l'ID, quindi i valori già scritti restano attaccati alla stessa definizione. Il CRUD ordinario non accetta il cambio di tipo: esiste il comando dedicato che senza `apply` mostra soltanto l'impatto e, quando esistono valori, richiede di dichiarare esplicitamente che vanno scartati, applicando poi definizione e scarto in un'unica transazione. Un riferimento a Concept o Entity deve puntare a qualcosa che esiste già: scriverlo non crea nulla, e ogni scrittura sostituisce soltanto il campo indicato.

Gate soddisfatto: ordinamento dei campi e conservazione dell'ID alla rinomina; valori tipizzati, cardinalità e appartenenza validati con scrittura atomica per campo; cambio di tipo bloccato nel CRUD ordinario, con preview e transazione nel comando separato; nessuna creazione automatica di Concept e nessun overwrite implicito, verificati contando i Concept prima e dopo.

## FASE 10.1.1 — Riferimenti editoriali a Entity e SemanticBlock (completata)

Introdurre i nodi `entityReference` e `semanticBlockReference` come riferimenti/rendering derivati. Ogni collocazione conserva `referenceId` e ID della destinazione; non incorpora nome, Template o FieldValue autorevoli. Copy/paste rigenera `referenceId` mantenendo la destinazione, cut/paste interno verificato può conservarlo e input manipolato non crea Entity o SemanticBlock.

Completato: i nodi inline atomici `entityReference` e `semanticBlockReference` conservano soltanto `referenceId` e l'ID della destinazione. Nome, Template e valori non entrano nel contenuto: l'etichetta mostrata è una decorazione risolta a ogni disegno, così rinominare la destinazione si vede subito e il `document_json` non invecchia. Il validatore rifiuta attributi estranei, ID non canonici e lo stesso `referenceId` due volte; il salvataggio verifica che ogni destinazione esista e fallisce atomicamente altrimenti, senza creare nulla.

Il clipboard segue la stessa politica delle occurrence: il copy/paste rigenera il `referenceId` mantenendo la destinazione, un cut/paste verificato nello stesso Document può conservarlo, e l'impronta del taglio comprende anche le identità dei riferimenti. Un endpoint di risoluzione restituisce le etichette per l'editor e, in futuro, per gli exporter.

Gate soddisfatto: destinazioni validate al salvataggio; copy/paste, reload e persistenza coperti da test end-to-end che verificano gli attributi effettivi del nodo; nessun payload strutturato duplicato nel contenuto, verificato cercando il nome della destinazione nel JSON salvato; entrambi i riferimenti risolvibili dalla stessa API.

## FASE 10.2 — Structured and Combined Search (completata)

Ricercare EntityType, Template, TemplateField e FieldValue sulle colonne tipizzate e combinare full text, Concept, Entity, Context e Tag tramite percorsi espliciti. Il collegamento tra Entity e Document avviene normalmente tramite KnowledgeOccurrence; numeri, boolean, date, misure, valute e reference non vengono confrontati con cast generici a testo.

Completato: i FieldValue si interrogano sulla colonna del proprio tipo, con operatori ammessi per famiglia: confronti numerici su `number_value`, temporali su `date_value`, booleani su `boolean_value`, riferimenti sugli ID. Un operatore fuori tipo viene rifiutato invece di essere convertito, e un confronto numerico con un testo fallisce anziché degradare a stringa. Più filtri si intersecano e ogni risultato dichiara il percorso che lo ha prodotto: `field_value` con Template, campo e operatore, oppure `occurrence` per i Document collegati.

La combinazione con Context e Tag passa dal percorso dichiarato Entity→KnowledgeOccurrence attiva→Document: mai dall'uguaglianza dei nomi. I campi dei Template sono a loro volta cercabili per nome, con il proprio Template accanto. La sidebar espone la ricerca sul Template selezionato, con l'opzione di applicare anche i filtri editoriali attivi.

Gate soddisfatto: query strutturate e combinate con risultati e conteggi ripetuti identici; percorso del match dichiarato in ogni risultato; string matching, identità semantica e payload tipizzati restano distinti, verificato anche in negativo con operatori fuori tipo.

## FASE 11 — KnowledgeRelation (completata)

CRUD di archi diretti Concept↔Concept, Entity↔Entity ed Entity↔Concept, tipi iniziali suggeriti e predicati custom. Co-occurrence, DocumentLink, FieldValue→Concept, Context e Tag non generano automaticamente KnowledgeRelation.

Completato: gli archi diretti collegano Concept↔Concept, Entity↔Entity ed Entity↔Concept in qualsiasi combinazione. La direzione fa parte dell'identità: lo stesso predicato fra la stessa coppia ordinata esiste una volta sola, mentre l'arco inverso è una relazione distinta e coesiste. Ogni capo dichiara il proprio sottotipo, e l'inspector mostra la stessa relazione come uscente da un lato ed entrante dall'altro, con il nome dell'altro oggetto navigabile. I predicati iniziali sono suggerimenti, non un elenco chiuso: un predicato scritto a mano entra fra i suggeriti successivi.

Nulla genera relazioni implicitamente: comparire nello stesso Document, condividere Context o Tag, o essere puntati da un FieldValue non producono archi, verificato con due oggetti che condividono documento, contesto e tag. Un oggetto non si collega a sé stesso e una destinazione inesistente viene rifiutata. Eliminare una relazione non tocca gli oggetti collegati né le loro occurrence. Context e Tag non diventano nodi.

Gate soddisfatto: direzione, sottotipo degli estremi e molteplicità preservati e verificati da entrambi i capi; nessun nodo automatico Context/Tag e nessuna relazione da co-occurrence.

## FASE 12 — Provenance delle relazioni e dei dati (completata)

Associare KnowledgeRelation e dati derivati a evidence verificabili già disponibili: Document, KnowledgeOccurrence, SemanticBlock e FieldValue. Ogni famiglia di destinazione usa un'associazione dedicata, non una FK polimorfica debole. Source e SourceLocator estendono questi percorsi nella FASE 16; non vengono anticipati.

Completato: la migration 010 aggiunge una tabella per famiglia di destinazione — Document, KnowledgeOccurrence, SemanticBlock e FieldValue — ciascuna con foreign key vere. Il soggetto è una KnowledgeRelation oppure un FieldValue, entrambe colonne con FK reale, e un CHECK impone che ne sia valorizzata esattamente una: nessuna coppia generica `target_type`/`target_id` che il database non potrebbe verificare.

Ogni evidence conserva il percorso verso i dati autorevoli: la occurrence porta il Document che la contiene e l'oggetto che dichiara, il blocco e il valore portano l'Entity che li possiede, e ciascuno riporta il proprio stato. Una destinazione inesistente, di famiglia sbagliata o di famiglia non prevista viene rifiutata. L'evidence resiste al normale editing: cancellare il testo stacca la occurrence senza eliminarla, e l'evidence resta navigabile dichiarando lo stato `detached`. Rimuovere una evidence non tocca il dato che indicava. Source e SourceLocator estendono questi percorsi nella FASE 16 e non sono anticipati.

Gate soddisfatto: evidence valide, navigabili e verificate dopo un editing che stacca la occurrence; percorso verso i dati autorevoli conservato in ogni famiglia; destinazioni inesistenti o di tipo errato rifiutate, verificato anche passando un Document dove serve una occurrence.

## FASE 13 — Compare (completata)

Workspace con modalità separate Compare Concepts e Compare Entities. La prima confronta descrizione, Alias, Context derivati, Relation e occurrence; la seconda usa EntityType, EntityIdentifier, Context derivati, Relation, occurrence e Template condivisi per allineare FieldValue. Una modalità mista non viene introdotta senza un caso d'uso esplicito.

Completato: `POST /api/compare` riceve una lista di KnowledgeObject e risponde con le righe allineate. La modalità è derivata dai soggetti, non dichiarata dal client: soggetti di tipo diverso vengono rifiutati con `compare_mixed_mode`, perché un Concept e una Entity non hanno righe confrontabili. Ogni riga dichiara il percorso che l'ha prodotta — `persisted` per i dati propri, `derived` per Context, Relation e occurrence raggiunti tramite Document→KnowledgeOccurrence, `field_value` per i valori allineati per TemplateField — così una cella vuota si distingue da una cella non applicabile.

Le colonne Entity si allineano soltanto sui Template condivisi da tutti i soggetti: il repository seleziona i template posseduti da ciascun soggetto e allinea per TemplateField stabile, non per etichetta. Nessun testo viene generato: ogni cella riporta valori persistiti o l'assenza esplicita.

La UI aggiunge una barra di confronto che accumula i soggetti scelti dall'inspector e li apre affiancati; da lì si svuota la selezione o si toglie un soggetto per volta.

Gate soddisfatto: confronto basato solo sulla conoscenza persistita, colonne Entity allineate per TemplateField stabile, modalità mista rifiutata e nessun testo generato da AI.

## FASE 14 — Matrix e Context views (completata)

Viste `Concept × Context`, `Entity × Context`, `EntityType × Context`, `Template × Context` e filtri FieldValue×Context. Entity, Template e FieldValue raggiungono il Context tramite Document→KnowledgeOccurrence(entity)→Entity→SemanticBlock. Il drill-down mostra Document, occurrence, co-KnowledgeObject e Source quando disponibili.

Completato: `POST /api/matrix` costruisce le quattro matrici sullo stesso scheletro Document→KnowledgeOccurrence, perché il Context raggiunge un KnowledgeObject solo attraverso il contenuto editoriale: EntityType, Template e FieldValue estendono quel percorso, non lo scavalcano. Ogni cella dichiara il percorso che l'ha prodotta — `occurrence`, `occurrence_entity_type`, `semantic_block`, `field_value` — così un numero non arriva mai senza provenance.

Il conteggio è il numero di KnowledgeOccurrence attive distinte, quindi `POST /api/matrix/cell` restituisce esattamente le righe che la cella dichiara. Le modalità `exact` e `subtree` usano lo stesso conteggio di foglia: nel sottoalbero un antenato somma le foglie senza contare due volte la stessa occurrence, e il totale di riga resta il conteggio distinto. I Document senza Context restano in una colonna propria invece di sparire.

Il filtro FieldValue riusa il compilatore tipizzato della ricerca strutturata, estratto in `FieldFilterCompiler` e condiviso con la FASE 10.2: il confronto avviene sulla colonna che la famiglia del campo scrive, mai su un cast a testo. Su un asse Concept il filtro viene rifiutato con `matrix_filter_not_applicable` invece di essere ignorato in silenzio, perché un Concept non ha SemanticBlock. Il drill-down mostra Document, occurrence e co-KnowledgeObject; la Source entra in questo percorso con la FASE 16 e non viene simulata.

Gate soddisfatto: conteggi coerenti fra matrice, drill-down e query strutturate, ogni cella dichiara il percorso che ha prodotto il match, e assi, modalità e filtri non applicabili vengono rifiutati.

## FASE 14.1 — Context come organizzatore di frammenti (completata)

Il Context smette di essere un campo del Document e diventa una ContextOccurrence: un range di testo contiguo che può attraversare più paragrafi, con identità e lifecycle uguali a quelli delle KnowledgeOccurrence. L'uomo prende appunti in punti diversi e riscrive la stessa cosa più volte: rendendo il Document non consapevole, è il frammento ad acquistare significato, e l'indice che l'utente costruisce — Concept, Entity e Context sui frammenti — è ciò che rende comparabili appunti caotici.

Completato: la migration 011 introduce `context_occurrences` e l'appartenenza derivata `context_memberships`, e toglie `documents.context_id`. L'appartenenza di un Concept o di una Entity a un Context è derivata dal contenimento totale del frammento, calcolata al salvataggio dal `document_json` che resta l'unica autorità; un overlap parziale non dichiara nulla. La tabella derivata è interamente ricostruibile e non contiene dati autorevoli.

Il range è contiguo: può attraversare textblock consecutivi entrando in ciascuno dal suo inizio e lasciando il precedente alla sua fine, mentre intervalli disgiunti sotto la stessa identità vengono rifiutati. Togliere il mark stacca la occurrence senza eliminarla, riscriverlo la riattiva. Filtri, ricerca combinata, confronto e matrici passano tutti dall'appartenenza derivata; il selettore «Contesto del documento» sparisce dall'interfaccia insieme al suo endpoint.

Nella stessa fase Concept, Entity e Context diventano davvero CRUD: la cancellazione toglie le occurrence, i mark dal testo e ciò che l'oggetto possedeva, lasciando intatte le parole dell'utente, e viene rifiutata solo quando un FieldValue di un'altra Entity punta lì. Il salvataggio diventa automatico — a pausa nella digitazione e subito dopo ogni comando semantico — e «Associa esistente» cerca insieme Concept, Entity e Context mentre si digita.

Gate soddisfatto: un Context marcato attraversa più paragrafi e sopravvive a salvataggio e reload, il contenimento parziale non produce appartenenza, la cancellazione dei tre organizzatori non tocca il testo, e le query derivate restano coerenti con matrici, confronto e ricerca.

## FASE 15 — Knowledge Map

Valutare Cytoscape.js e Sigma.js, quindi usare una libreria esistente. Consentire viste Concept only, Entity only e Concept+Entity; i nodi KnowledgeObject sono distinti visivamente e gli archi sono KnowledgeRelation. SemanticBlock, TemplateField, FieldValue, Context, Tag e KnowledgeOccurrence non diventano nodi principali; Context e Tag restano filtri/grouping/coloring.

Gate: inspector, provenance e filtri navigabili; occurrence e dati strutturati consultabili senza essere trasformati in nodi principali.

## FASE 16 — Sources

Implementare catalogo Source riutilizzabile e ricercabile, inclusa la vista libri con metadata strutturati, SourceContributor ordinati e SourceIdentifier normalizzati. Source resta distinta da Entity anche quando rappresentano lo stesso libro o paper: l'eventuale collegamento è esplicito. Implementare SourceLocator, DocumentSource, SourceAnchor/SourceCitation e associazioni dedicate e verificabili verso KnowledgeObject, KnowledgeOccurrence, SemanticBlock, FieldValue e KnowledgeRelation. Abilitare il payload FieldValue `source_reference` e la provenance verso Source/SourceLocator.

Gate: la stessa fonte è riusabile senza duplicazione; fonte dell'intero Document e fonte di range sono distinguibili; contributor multipli sono ordinati e identificatori multipli normalizzati/ricercabili; duplicati nella stessa Source sono rifiutati e candidati tra Source diverse non vengono fusi automaticamente; anchor stabile attraverso edit/delete/undo/copy/paste/reload; provenance precisa senza foreign key polimorfiche non verificabili.

## FASE 17 — Immagini e figure

Implementare upload di PNG/JPEG/WebP, storage locale sicuro, record Asset e nodo immagine TipTap inline/block con alt text e proprietà di visualizzazione. Consentire inserimento, spostamento, ridimensionamento previsto dalla UI, copy/paste, delete, undo/redo e reload. Collegamenti verso Document, KnowledgeObject, SemanticBlock, FieldValue, Source e SourceLocator usano associazioni esplicite e verificabili; Asset non diventa Source, Entity o FieldValue.

Gate: nessun base64 nel documento, MIME e limiti verificati lato server, file lifecycle recuperabile, immagini persistenti dopo reload e riferimenti integri e testati.

## FASE 18 — Commenti

Distinguere thread su testo, ancorati tramite struttura/remapping ProseMirror e opzionalmente associati alla KnowledgeOccurrence nel range, da thread su oggetti strutturati riferiti con associazioni dedicate a KnowledgeObject, KnowledgeRelation, SemanticBlock o FieldValue. I commenti su oggetto non simulano un falso range testuale.

Gate: anchor testuale stabile attraverso una matrice di editing; subject strutturati verificati da FK; offset assoluti e target polimorfici deboli non usati come identità.

## FASE 19 — Command Palette

Palette unica per ricerca e azioni già esistenti, incluse creazione/apertura di Concept ed Entity, associazione a selezione, EntityType, EntityIdentifier, Template/SemanticBlock, inserimento dei riferimenti Entity/SemanticBlock, KnowledgeRelation, Source e Compare. Non introduce nuovi modelli di dominio e non duplica la logica applicativa dei comandi.

Gate: accessibilità da tastiera e nessuna duplicazione della logica dei comandi.

## FASE 20 — Ipertesto, indice e formule

Introdurre DocumentAnchor stabili sugli heading, indice navigabile derivato, DocumentLink verso un intero Document o un suo anchor, backlink e formule inline/block. I link usano UUIDv7 e non titoli o slug come identità; DocumentLink resta distinto da KnowledgeRelation e non crea Concept o Entity. La convivenza con un mark `knowledgeOccurrence` non modifica il KnowledgeObject; Entity e SemanticBlock non usano testo, titolo o slug come identità autorevole.

Gate: indice e link restano navigabili dopo rinomina, spostamento, editing, save/reload e undo; copy/paste e cut/paste rispettano le identità; target o anchor mancanti sono diagnosticati senza cascade o riscritture silenziose; formule conservano esattamente il sorgente e sopravvivono al round trip.

## FASE 21 — Document lunghi e note editoriali

Introdurre la gerarchia aciclica di Document con parent, ruolo e ordine, mantenendo possibile il Document monolitico. Implementare split/move strutturale atomico, parti/capitoli/sezioni, DocumentNote a piè di pagina e finali di capitolo/Document, numerazione derivata e preview editoriale non paginata. Lo split trasferisce tutte le KnowledgeOccurrence coinvolte indipendentemente da `objectType`, oltre alle altre identità documentali; SemanticBlock resta Entity-owned e non viene duplicato. In questa fase il body delle note supporta contenuto base, formule e DocumentLink; le citazioni bibliografiche vi entrano solo nella FASE 22.

Gate: un libro monolitico può essere suddiviso in Document figli senza cambiare KnowledgeOccurrence, anchor, SourceCitation o link e senza duplicare SemanticBlock; cicli e ordini duplicati sono rifiutati; rollback non lascia trasferimenti parziali; footnote/endnote resistono a edit, reorder, delete, undo, copy/paste, save/reload e aggregazione del libro.

## FASE 22 — Reference manager e bibliografia

Costruire il reference manager locale sopra Source: CRUD e ricerca avanzata, contributor e SourceIdentifier, import/deduplicazione assistita, BibliographicCitation multi-item con locator, scelta dello stile e bibliografia derivata. SourceIdentifier (DOI, ISBN, PMID) resta distinto da EntityIdentifier (ticker, CIK, LEI o identificatore clinico). Un paper/libro rappresentato sia come Source sia come Entity usa un collegamento esplicito e non fonde i lifecycle. Le citazioni sono abilitate nel contenuto principale e nelle DocumentNote; provenance di range, provenance di dati e citazione visibile restano distinte.

Gate: cambiare stile rigenera citazioni e bibliografia senza alterare Source; citazioni multi-fonte e locator sono coerenti; delete non distrugge fonti condivise; merge richiede conferma ed è transazionale; bibliografia include correttamente fonti citate ed eventuali fonti aggiunte esplicitamente.

## FASE 22.1 — Provider / Autocomplete Layer

Introdurre in futuro un registry di Provider configurabili e mapping verso TemplateField per autocomplete di Entity, EntityIdentifier, FieldValue e metadata Source e per valorizzazioni multi-field. Ogni proposta conserva `origin`, `provider_id`, `retrieved_at` e Source/SourceLocator quando disponibile. Il core resta offline e funzionante senza provider; adapter e dipendenze devono essere gratuiti e verificati per licenza.

Gate: nessun valore manuale viene sovrascritto silenziosamente; ogni import è una proposta confermabile con provenance; provider differenti condividono il contratto generico senza nuove tabelle di dominio per adapter; indisponibilità di rete non compromette il core.

## FASE 23 — Export documentale

Implementare export derivato dal `document_json`, dai body delle note e dai record necessari verso HTML, DOCX, OpenDocument Text (`.odt`) e LaTeX (`.tex`), senza servizi a pagamento. Definire una mappatura esplicita per struttura, gerarchia, indice, formule, immagini, note, citazioni, bibliografia, KnowledgeOccurrence di entrambi i tipi, riferimenti Entity/SemanticBlock introdotti nella FASE 10.1.1, FieldValue, KnowledgeRelation, provenance e DocumentLink. SemanticBlock può essere reso come tabella/sezione o degradato con diagnostica, mai letto da `plain_text`.

Gate: una fixture rappresentativa comprendente libro gerarchico, dati Concept/Entity e strutturati, indice, formule, immagini, note, citazioni e bibliografia viene esportata nei quattro formati senza mutare i dati; ogni perdita o degradazione è dichiarata e testata, mai silenziosa.

## FASE 24 — Learning Layer

Question, Flashcard e ReviewItem possono derivare da Concept, Entity, KnowledgeOccurrence, SemanticBlock, FieldValue, KnowledgeRelation, Source e SourceLocator e conservano associazioni di provenance verificabili.

Gate: nessun elemento didattico scollegato dalle fonti di conoscenza che lo hanno originato; derivazioni multi-oggetto mantengono tutti gli estremi dichiarati.

## FASE 25 — AI Layer

Solo suggerimenti confermabili: Concept, Entity, EntityType, ConceptAlias, EntityIdentifier, Template, FieldValue, KnowledgeRelation, collegamento FieldValue→Concept, sintesi, confronti e field mancanti. Gli output usano `origin = ai_suggested`, conservano provenance e non sovrascrivono valori manuali. Il core resta pienamente funzionante senza provider AI.

Gate: nessuna struttura permanente creata senza conferma esplicita; output con provenance.

## FASE 26 — AI Context Builder

Introdurre il confine `KnowledgeRepository → ContextBuilder → LLM Provider`. Il builder seleziona Concept, Entity, KnowledgeOccurrence, EntityIdentifier, SemanticBlock, FieldValue, Context, Tag, KnowledgeRelation, Source e provenance in base al caso d'uso: Explain Concept, Analyze Entity, Compare Concepts/Entities, Review SemanticBlock o Generate Flashcards. Non passa indiscriminatamente l'intero database.

Gate: il provider non interroga direttamente il database e gli output importanti dichiarano le evidenze usate.

## Dipendenze vincolanti tra fasi

```text
Phase 1.1 → Highlight → KnowledgeOccurrence Concept/Entity
→ invarianti editor → sincronizzazione → Inspectors → Document lifecycle

Entity + EntityType → EntityIdentifier

Template System → SemanticBlock → FieldValue
→ Entity/SemanticBlock references → Structured Search → Entity Compare / Matrix

KnowledgeRelation → provenance Relation → Knowledge Map

Sources → source_reference → provenance FieldValue
Sources + Template System + Reference Manager → Provider Layer
```

Una fase può esporre soltanto i dati già introdotti. Inspector, ricerca, compare, provenance ed export si ampliano incrementalmente senza anticipare ownership o tabelle delle fasi successive.

## Milestone prioritario

Le FASI 1–6 costituiscono il primo milestone reale. Non si procede a mappe, learning layer o AI finché questa sequenza non è robusta:

```text
crea Document → scrivi rich text → crea Concept o Entity da selezione
→ modifica mantenendo la KnowledgeOccurrence → crea seconda occurrence
→ ispeziona entrambi i sottotipi → cancella una manifestazione senza perdere il KnowledgeObject
→ salva → reload coerente
```

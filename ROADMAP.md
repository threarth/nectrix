# Roadmap

## Regola di avanzamento

Si implementa una fase per volta. Una fase può iniziare soltanto quando:

1. il criterio di uscita della fase precedente è soddisfatto;
2. build e test applicabili sono verdi;
3. le decisioni nuove sono riflesse nei documenti architetturali;
4. ogni bug noto che viola un'invariante è risolto o dichiarato blocco.

Le feature delle fasi successive non vengono anticipate “per comodità”. È ammesso predisporre nel modello soltanto quanto serve a non chiudere possibilità già richieste, senza esporre funzionalità premature.

## FASE 0 — Architettura (completata)

Deliverable:

- README e documenti di architettura, dominio, invarianti, roadmap e regole operative;
- decisione sul lifecycle delle occurrence;
- separazione formale Concept/Context/Tag;
- verifica degli scenari del modello;
- decisioni risolutive per i problemi architetturali individuati.

Gate di uscita:

- nessun codice applicativo o dipendenza introdotta;
- tutte le entità richieste definite;
- identità in editing, delete, undo/redo, copy/paste, cut/paste, serialization e reload specificata;
- query `Concept × Context × Tag` spiegabile tramite relazioni del modello;
- ambiguità bloccanti risolte prima dello schema.

## FASE 1 — Bootstrap minimale

Creare struttura frontend/API/data/tests/docs, editor TipTap base, API PHP minimale e schema SQLite iniziale. Implementare soltanto CRUD minimo della Note: creazione, elenco, apertura, titolo, contenuto, salvataggio e reload.

Prima di codificare:

- definire JSON schema/allowlist del documento;
- scegliere runner di test PHP senza introdurre framework applicativi.

Decisioni già vincolanti dalla FASE 0: UUIDv7 per tutte le entità, `Note.revision` obbligatoria, optimistic concurrency e policy di conflitto definite in `ARCHITECTURE.md`.

Gate: una nota con paragraph, heading, bold, italic, underline, liste, blockquote e history sopravvive esattamente a save/reload; test e build verdi.

## FASE 2 — Highlight normale

Introdurre solo il mark visuale di highlight. Testare editing interno, delete parziale/totale, undo/redo, copy/paste e reload.

Gate: comportamento stabile e nessuna scrittura nelle tabelle Concept/Occurrence.

## FASE 3 — Concept e Occurrence

Introdurre `conceptOccurrence`, creazione Concept da selezione e associazione a Concept esistente cercato per canonical name/Alias. Ogni associazione crea un nuovo ID Occurrence.

Gate: creazione atomica, persistenza e rendering coerenti; nessuna promozione automatica ad Alias.

## FASE 4 — Invarianti delle occurrence

Implementare e testare editing interno, delete parziale/totale, orphaning, undo/redo, copy/paste e policy cut/paste documentata in `ARCHITECTURE.md`.

Gate: tutte le invarianti `INV-OCC-*` applicabili coperte da test verdi.

## FASE 5 — Sincronizzazione DB ↔ documento

Implementare estrazione, validazione e riconciliazione transazionale degli ID, stati `active`/`detached`/`deleted`, optimistic concurrency e diagnostica delle inconsistenze.

Gate: salvataggi ripetuti idempotenti; conflitti e documenti corrotti non producono modifiche parziali o cancellazioni definitive.

## FASE 6 — Concept Inspector

Mostrare proprietà del Concept e occurrence correnti estratte dai documenti, raggruppate con Note e Context. Il click naviga alla occurrence tramite ID.

Gate: nessuna copia obsoleta del testo; navigazione affidabile dopo editing e reload.

## FASE 7 — Alias

CRUD Alias e ricerca Concept tramite canonical name o Alias. La modifica degli Alias non modifica le occurrence.

Gate: ambiguità degli Alias gestita mostrando i Concept distinti; invarianti Alias verdi.

## FASE 8 — Context

CRUD di Context e sub-context a profondità arbitraria, breadcrumb, move aciclico di interi rami, assegnazione di un Context principale alla Note, filtro `exact`/`subtree` e raggruppamento occurrence per Context. La cancellazione di nodi con figli o Note richiede riassegnazione esplicita.

Gate: lo stesso Concept compare in più Context senza duplicazione; query ricorsive, move, anti-ciclo, filtro subtree e cancellazione prudente coperti da test verdi.

## FASE 9 — Tag

CRUD e assegnazione/rimozione Tag, ricerca e filtro, sempre separati da Concept e Context.

Gate: query singole e combinate `Concept × Context × Tag` corrette, incluso il caso di nomi uguali.

## FASE 10 — Ricerca globale

FTS5 sul plain text e risultati categorizzati per Text, Concepts, Aliases/Occurrences, Contexts, Tags e Relations. Distinguere string matching da Concept matching.

Gate: ricerca Alias raggiunge il Concept anche senza match nel canonical name; indici ricostruibili dal dato autorevole.

## FASE 11 — Relations

CRUD di archi diretti tra Concept, tipi iniziali suggeriti e predicati custom.

Gate: direzione e molteplicità preservate; nessun nodo automatico Context/Tag.

## FASE 12 — Provenance delle relazioni

Associare Relation a occurrence evidence e navigare al passaggio originario.

Gate: evidence valida, navigabile e resistente al normale editing dell'occurrence.

## FASE 13 — Compare

Workspace per confrontare 2–4 Concept per proprietà, Context, Note, Relation e occurrence.

Gate: confronto basato solo sulla conoscenza persistita, senza testo generato da AI.

## FASE 14 — Concept × Context

Vista matriciale e drill-down delle celle verso occurrence, Note, co-Concept e Source disponibili.

Gate: conteggi e filtri coerenti con le query strutturate.

## FASE 15 — Knowledge Map

Valutare Cytoscape.js e Sigma.js, quindi usare una libreria esistente. Nodi Concept, archi Relation; Context e Tag solo come filtri/grouping/coloring.

Gate: inspector, provenance e filtri navigabili; occurrence non trasformate in nodi principali.

## FASE 16 — Sources

Implementare catalogo Source riutilizzabile e ricercabile, inclusa la vista libri con titolo, autori, anno, ISBN e metadata. Il dialog “Aggiungi fonte” permette di scegliere una fonte già presente oppure crearla. Implementare SourceLocator, NoteSource per l'intera nota, SourceAnchor/SourceCitation per parti di testo e associazioni tipizzate verso Concept, Occurrence e Relation.

Gate: la stessa fonte è riusabile senza duplicazione; fonti di nota e di range sono distinguibili; anchor stabile attraverso edit/delete/undo/copy/paste/reload; provenance precisa senza foreign key polimorfiche non verificabili.

## FASE 17 — Immagini e figure

Implementare upload di PNG/JPEG/WebP, storage locale sicuro, record Asset e nodo immagine TipTap inline/block con alt text e proprietà di visualizzazione. Consentire inserimento, spostamento, ridimensionamento previsto dalla UI, copy/paste, delete, undo/redo e reload. Collegamenti a Source, Note, Concept e occurrence restano associazioni esplicite.

Gate: nessun base64 nel documento, MIME e limiti verificati lato server, file lifecycle recuperabile, immagini persistenti dopo reload e riferimenti integri e testati.

## FASE 18 — Commenti

Thread ancorati a range tramite struttura/remapping ProseMirror, con resolve, reopen e jump.

Gate: anchor stabile attraverso una matrice di editing; offset assoluti non usati come unica identità.

## FASE 19 — Command Palette

Palette unica per ricerca e azioni già esistenti. Non introduce nuovi modelli di dominio.

Gate: accessibilità da tastiera e nessuna duplicazione della logica dei comandi.

## FASE 20 — Learning Layer

Question, Flashcard e ReviewItem con provenance verso il knowledge layer.

Gate: nessun elemento didattico scollegato dalle fonti di conoscenza che lo hanno originato.

## FASE 21 — AI Layer

Solo suggerimenti confermabili: Concept, Alias, Relation, sintesi, confronti, domande e lacune. Il core resta pienamente funzionante senza provider AI.

Gate: nessuna struttura permanente creata senza conferma esplicita; output con provenance.

## FASE 22 — AI Context Builder

Introdurre il confine `KnowledgeRepository → ContextBuilder → LLM Provider`, con input ispezionabile e riferimenti conservabili.

Gate: il provider non interroga direttamente il database e gli output importanti dichiarano le evidenze usate.

## Milestone prioritario

Le FASI 1–6 costituiscono il primo milestone reale. Non si procede a mappe, learning layer o AI finché questa sequenza non è robusta:

```text
crea Note → scrivi rich text → crea Concept da selezione
→ modifica mantenendo l'Occurrence → crea seconda Occurrence
→ ispeziona entrambe → cancella una senza perdere l'altra o il Concept
→ salva → reload coerente
```

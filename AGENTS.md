# Istruzioni per agenti e collaboratori

Queste regole si applicano all'intero repository.

## Scopo corrente

Leggere `ROADMAP.md` prima di modificare il progetto e lavorare esclusivamente sulla fase richiesta. Non anticipare feature di fasi successive. Se la richiesta non indica una fase, determinare la fase corrente dai deliverable presenti e chiedere conferma prima di oltrepassarne il gate.

## Ordine di lettura

Prima di interventi architetturali o applicativi leggere integralmente:

1. `README.md`;
2. `DOMAIN_MODEL.md`;
3. `INVARIANTS.md`;
4. `ARCHITECTURE.md`;
5. la sezione pertinente di `ROADMAP.md`.

Le invarianti sono requisiti, non suggerimenti.

## Regole di implementazione

- Analizzare lo stato del repository e le modifiche non committate prima di editare.
- Fare il cambiamento minimo che completa la fase corrente.
- Mantenere frontend Svelte/Vite/TypeScript/TipTap e backend PHP/SQLite semplici; niente framework pesanti senza motivazione documentata.
- Non introdurre Docker, Elasticsearch, vector DB, graph database, autenticazione complessa, microservizi o AI nelle fasi iniziali.
- Non costruire astrazioni per funzionalità non ancora richieste.
- Trattare `document_json` come fonte autorevole del contenuto e `plain_text` come derivato.
- Non salvare il testo o offset assoluti come identità autorevole di un'Occurrence.
- Non fondere Concept, Context e Tag in una tabella o astrazione semantica comune.
- Non creare Alias dal testo di una occurrence senza comando esplicito.
- Non cancellare automaticamente un Concept quando perde l'ultima occurrence.
- Non correggere silenziosamente inconsistenze tra documento e database: validare, fallire atomicamente e conservare i dati per il recupero.

## Modifiche al dominio

Ogni cambiamento a Concept, ConceptAlias, Occurrence o al mark `conceptOccurrence` deve aggiornare nello stesso intervento:

- `DOMAIN_MODEL.md`;
- `ARCHITECTURE.md` se cambia ownership o sincronizzazione;
- `INVARIANTS.md`;
- test delle invarianti interessate.

Ogni cambiamento alla distinzione Concept/Context/Tag richiede una decisione architetturale esplicita. La somiglianza dei nomi non è una ragione valida per unificarli.

Ogni cambiamento a Document, gerarchia editoriale, DocumentNote o al nodo `documentNoteReference` deve aggiornare `DOMAIN_MODEL.md`, `ARCHITECTURE.md`, `INVARIANTS.md` e i test applicabili. Uno split/move tra Document deve essere atomico e preservare tutte le identità trasferite; non può essere simulato con un normale copy/paste.

Ogni cambiamento a Source, SourceContributor, SourceIdentifier, SourceLocator, SourceAnchor, SourceCitation, BibliographicCitation, BibliographySettings o Asset deve preservare la distinzione tra catalogo bibliografico, provenance, citazione visibile, ancoraggio editoriale e file binario e aggiornare `DOMAIN_MODEL.md`, `ARCHITECTURE.md`, `INVARIANTS.md` e i test applicabili.

Ogni cambiamento a DocumentAnchor, DocumentLink, al mark `documentLink`, agli heading ancorati o alle formule deve aggiornare `DOMAIN_MODEL.md`, `ARCHITECTURE.md`, `INVARIANTS.md` e i test applicabili. Un DocumentLink resta distinto da una Relation tra Concept; titolo, slug, testo e offset non diventano identità autorevoli della destinazione.

Ogni exporter deve leggere `document_json`, `DocumentNote.body_json` e i record semantici/bibliografici necessari, dichiarare le degradazioni e rispettare `INV-EXP-*`; non può usare `plain_text` come sostituto del contenuto né introdurre servizi di conversione a pagamento.

Le ambiguità che possono cambiare identità, lifecycle, cancellazione o significato dei dati devono essere segnalate prima di codificare. Proporre una soluzione e descriverne i trade-off.

## Editor e occurrence

Qualsiasi intervento sull'editor deve considerare almeno:

- modifica interna e ai bordi del mark;
- cancellazione parziale e totale;
- undo/redo;
- copy/paste;
- cut/paste verificato e ambiguo;
- serializzazione e parsing;
- save/reload;
- frammentazione in text node contigui;
- input manipolato o ID duplicati.

Ogni bug editoriale deve avere un test regressivo. Non affidarsi al solo test manuale per il lifecycle delle occurrence.

## Test e gate

- Scrivere test per ogni invariante introdotta o modificata.
- Eseguire test e build applicabili alla fine di ogni fase.
- Non dichiarare completata una fase con test o build falliti.
- Se un comando non può essere eseguito nell'ambiente, documentare esattamente cosa resta da verificare.
- Preferire test unitari vicini alla trasformazione, test di integrazione per transazioni/riconciliazione e test end-to-end per i flussi utente critici.

## Dipendenze e dati

- Il codice originale di Nectrix è distribuito con licenza `AGPL-3.0-or-later`. I nuovi file sorgente devono riportare `SPDX-License-Identifier: AGPL-3.0-or-later` quando il formato consente commenti.
- Aggiungere una dipendenza solo quando riduce concretamente rischio o complessità della fase corrente.
- Usare esclusivamente dipendenze e servizi gratuiti, senza canoni, abbonamenti, licenze o funzionalità che richiedano pagamento. Un eventuale cambio di policy richiede prima una decisione esplicita del proprietario e l'aggiornamento di `THIRD_PARTY_LICENSES.md`; non può essere implicito nell'aggiunta di una dipendenza.
- Prima di aggiungere o aggiornare una dipendenza, verificare la licenza alla fonte ufficiale, accertarne la compatibilità con il progetto e aggiornare `THIRD_PARTY_LICENSES.md` nello stesso intervento.
- Indicare nella documentazione di consegna le licenze delle dipendenze introdotte o aggiornate e segnalare obblighi di attribuzione, distribuzione o pubblicazione del codice.
- Non confondere un componente open source gratuito con servizi cloud, estensioni, piani hosted o feature commerciali offerti dallo stesso fornitore.
- Usare Composer solo se offre un beneficio dimostrabile; non è un requisito del bootstrap.
- Abilitare le foreign key SQLite su ogni connessione.
- Usare transazioni per modifiche che coinvolgono documento e record semantici.
- Le migrazioni devono essere incrementali e testabili; non modificare database utente distruttivamente senza strategia di migrazione/backup.
- Non incorporare immagini base64 nel documento; non fidarsi di MIME type, estensione o filename forniti dal client.

## Documentazione della consegna

Alla fine di un intervento indicare:

- fase e criterio di uscita affrontati;
- file modificati;
- decisioni architetturali prese;
- test/build eseguiti e risultato;
- rischi o decisioni differite, senza implementare in anticipo la soluzione.

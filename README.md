# Nectrix

Nectrix è un sistema personale di organizzazione della conoscenza centrato su testo libero e struttura progressiva:

> Scrivere normalmente → strutturare solo quando serve → rendere tutto ricercabile e collegabile.

Il progetto distingue quattro ruoli indipendenti:

- **Concept**: conoscenza astratta, di che cosa si parla;
- **Entity**: cosa specifica sulla quale si raccolgono dati strutturati;
- **Context**: nell'ambito di che cosa lo si studia o usa;
- **Tag**: come si vuole classificare o gestire il materiale.

Concept ed Entity sono i due soli sottotipi paralleli di KnowledgeObject. Context e Tag restano esterni a questa gerarchia. La stessa stringa può essere il nome di un Concept, una Entity, un Context e un Tag senza creare collegamenti impliciti.

I Context formano gerarchie di profondità arbitraria. L'unità editoriale è il **Document**: può essere un testo autonomo oppure un contenitore gerarchico per un libro composto da parti e capitoli. La roadmap include note a piè di pagina e finali, indice navigabile, formule, collegamenti ipertestuali, immagini locali sicure, un reference manager con citazioni e bibliografia ed export HTML, DOCX, ODT e LaTeX.

## Stato del progetto

La **FASE 1 — Bootstrap minimale**, la **Phase 1.1 — Domain Model Extension and Alignment**, la **FASE 2 — Highlight normale**, la **FASE 3 — KnowledgeObject e Semantic Occurrences** la **FASE 4 — Invarianti delle KnowledgeOccurrence** la **FASE 5 — Sincronizzazione DB ↔ documento** e la **FASE 6 — Inspectors e popover** e la **FASE 6.1 — Lifecycle e cancellazione dei Document** e la **FASE 7 — ConceptAlias ed EntityIdentifier** sono completate. Il repository contiene editor Svelte/TipTap, API PHP/SQLite, Highlight visuale persistente, flussi atomici per creare o associare KnowledgeOccurrence di Concept ed Entity le regole di identità delle occurrence in editing, cancellazione, undo/redo, copy/paste, cut/paste verificato e input manipolato, la riconciliazione transazionale fra documento e database con stati `active`, `detached` e `deleted`, gli inspector di Concept ed Entity con alias, identificatori e archiviazione esplicita, e il lifecycle non distruttivo dei Document con archive, cestino e purge di manutenzione. La prossima fase prevista è la FASE 8 — Context.

I documenti normativi sono:

- [ARCHITECTURE.md](ARCHITECTURE.md): confini, componenti, persistenza e sincronizzazione;
- [DECISIONS.md](DECISIONS.md): decisioni architetturali adottate e decisioni programmate con fase limite;
- [DOMAIN_MODEL.md](DOMAIN_MODEL.md): entità, attributi, relazioni e regole di dominio;
- [INVARIANTS.md](INVARIANTS.md): proprietà che ogni implementazione deve preservare;
- [ROADMAP.md](ROADMAP.md): fasi, gate e criteri di completamento;
- [BACKLOG.md](BACKLOG.md): punto di ripresa e prossimo lavoro, subordinato alla roadmap;
- [AGENTS.md](AGENTS.md): regole operative per chi modifica il repository;
- [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md): policy economica e inventario verificato delle licenze di terze parti.

In caso di conflitto, le invarianti di dominio hanno precedenza sulle scorciatoie implementative. Ogni cambiamento a KnowledgeObject, KnowledgeOccurrence o dati strutturati richiede un aggiornamento contestuale di `INVARIANTS.md`.

## Stack pianificato

- frontend: Svelte, Vite, TypeScript, TipTap/ProseMirror;
- API: PHP 8.x, JSON REST minimale;
- persistenza: SQLite e SQLite FTS5;
- test: Vitest, test PHP equivalenti a PHPUnit e, in una fase successiva, Playwright.

Le dipendenze devono essere introdotte solo nella fase che le richiede.

Il progetto usa esclusivamente componenti e servizi gratuiti. Ogni dipendenza effettivamente introdotta deve avere una licenza verificata e deve essere registrata in `THIRD_PARTY_LICENSES.md`; servizi cloud, estensioni e funzionalità a pagamento non sono ammessi senza autorizzazione esplicita del proprietario del progetto.

## Licenza

Nectrix è software libero distribuito secondo la [GNU Affero General Public License, versione 3 o successiva](LICENSE) (`AGPL-3.0-or-later`). Chi distribuisce una versione modificata o la rende disponibile agli utenti attraverso una rete deve offrire anche il relativo codice sorgente secondo i termini della licenza.

## Avvio locale

Requisiti: Node.js compatibile con Vite 8, PHP 8.2 o successivo con `pdo_sqlite`, e SQLite 3.37 o successivo. L'estensione `mbstring` non è richiesta ma è consigliata: senza, la normalizzazione degli identificatori è case-insensitive solo sui caratteri ASCII.

```bash
npm install
npm run dev:api
```

In un secondo terminale:

```bash
npm run dev
```

L'interfaccia è disponibile su `http://127.0.0.1:5173`; Vite inoltra `/api` a `http://127.0.0.1:8080`. Il database applicativo viene creato in `data/nectrix.sqlite` ed è escluso da Git. Il percorso può essere sostituito con la variabile `NECTRIX_DB_PATH` per test o installazioni locali differenti.

Il purge fisico di un Document è manutenzione esplicita, non un comando dell'interfaccia. Mostra sempre prima l'impatto e agisce solo con `--apply`, su un Document nel cestino, scrivendo un backup:

```bash
php api/bin/purge-document.php --id=<uuid>
php api/bin/purge-document.php --id=<uuid> --apply
```

Comandi di verifica:

```bash
npm run check
npm test
npm run build
npm run licenses
```

## API della FASE 1

- `GET /api/health` — stato dell'API;
- `GET /api/documents` — elenco dei Document;
- `POST /api/documents` — creazione;
- `GET /api/documents/{uuid}` — apertura;
- `PUT /api/documents/{uuid}` — salvataggio con `baseRevision`.

La cancellazione non appartiene alla FASE 1. Lo schema editoriale accettato è documentato in [docs/DOCUMENT_SCHEMA_V1.md](docs/DOCUMENT_SCHEMA_V1.md).

## Primo milestone applicativo

Il primo milestone termina quando un Document rich-text può contenere KnowledgeOccurrence persistenti di Concept ed Entity e le loro identità e discriminator restano coerenti durante modifica, cancellazione, undo/redo, copia/incolla, salvataggio e reload. Mappe, AI, flashcard e altre funzioni avanzate restano fuori ambito fino a quel momento.

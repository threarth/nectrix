# Nectrix

Nectrix è un sistema personale di organizzazione della conoscenza centrato su testo libero e struttura progressiva:

> Scrivere normalmente → strutturare solo quando serve → rendere tutto ricercabile e collegabile.

Il progetto distingue tre dimensioni indipendenti:

- **Concept**: di che cosa si parla;
- **Context**: nell'ambito di che cosa lo si studia o usa;
- **Tag**: come si vuole classificare o gestire il materiale.

La stessa stringa può essere il nome di un Concept, di un Context e di un Tag senza creare alcuna identità condivisa tra le tre entità.

I Context formano gerarchie di profondità arbitraria. La roadmap include inoltre un catalogo riutilizzabile di fonti collegabili a intere note o a range testuali e immagini inline conservate come asset locali sicuri.

## Stato del progetto

La **FASE 0 — Architettura** è completata. Il repository non contiene ancora frontend, backend o schema eseguibile; la prossima fase prevista è il bootstrap minimale.

I documenti normativi sono:

- [ARCHITECTURE.md](ARCHITECTURE.md): confini, componenti, persistenza e sincronizzazione;
- [DOMAIN_MODEL.md](DOMAIN_MODEL.md): entità, attributi, relazioni e regole di dominio;
- [INVARIANTS.md](INVARIANTS.md): proprietà che ogni implementazione deve preservare;
- [ROADMAP.md](ROADMAP.md): fasi, gate e criteri di completamento;
- [AGENTS.md](AGENTS.md): regole operative per chi modifica il repository.

In caso di conflitto, le invarianti di dominio hanno precedenza sulle scorciatoie implementative. Ogni cambiamento al modello Concept/Occurrence richiede un aggiornamento contestuale di `INVARIANTS.md`.

## Stack pianificato

- frontend: Svelte, Vite, TypeScript, TipTap/ProseMirror;
- API: PHP 8.x, JSON REST minimale;
- persistenza: SQLite e SQLite FTS5;
- test: Vitest, test PHP equivalenti a PHPUnit e, in una fase successiva, Playwright.

Le dipendenze devono essere introdotte solo nella fase che le richiede.

## Primo milestone applicativo

Il primo milestone termina quando una nota rich-text può contenere più occurrence persistenti dello stesso Concept e le loro identità restano coerenti durante modifica, cancellazione, undo/redo, copia/incolla, salvataggio e reload. Mappe, AI, flashcard e altre funzioni avanzate restano fuori ambito fino a quel momento.

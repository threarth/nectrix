# Backlog

Questo file riassume il punto di ripresa. `ROADMAP.md`, `INVARIANTS.md` e i documenti architetturali restano normativi in caso di divergenza.

## Stato salvato

- FASE 0 — Architettura: completata.
- FASE 1 — Bootstrap minimale: completata.
- Phase 1.1 — Domain Model Extension and Alignment: completata.
- FASE 2 — Highlight normale: completata.
- FASE 3 — KnowledgeObject e Semantic Occurrences: completata.
- FASE 4 — Invarianti delle KnowledgeOccurrence: completata.
- Frontend Svelte/Vite/TypeScript con editor TipTap base.
- API PHP minimale e database SQLite in `data/nectrix.sqlite`.
- Flussi della fase limitati a creazione, elenco, apertura e aggiornamento dei Document; cancellazione non ancora prevista.
- Migration additiva per KnowledgeObject, Concept, Entity/EntityType, KnowledgeOccurrence, KnowledgeRelation, Template, SemanticBlock e FieldValue tipizzati; nessuna API/UI semantica anticipata.
- Test backend, round trip TipTap, type-check, build, controllo licenze e audit dipendenze verdi alla chiusura della Phase 1.1.

## Prossimo lavoro autorizzabile

### FASE 5 — Sincronizzazione DB ↔ documento

Implementare estrazione, validazione e riconciliazione transazionale di tutte le KnowledgeOccurrence al salvataggio. La FASE 4 ha già consegnato l'estrazione e i rifiuti strutturali; restano da fare gli stati e la riconciliazione.

Prima del gate devono essere verificati con test regressivi:

- passaggio ad `detached` degli ID prima attivi e ora assenti, senza rimozione fisica;
- riattivazione ad `active` di un record `detached` il cui mark torna dopo un undo;
- idempotenza di salvataggi ripetuti dello stesso contenuto;
- transizione ad `orphan` dei soli Concept che perdono l'ultima occurrence, mentre le Entity restano `active`;
- optimistic concurrency e documenti corrotti senza modifiche parziali o cancellazioni definitive.

Gate: salvataggi ripetuti idempotenti; conflitti e documenti corrotti non producono modifiche parziali o cancellazioni definitive.

## Decisioni

Il registro unico è `DECISIONS.md`. Non risultano decisioni programmate che blocchino la FASE 5.

Sono già stabiliti, senza anticiparne l'implementazione:

- KnowledgeObject e KnowledgeOccurrence di Concept/Entity iniziano soltanto dopo il gate della FASE 2;
- lifecycle e cancellazione non distruttiva dei Document appartengono alla FASE 6.1;
- i test end-to-end in browser reale diventano obbligatori dalla FASE 3;
- riferimenti editoriali a Entity e SemanticBlock appartengono alla FASE 10.1.1;
- packaging e notice distribuibili vengono chiusi con la FASE 23.

## Comandi di ripresa

```bash
npm install
npm run dev:api
npm run dev
```

Verifica completa:

```bash
npm run check
npm test
npm run build
npm run licenses
npm audit
```

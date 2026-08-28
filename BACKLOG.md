# Backlog

Questo file riassume il punto di ripresa. `ROADMAP.md`, `INVARIANTS.md` e i documenti architetturali restano normativi in caso di divergenza.

## Stato salvato

- FASE 0 — Architettura: completata.
- FASE 1 — Bootstrap minimale: completata.
- Phase 1.1 — Domain Model Extension and Alignment: completata.
- FASE 2 — Highlight normale: completata.
- FASE 3 — KnowledgeObject e Semantic Occurrences: completata.
- FASE 4 — Invarianti delle KnowledgeOccurrence: completata.
- FASE 5 — Sincronizzazione DB ↔ documento: completata.
- FASE 6 — Inspectors e popover: completata.
- FASE 6.1 — Lifecycle e cancellazione dei Document: completata.
- FASE 7 — ConceptAlias ed EntityIdentifier: completata.
- FASE 8 — Context: completata.
- Frontend Svelte/Vite/TypeScript con editor TipTap base.
- API PHP minimale e database SQLite in `data/nectrix.sqlite`.
- Flussi della fase limitati a creazione, elenco, apertura e aggiornamento dei Document; cancellazione non ancora prevista.
- Migration additiva per KnowledgeObject, Concept, Entity/EntityType, KnowledgeOccurrence, KnowledgeRelation, Template, SemanticBlock e FieldValue tipizzati; nessuna API/UI semantica anticipata.
- Test backend, round trip TipTap, type-check, build, controllo licenze e audit dipendenze verdi alla chiusura della Phase 1.1.

## Prossimo lavoro autorizzabile

### FASE 9 — Tag

CRUD e assegnazione dei Tag ai Document, ricerca e filtro, sempre separati da Concept, Entity, EntityType, Template e FieldValue.

Prima del gate devono essere verificati con test regressivi:

- query singole e combinate KnowledgeObject × Context × Tag;
- filtro dei KnowledgeObject derivato tramite Document e KnowledgeOccurrence, senza assegnazioni dirette;
- nomi uguali nelle dimensioni distinte che restano cose diverse.

Gate: query singole e combinate `KnowledgeObject × Context × Tag` corrette per Concept ed Entity, incluso il caso di nomi uguali nelle dimensioni distinte.

## Decisioni

Il registro unico è `DECISIONS.md`. Non risultano decisioni programmate che blocchino la FASE 9.

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

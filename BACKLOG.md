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
- Frontend Svelte/Vite/TypeScript con editor TipTap base.
- API PHP minimale e database SQLite in `data/nectrix.sqlite`.
- Flussi della fase limitati a creazione, elenco, apertura e aggiornamento dei Document; cancellazione non ancora prevista.
- Migration additiva per KnowledgeObject, Concept, Entity/EntityType, KnowledgeOccurrence, KnowledgeRelation, Template, SemanticBlock e FieldValue tipizzati; nessuna API/UI semantica anticipata.
- Test backend, round trip TipTap, type-check, build, controllo licenze e audit dipendenze verdi alla chiusura della Phase 1.1.

## Prossimo lavoro autorizzabile

### FASE 6.1 — Lifecycle e cancellazione dei Document

Introdurre `Document.status = active|archived|trashed` con archive, trash e restore non distruttivi. Gli archiviati sono in sola lettura e ricercabili con scope esplicito, i trashed compaiono solo nella vista di recupero. Il purge fisico resta un comando di manutenzione separato con preview, backup e verifica dei riferimenti.

Prima del gate devono essere verificati con test regressivi:

- archive, trash e restore che conservano contenuto, associazioni e stato delle KnowledgeOccurrence;
- nessuna cascade verso KnowledgeObject o dati strutturati;
- purge bloccato in presenza di riferimenti non gestiti, con rollback completo su errore.

Gate: archive/trash/restore coperti da test; nessuna cascade verso KnowledgeObject o dati strutturati; purge bloccato in presenza di riferimenti non gestiti e rollback completo su errore.

## Decisioni

Il registro unico è `DECISIONS.md`. Non risultano decisioni programmate che blocchino la FASE 6.1.

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

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
- FASE 9 — Tag: completata.
- FASE 10 — Full text e semantic search: completata.
- FASE 10.1 — Template System: completata.
- FASE 10.1.1 — Riferimenti editoriali a Entity e SemanticBlock: completata.
- FASE 10.2 — Structured and Combined Search: completata.
- FASE 11 — KnowledgeRelation: completata.
- FASE 12 — Provenance delle relazioni e dei dati: completata.
- Frontend Svelte/Vite/TypeScript con editor TipTap base.
- API PHP minimale e database SQLite in `data/chaorganix.sqlite`.
- Flussi della fase limitati a creazione, elenco, apertura e aggiornamento dei Document; cancellazione non ancora prevista.
- Migration additiva per KnowledgeObject, Concept, Entity/EntityType, KnowledgeOccurrence, KnowledgeRelation, Template, SemanticBlock e FieldValue tipizzati; nessuna API/UI semantica anticipata.
- Test backend, round trip TipTap, type-check, build, controllo licenze e audit dipendenze verdi alla chiusura della Phase 1.1.

## Prossimo lavoro autorizzabile

### FASE 15 — Knowledge Map

Mappa navigabile dei KnowledgeObject: nodi Concept ed Entity distinti visivamente, archi KnowledgeRelation, viste Concept only, Entity only e Concept+Entity. Context e Tag restano filtri, grouping e coloring, non nodi.

ADR-P15-01 e' chiusa: la mappa usa Cytoscape.js (MIT), come registrato in ADR-015.

Prima del gate devono essere verificati con test regressivi:

- inspector, provenance e filtri navigabili dalla mappa;
- SemanticBlock, TemplateField, FieldValue, Context, Tag e KnowledgeOccurrence consultabili senza diventare nodi principali.

Gate: inspector, provenance e filtri navigabili; occurrence e dati strutturati consultabili senza essere trasformati in nodi principali.

### Documenti di prova su psicanalisi

Verso la fine delle fasi, costruire alcuni documenti reali come banco di prova dell'indice: topiche di Freud, Io ed Es, il Sé in Jung, il Sé in Freud, il Sé in Lacan. Devono usare tutti e tre gli organizzatori — Concept, Entity e Context sui frammenti — e servire a verificare mappe, matrici e confronti su appunti scritti come li scriverebbe una persona, sparsi e ripetuti.

Vincolo permanente: i documenti creati nel database di sviluppo non vengono mai eliminati.

## Decisioni

Il registro unico è `DECISIONS.md`. ADR-P15-01 è stata chiusa da ADR-015 (Cytoscape.js); la prima decisione ancora aperta è ADR-P16-01, dovuta prima della FASE 16.

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

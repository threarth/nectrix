# Backlog

Questo file riassume il punto di ripresa. `ROADMAP.md`, `INVARIANTS.md` e i documenti architetturali restano normativi in caso di divergenza.

## Stato salvato

- FASE 0 — Architettura: completata.
- FASE 1 — Bootstrap minimale: completata.
- Frontend Svelte/Vite/TypeScript con editor TipTap base.
- API PHP minimale e database SQLite in `data/nectrix.sqlite`.
- Flussi della fase limitati a creazione, elenco, apertura e aggiornamento dei Document; cancellazione non ancora prevista.
- Test backend, round trip TipTap, type-check, build, controllo licenze e audit dipendenze verdi alla chiusura della FASE 1.

## Prossimo lavoro autorizzabile

### FASE 2 — Highlight normale

Introdurre esclusivamente un mark visuale di highlight, senza creare o modificare Concept e Occurrence.

Prima del gate devono essere verificati con test regressivi:

- modifica interna e ai bordi del mark;
- cancellazione parziale e totale;
- undo/redo;
- copy/paste e cut/paste;
- serializzazione, salvataggio e reload;
- assenza di scritture semantiche nelle future tabelle Concept/Occurrence.

Gate: comportamento stabile e nessuna scrittura nelle tabelle Concept/Occurrence.

## Decisioni differite

- FASE 3 e successive: Concept e Occurrence, solo dopo il gate della FASE 2.
- Cancellazione dei Document: non appartiene alla FASE 1 e richiede una fase/decisione esplicita.
- Test end-to-end in browser reale: da introdurre quando richiesto dai flussi critici della roadmap; la FASE 1 usa test TipTap in jsdom e collaudo HTTP.
- Packaging distribuibile e raccolta dei notice delle dipendenze: prima di una distribuzione; l'inventario corrente è in `THIRD_PARTY_LICENSES.md`.

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

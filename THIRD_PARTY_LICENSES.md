# Licenze di terze parti

## Policy

Nectrix rispetta le condizioni di tutte le licenze applicabili e usa esclusivamente dipendenze e servizi gratuiti. Non sono ammessi canoni, abbonamenti, licenze o piani hosted che richiedano pagamento, né funzionalità a pagamento. Un eventuale cambio di questa policy richiede prima una decisione esplicita del proprietario e l'aggiornamento di questo documento; non può essere implicito nell'aggiunta di una dipendenza.

Prima di introdurre o aggiornare una dipendenza occorre:

1. verificarne la licenza tramite una fonte ufficiale;
2. verificare gli obblighi di attribuzione e distribuzione e la compatibilità con il progetto;
3. registrare qui nome, versione, utilizzo, licenza e fonte;
4. comunicare nella consegna la licenza introdotta o modificata e i relativi obblighi.

La gratuità del componente open source non implica che servizi cloud, hosting, estensioni o altre offerte dello stesso fornitore siano gratuiti. Tali offerte non devono essere abilitate o integrate automaticamente.

## Inventario corrente — dipendenze dirette

Le versioni sono fissate esattamente in `package.json` e `package-lock.json`. Nessun pacchetto PHP/Composer è installato.

| Componente | Versione | Ambito | Licenza | Fonte ufficiale | Costo |
| --- | --- | --- | --- | --- | --- |
| Svelte | 5.56.10 | runtime frontend | MIT | [sveltejs/svelte](https://github.com/sveltejs/svelte) | gratuito |
| TipTap Core | 3.30.3 | runtime editor | MIT | [ueberdosis/tiptap](https://github.com/ueberdosis/tiptap) | gratuito; nessun servizio Cloud/Platform |
| TipTap ProseMirror packages | 3.30.3 | runtime editor | MIT | [ueberdosis/tiptap](https://github.com/ueberdosis/tiptap) | gratuito |
| TipTap StarterKit | 3.30.3 | runtime editor | MIT | [ueberdosis/tiptap](https://github.com/ueberdosis/tiptap) | gratuito; sole estensioni OSS |
| Vite | 8.2.2 | sviluppo/build | MIT | [vitejs/vite](https://github.com/vitejs/vite) | gratuito |
| Svelte plugin for Vite | 7.3.0 | sviluppo/build | MIT | [sveltejs/vite-plugin-svelte](https://github.com/sveltejs/vite-plugin-svelte) | gratuito |
| TypeScript | 6.0.3 | sviluppo/type-check | Apache-2.0 | [microsoft/TypeScript](https://github.com/microsoft/TypeScript) | gratuito |
| svelte-check | 4.7.6 | sviluppo/type-check | MIT | [sveltejs/language-tools](https://github.com/sveltejs/language-tools) | gratuito |
| Vitest | 4.1.11 | test frontend | MIT | [vitest-dev/vitest](https://github.com/vitest-dev/vitest) | gratuito |
| jsdom | 30.0.1 | ambiente test DOM | MIT | [jsdom/jsdom](https://github.com/jsdom/jsdom) | gratuito |

## Dipendenze transitive

`package-lock.json` registra versione e licenza di ogni pacchetto transitivo. Il controllo `npm run licenses` fallisce se incontra una licenza mancante o fuori dall'allowlist verificata. Al completamento della FASE 1 il lockfile contiene 176 record transitivi e opzionali di piattaforma:

| Licenza SPDX | Record | Obbligo rilevante |
| --- | ---: | --- |
| MIT | 147 | conservare copyright e testo della licenza nelle redistribuzioni |
| Apache-2.0 | 6 | conservare licenza e notice applicabili; clausole brevetti |
| ISC | 3 | conservare copyright e testo della licenza |
| BSD-2-Clause | 2 | conservare copyright e condizioni |
| BSD-3-Clause | 2 | conservare copyright e condizioni; niente endorsement |
| MIT-0 | 2 | licenza permissiva senza requisito di attribuzione |
| MPL-2.0 | 12 | copyleft a livello di file per eventuali modifiche ai file MPL distribuiti |
| BlueOak-1.0.0 | 1 | licenza permissiva |
| CC0-1.0 | 1 | dedizione al pubblico dominio/permesso fallback |

Non risultano licenze mancanti, componenti a pagamento o pacchetti TipTap Pro. I file di licenza originali restano disponibili nei rispettivi pacchetti installati; un futuro packaging distribuibile dovrà includere i notice richiesti.

// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Visible text of the editor commands. Labels, accessible names and explanations live here so they
 * can be revised or translated without touching the components.
 */
export interface CommandStrings {
  /** Text shown on the control. */
  label: string
  /** Accessible name, when the label alone is not descriptive. */
  ariaLabel?: string
  /** Explanation shown on hover, said in terms of what the command does to the document. */
  description: string
}

export const editorStrings = {
  toolbarLabel: 'Formattazione documento',

  paragraph: {
    label: 'Normale',
    description: 'Rende il blocco un paragrafo normale (Ctrl+Alt+0)',
  },

  heading: (level: number): CommandStrings => ({
    label: `H${level}`,
    ariaLabel: `Titolo ${level}`,
    description: `Rende il blocco un titolo di livello ${level} (Ctrl+Alt+${level})`,
  }),

  bold: { label: 'G', ariaLabel: 'Grassetto', description: 'Grassetto sul testo selezionato (Ctrl+B)' },
  italic: { label: 'C', ariaLabel: 'Corsivo', description: 'Corsivo sul testo selezionato (Ctrl+I)' },
  underline: { label: 'S', ariaLabel: 'Sottolineato', description: 'Sottolineato sul testo selezionato (Ctrl+U)' },

  highlight: {
    label: 'Evidenzia',
    description: 'Evidenzia il testo selezionato. È solo visuale: non crea né associa Concept o Entity',
  },
  palette: {
    label: 'Palette',
    description: 'Scegli quanti e quali colori di evidenziazione usare, salvati solo su questo dispositivo',
  },

  createConcept: {
    label: 'Crea Concept',
    description: 'Crea un nuovo Concept dal testo selezionato e lo associa a questo punto del documento',
  },
  createEntity: {
    label: 'Crea Entity',
    description: 'Crea una nuova Entity, con il suo EntityType, dal testo selezionato e la associa a questo punto',
  },
  attachExisting: {
    label: 'Associa esistente',
    description: 'Cerca un Concept o una Entity già esistenti e li associa al testo selezionato',
  },

  bulletList: { label: 'Elenco •', description: 'Elenco puntato (Ctrl+Shift+8)' },
  orderedList: { label: 'Elenco 1.', description: 'Elenco numerato (Ctrl+Shift+7)' },
  blockquote: { label: 'Citazione', description: 'Blocco citazione (Ctrl+Shift+B)' },

  undo: { label: 'Annulla', description: 'Annulla l’ultima modifica (Ctrl+Z)' },
  redo: { label: 'Ripeti', description: 'Ripete la modifica annullata (Ctrl+Y)' },
} as const

export const highlightPopoverStrings = {
  dialogLabel: 'Opzioni evidenziazione',
  colorLabel: 'Colore',
  colorsGroupLabel: 'Scegli colore highlight',
  colorDescription: (color: string): string => `Usa highlight ${color}`,
  remove: { label: 'Rimuovi', description: 'Toglie l’evidenziazione da questo intervallo' },
} as const

export const occurrencePopoverStrings = {
  dialogLabel: 'Opzioni del testo selezionato',
  open: {
    label: (objectType: 'concept' | 'entity'): string =>
      objectType === 'concept' ? 'Apri Concept' : 'Apri Entity',
    description: 'Apre l’inspector del Concept o della Entity associati a questo intervallo',
  },
} as const

export const palettePanelStrings = {
  panelLabel: 'Configura palette Highlight',
  sizeLabel: 'Colori',
  sizeDescription: 'Quanti colori di evidenziazione mostrare nel popover',
  colorsLabel: 'Modifica colori palette',
  colorDescription: (index: number): string => `Modifica colore ${index}`,
  reset: { label: 'Ripristina predefiniti', description: 'Riporta la palette ai colori iniziali' },
} as const

export const inspectorStrings = {
  panelLabel: 'Dettaglio del KnowledgeObject',
  kind: (objectType: 'concept' | 'entity'): string => (objectType === 'concept' ? 'Concept' : 'Entity'),
  close: { label: '×', ariaLabel: 'Chiudi il pannello', description: 'Chiude il pannello e torna al solo documento' },
  loading: 'Caricamento…',

  statusLabel: 'Stato',
  objectStatus: {
    active: 'attivo',
    orphan: 'senza occurrence attive',
    archived: 'archiviato',
  } as Record<string, string>,

  description: { label: 'Descrizione', empty: 'Nessuna descrizione.' },
  entityType: { label: 'EntityType', archived: 'archiviato' },

  occurrences: {
    label: 'Occurrence',
    empty: 'Nessuna occurrence registrata.',
    /** The text is read from the Document, so a detached occurrence simply has none. */
    missingText: 'Testo non più presente nel documento',
    description: 'Apre il Document e porta al punto dell’occurrence',
    status: {
      active: 'attiva',
      detached: 'staccata',
      deleted: 'eliminata',
    } as Record<string, string>,
  },

  archive: {
    label: 'Archivia',
    description: 'Archivia senza cancellare nulla: occurrence e dati restano al loro posto',
  },
  restore: {
    label: 'Ripristina',
    description: 'Riporta in uso l’oggetto archiviato',
  },
  archiveEntityType: {
    label: 'Archivia EntityType',
    description: 'Archivia il tipo: resta valido per le Entity esistenti e non può essere usato per nuove Entity',
  },
  restoreEntityType: {
    label: 'Ripristina EntityType',
    description: 'Rende di nuovo disponibile il tipo per nuove Entity',
  },
} as const

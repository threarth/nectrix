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
  selectionGroupLabel: 'Comandi sul testo selezionato',

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
    description: 'Evidenzia il testo selezionato. È solo visuale: non crea né associa Concept o Entity. Un Concept o una Entity hanno già la propria evidenziazione e non si evidenziano',
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
  remove: {
    label: 'Elimina',
    description: 'Elimina Concept o Entity, le sue occurrence e i mark nel testo: le parole restano',
    confirm: (name: string, occurrences: number): string =>
      `Elimino «${name}»?${occurrences === 0 ? '' : ` Toglie ${occurrences === 1 ? 'una marcatura' : `${occurrences} marcature`} dal testo; le parole restano.`}`,
  },
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
  edit: {
    label: 'Modifica',
    description: 'Cambia nome e descrizione. Occurrence, alias e identificatori restano invariati',
    nameLabel: 'Nome',
    descriptionLabel: 'Descrizione',
    save: 'Salva',
    cancel: 'Annulla',
  },
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

export const documentStrings = {
  scopeLabel: 'Documenti da mostrare',
  scopes: [
    { value: 'active', label: 'Attivi', description: 'I documenti su cui stai lavorando' },
    { value: 'archived', label: 'Archiviati', description: 'Documenti conservati in sola lettura, fuori dalle liste normali' },
    { value: 'trashed', label: 'Cestino', description: 'Vista di recupero: nulla viene eliminato finché non lo chiedi esplicitamente' },
  ] as const,

  readOnly: 'Sola lettura',
  readOnlyHint: {
    archived: 'Documento archiviato: ripristinalo per modificarlo.',
    trashed: 'Documento nel cestino: ripristinalo per modificarlo.',
  } as Record<string, string>,

  archive: { label: 'Archivia', description: 'Conserva il documento in sola lettura, senza eliminare nulla' },
  trash: { label: 'Cestina', description: 'Sposta il documento nella vista di recupero, senza eliminare nulla' },
  restore: { label: 'Ripristina', description: 'Riporta il documento fra quelli attivi e modificabili' },

  emptyScope: {
    active: 'Non ci sono ancora documenti.',
    archived: 'Nessun documento archiviato.',
    trashed: 'Il cestino è vuoto.',
  } as Record<string, string>,
} as const

export const dialogStrings = {
  cancel: 'Annulla',
  working: 'Attendi…',
} as const

export const conceptDialogStrings = {
  title: 'Nuovo Concept',
  hint: 'Il testo selezionato diventa una occurrence del Concept, senza essere modificato.',
  nameLabel: 'Nome del Concept',
  namePlaceholder: 'Come si chiama questa idea',
  note: 'Il nome del Concept può essere diverso dal testo selezionato.',
  confirm: 'Crea Concept',
} as const

export const entityDialogStrings = {
  title: 'Nuova Entity',
  hint: 'Il testo selezionato diventa una occurrence della Entity, senza essere modificato.',
  nameLabel: 'Nome della Entity',
  namePlaceholder: 'La cosa specifica di cui parli',
  typeLabel: 'EntityType',
  newType: 'Nuovo tipo…',
  newTypeLabel: 'Nome del nuovo EntityType',
  newTypePlaceholder: 'Per esempio Azienda, Farmaco, Libro',
  note: 'Un tipo esistente viene riusato; un nome nuovo crea il tipo.',
  archivedNote: (count: number): string =>
    count === 1
      ? ' Un tipo archiviato non è elencato: ripristinalo dall’inspector per riusarlo.'
      : ` ${count} tipi archiviati non sono elencati: ripristinali dall’inspector per riusarli.`,
  confirm: 'Crea Entity',
} as const

export const attachDialogStrings = {
  title: 'Associa un Concept o una Entity esistenti',
  hint: 'Il testo selezionato diventa una nuova occurrence dell’oggetto scelto.',
  queryLabel: 'Cerca',
  queryPlaceholder: 'Nome o alias',
  search: 'Cerca',
  searching: 'Ricerca…',
  resultsLabel: 'Risultati della ricerca',
  empty: 'Nessun Concept o Entity trovato.',
  failure: 'Ricerca non disponibile.',
  note: 'Lo stesso nome può appartenere a Concept distinti: scegli quello giusto.',
  kind: (objectType: 'concept' | 'entity'): string => (objectType === 'concept' ? 'Concept' : 'Entity'),
  confirm: 'Associa',
} as const

export const aliasStrings = {
  label: 'Alias',
  empty: 'Nessun alias.',
  note: 'Un alias è un altro nome dello stesso Concept. Lo stesso alias può appartenere a Concept diversi.',
  placeholder: 'Altro nome di questo Concept',
  add: { label: 'Aggiungi alias', description: 'Aggiunge un nome alternativo senza toccare il testo dei documenti' },
  remove: { label: '×', description: 'Rimuove l’alias, senza toccare occurrence o documenti' },
} as const

export const identifierStrings = {
  label: 'Identificatori',
  empty: 'Nessun identificatore.',
  note: 'Un identificatore è strutturato: scheme, valore e, quando serve, authority. Non è un alias.',
  schemePlaceholder: 'scheme, per esempio ticker',
  valuePlaceholder: 'valore',
  authorityPlaceholder: 'authority, per esempio NASDAQ',
  normalizedLabel: 'normalizzato',
  add: { label: 'Aggiungi identificatore', description: 'Registra un identificatore strutturato di questa Entity' },
  remove: { label: '×', description: 'Rimuove l’identificatore' },
  duplicates: (names: string[]): string =>
    names.length === 1
      ? `Stessa identità già dichiarata da: ${names[0]}. Nessuna fusione automatica: verifica se sono la stessa cosa.`
      : `Stessa identità già dichiarata da: ${names.join(', ')}. Nessuna fusione automatica: verifica se sono la stessa cosa.`,
} as const

export const occurrenceHandleStrings = {
  start: 'Sposta l’inizio dell’occurrence',
  end: 'Sposta la fine dell’occurrence',
  description: 'Trascina per correggere il confine, mantenendo lo stesso Concept o la stessa Entity',
} as const

export const contextStrings = {
  panelLabel: 'Contesti',
  all: { label: 'Tutti', description: 'Mostra i documenti di qualsiasi contesto' },
  none: 'Nessun contesto',
  empty: 'Nessun contesto: creane uno per organizzare i documenti.',
  modeLabel: 'Come filtrare',
  modes: [
    { value: 'subtree', label: 'Con i sotto-contesti', description: 'Include i documenti dei contesti discendenti' },
    { value: 'exact', label: 'Solo questo', description: 'Solo i documenti assegnati esattamente a questo contesto' },
  ] as const,

  create: {
    label: 'Nuovo',
    description: 'Crea un contesto sotto quello selezionato, o alla radice se non ne hai scelto uno',
    dialogTitle: 'Nuovo contesto',
    rootHint: 'Nasce alla radice, perché non hai selezionato nessun contesto.',
    childHint: (parent: string): string => `Nasce dentro «${parent}».`,
    nameLabel: 'Nome del contesto',
    placeholder: 'Per esempio Università, Lavoro, Tesi',
  },
  rename: {
    label: 'Rinomina',
    description: 'Cambia il nome del contesto selezionato',
    dialogTitle: 'Rinomina contesto',
  },
  move: { label: 'Sposta', description: 'Sposta il contesto selezionato, con tutto il suo ramo, sotto un altro' },
  remove: {
    label: 'Elimina',
    description: 'Elimina il contesto e toglie le sue marcature dal testo, senza cambiare una parola',
    /** Explains the refusal next to the command, before attempting it. */
    blocked: (children: number): string =>
      `Non eliminabile: ha ${children === 1 ? 'un sotto-contesto' : `${children} sotto-contesti`}. ` +
      `${children === 1 ? 'Spostalo o eliminalo' : 'Spostali o eliminali'} prima.`,
    /** What the deletion will take away, said before it happens. */
    impact: (ranges: number): string =>
      ranges === 0
        ? 'Nessun frammento marcato: elimina solo il contesto.'
        : `Toglie ${ranges === 1 ? '1 frammento marcato' : `${ranges} frammenti marcati`} dal testo, senza cancellare parole.`,
    confirm: (name: string, ranges: number): string =>
      `Elimino «${name}»?${ranges === 0 ? '' : ` Toglie ${ranges === 1 ? 'una marcatura' : `${ranges} marcature`} dal testo; le parole restano.`}`,
  },
  moveToRoot: 'Alla radice',
  documentCount: (documents: number): string =>
    documents === 1 ? '1 documento' : `${documents} documenti`,

  rangeCount: (ranges: number): string => (ranges === 1 ? '1 frammento' : `${ranges} frammenti`),

  derived: {
    label: 'Concept ed Entity qui',
    empty: 'Nessun Concept o Entity nei documenti filtrati.',
    description: 'Ricavati dai documenti selezionati attraverso le loro occurrence, non assegnati direttamente a contesti o tag',
  },
} as const

export const tagStrings = {
  panelLabel: 'Tag',
  empty: 'Nessun tag: creane uno per classificare i documenti.',
  nameLabel: 'Nome del tag',
  placeholder: 'Senza cancelletto, per esempio da rileggere',
  filterDescription: 'Filtra i documenti che hanno questo tag; il numero indica quanti sono',
  allRequired: 'Con più tag selezionati compaiono solo i documenti che li hanno tutti.',

  create: {
    label: 'Nuovo',
    description: 'Crea un tag. Un tag resta separato da Concept, Entity ed EntityType',
    dialogTitle: 'Nuovo tag',
    hint: 'Un tag classifica i documenti e non crea conoscenza: non diventa un Concept.',
  },
  rename: {
    label: 'Rinomina',
    description: 'Cambia il nome del tag selezionato',
    dialogTitle: 'Rinomina tag',
  },
  remove: {
    label: 'Elimina',
    description: 'Possibile solo se il tag non è assegnato a nessun documento',
    blocked: (documents: number): string =>
      documents === 1
        ? 'Non eliminabile: è su un documento. Toglilo prima da lì.'
        : `Non eliminabile: è su ${documents} documenti. Toglilo prima da lì.`,
  },

  documentLabel: 'Tag del documento',
  addToDocument: 'Aggiungi tag',
  removeFromDocument: 'Togli il tag dal documento',
  none: 'Nessun tag',
} as const

export const searchStrings = {
  panelLabel: 'Ricerca',
  placeholder: 'Cerca testo, Concept, Entity, contesti, tag',
  submit: { label: 'Cerca', description: 'Cerca in tutte le dimensioni e mostra da dove arriva ogni risultato' },
  clear: { label: 'Pulisci', description: 'Torna all’elenco dei documenti' },
  searching: 'Ricerca…',
  empty: 'Nessun risultato.',
  resultsLabel: 'Risultati',

  categories: {
    document: 'Documento',
    concept: 'Concept',
    entity: 'Entity',
    entity_type: 'EntityType',
    context: 'Contesto',
    tag: 'Tag',
  } as Record<string, string>,

  /** Says whether the match came from words or from a declared identity. */
  matches: {
    full_text: 'testo',
    name: 'nome',
    alias: 'alias',
    identifier: 'identificatore',
    identity: 'occurrence',
  } as Record<string, string>,

  occurrencesOf: {
    label: 'Dove compare',
    description: 'Documenti che contengono una occurrence attiva di questo oggetto, indipendentemente dalle parole scritte',
  },
} as const

export const structuredStrings = {
  label: 'Dati strutturati',
  description: 'Appartengono alla Entity, non al documento: non sono evidenziazioni né occurrence',
  empty: 'Nessun blocco: applica un Template per raccogliere dati.',
  addBlock: 'Applica un Template',
  removeBlock: { label: '×', description: 'Rimuove il blocco e i suoi valori da questa Entity' },
  noTemplates: 'Nessun Template disponibile: creane uno.',
  newTemplate: { label: 'Nuovo Template', dialogTitle: 'Nuovo Template', nameLabel: 'Nome del Template', placeholder: 'Per esempio Scheda azienda' },
  addField: { label: 'Aggiungi campo', dialogTitle: 'Nuovo campo', nameLabel: 'Nome del campo', placeholder: 'Per esempio Settore' },
  required: 'obbligatorio',
  emptyValue: 'vuoto',
  unitLabel: 'unità',
  currencyLabel: 'valuta',
  unsupported: 'Questo tipo si modifica dalla UI dedicata, prevista da una fase successiva.',

  fieldTypes: [
    { value: 'text', label: 'Testo' },
    { value: 'number', label: 'Numero' },
    { value: 'boolean', label: 'Sì/No' },
    { value: 'date', label: 'Data' },
    { value: 'enum', label: 'Scelta singola' },
    { value: 'multi_enum', label: 'Scelta multipla' },
    { value: 'measurement', label: 'Misura' },
    { value: 'currency', label: 'Importo' },
    { value: 'percentage', label: 'Percentuale' },
    { value: 'url', label: 'Collegamento' },
  ] as const,
  optionsLabel: 'Opzioni separate da virgola',
} as const

export const referenceStrings = {
  command: {
    label: 'Riferimento',
    description: 'Inserisce un rimando a una Entity o a un suo blocco di dati: il documento conserva solo l’ID',
  },
  dialogTitle: 'Inserisci un riferimento',
  hint: 'Il documento non copia nome né valori: li mostra risolvendoli dalla destinazione.',
  queryLabel: 'Cerca una Entity',
  queryPlaceholder: 'Nome della Entity',
  search: 'Cerca',
  searching: 'Ricerca…',
  empty: 'Nessuna Entity trovata.',
  blockLabel: 'Riferisci',
  wholeEntity: 'La Entity',
  confirm: 'Inserisci',
  unresolved: 'riferimento non risolto',
  loading: '…',
} as const

export const structuredSearchStrings = {
  label: 'Cerca nei dati',
  description: 'Confronta i valori sul loro tipo: i numeri come numeri, le date come date',
  fieldLabel: 'Campo',
  operatorLabel: 'Confronto',
  valueLabel: 'Valore',
  submit: 'Cerca',
  searching: 'Ricerca…',
  empty: 'Nessuna Entity con questi valori.',
  resultsLabel: 'Entity trovate',
  withFilters: 'Applica anche i filtri di contesto e tag attivi',
  matchedBy: (field: string, operator: string): string => `${field} · ${operator}`,

  operators: {
    eq: 'uguale a',
    contains: 'contiene',
    gt: 'maggiore di',
    gte: 'almeno',
    lt: 'minore di',
    lte: 'al massimo',
    before: 'prima del',
    after: 'dopo il',
    is_true: 'vero',
    is_false: 'falso',
  } as Record<string, string>,

  /** Mirrors the operators the API admits for each family of field types. */
  byType: {
    text: ['eq', 'contains'],
    url: ['eq', 'contains'],
    enum: ['eq', 'contains'],
    multi_enum: ['eq', 'contains'],
    number: ['eq', 'gt', 'gte', 'lt', 'lte'],
    percentage: ['eq', 'gt', 'gte', 'lt', 'lte'],
    measurement: ['eq', 'gt', 'gte', 'lt', 'lte'],
    currency: ['eq', 'gt', 'gte', 'lt', 'lte'],
    boolean: ['is_true', 'is_false'],
    date: ['eq', 'before', 'after'],
    entity_reference: ['eq'],
    concept_reference: ['eq'],
  } as Record<string, string[]>,
} as const

export const relationStrings = {
  label: 'Relazioni',
  description: 'Archi dichiarati da te: comparire nello stesso documento o contesto non crea una relazione',
  empty: 'Nessuna relazione dichiarata.',
  outgoing: '→',
  incoming: '←',
  add: { label: 'Collega', description: 'Dichiara una relazione verso un altro Concept o Entity' },
  remove: { label: '×', description: 'Rimuove la relazione, senza toccare gli oggetti collegati' },

  dialogTitle: 'Nuova relazione',
  hint: 'La direzione fa parte della relazione: l’arco inverso è una relazione diversa.',
  queryLabel: 'Cerca la destinazione',
  queryPlaceholder: 'Nome del Concept o della Entity',
  search: 'Cerca',
  searching: 'Ricerca…',
  empty_results: 'Nessun risultato.',
  predicateLabel: 'Predicato',
  predicatePlaceholder: 'Per esempio riguarda, deriva da',
  confirm: 'Collega',
} as const

export const evidenceStrings = {
  label: 'Su cosa si basa',
  description: 'Dati già presenti che sostengono la relazione: documenti, occurrence, blocchi o valori',
  empty: 'Nessuna evidenza dichiarata.',
  show: { label: 'Evidenze', description: 'Mostra su quali dati si basa questa relazione' },
  addDocument: { label: 'Aggiungi il documento aperto', description: 'Usa il documento aperto come evidenza di questa relazione' },
  remove: { label: '×', description: 'Toglie l’evidenza, senza toccare il dato che indicava' },
  families: {
    document: 'Documento',
    occurrence: 'Occurrence',
    semantic_block: 'Blocco',
    field_value: 'Valore',
  } as Record<string, string>,
  states: {
    active: 'attiva',
    detached: 'staccata',
    deleted: 'eliminata',
    archived: 'archiviato',
    trashed: 'nel cestino',
    manual: 'manuale',
    provider: 'da provider',
    derived: 'derivato',
    ai_suggested: 'proposto',
  } as Record<string, string>,
} as const

export const compareStrings = {
  add: { label: 'Confronta', description: 'Aggiunge questo oggetto al confronto' },
  trayLabel: 'Confronto',
  hint: 'Concept ed Entity si confrontano separatamente.',
  run: { label: 'Apri il confronto', description: 'Mostra i due o più oggetti affiancati' },
  clear: { label: 'Svuota', description: 'Toglie tutti gli oggetti dal confronto' },
  remove: (name: string): string => `Togli ${name} dal confronto`,
  dialogTitle: (mode: string): string => (mode === 'concepts' ? 'Confronto fra Concept' : 'Confronto fra Entity'),
  close: 'Chiudi',
  empty: '—',
  paths: {
    persisted: 'dato registrato',
    derived: 'derivato dalle occurrence',
    field_value: 'valore strutturato',
  } as Record<string, string>,
} as const

export const matrixStrings = {
  open: { label: 'Viste matrice', description: 'Incrocia Concept, Entity, EntityType e Template con i Context' },
  dialogTitle: 'Matrice per Context',
  close: 'Chiudi',
  axisLabel: 'Righe della matrice',
  axes: [
    { value: 'concept', label: 'Concept', description: 'Un Concept per riga' },
    { value: 'entity', label: 'Entity', description: 'Una Entity per riga' },
    { value: 'entity_type', label: 'EntityType', description: 'Un tipo di Entity per riga' },
    { value: 'template', label: 'Template', description: 'Un Template per riga, attraverso i SemanticBlock' },
  ] as const,
  modeLabel: 'Come si contano i Context',
  modes: [
    { value: 'exact', label: 'Solo il Context', description: 'Conta solo i Document assegnati a quel Context' },
    { value: 'subtree', label: 'Con i discendenti', description: 'Somma anche i Document dei Context figli' },
  ] as const,
  filterLabel: 'Filtro sui valori strutturati',
  filterNone: 'Nessun filtro',
  filterUnavailable: 'Il filtro sui FieldValue non si applica a un Concept: un Concept non ha SemanticBlock.',
  noContext: 'Senza Context',
  rowHeader: 'Riga',
  totalHeader: 'Totale',
  empty: 'Nessun dato: la matrice si popola quando le occurrence vivono in Document assegnati a un Context.',
  truncated: (limit: number): string => `Mostrate le prime ${limit} righe per totale.`,
  cellLabel: (row: string, column: string, matches: number): string => `${row} in ${column}: ${matches}`,
  drillTitle: (row: string, column: string): string => `${row} — ${column}`,
  drillEmpty: 'Nessuna occurrence in questa cella.',
  drillClose: 'Chiudi il dettaglio',
  coObjects: 'Insieme a:',
  openDocument: 'Apri il documento',
  sourceLater: 'Le Source compaiono nel drill-down dalla FASE 16.',
  paths: {
    occurrence: 'Document → KnowledgeOccurrence',
    occurrence_entity_type: 'Document → KnowledgeOccurrence → Entity → EntityType',
    semantic_block: 'Document → KnowledgeOccurrence → Entity → SemanticBlock',
    field_value: 'Document → KnowledgeOccurrence → Entity → SemanticBlock → FieldValue',
  } as Record<string, string>,
} as const

export const attachStrings = {
  title: 'Associa a qualcosa che esiste',
  hint: 'Cerca fra Concept, Entity e Context: scrivi e i risultati si aggiornano.',
  confirm: 'Associa',
  queryLabel: 'Cerca',
  queryPlaceholder: 'Nome di Concept, Entity o Context',
  resultsLabel: 'Risultati della ricerca',
  searching: 'Cerco…',
  empty: 'Nessun risultato: prova con meno lettere, oppure crea un Concept, una Entity o un Context.',
  failure: 'Ricerca non riuscita.',
  kind: (objectType: 'concept' | 'entity' | 'context'): string =>
    objectType === 'concept' ? 'Concept' : objectType === 'entity' ? 'Entity' : 'Context',
  note: 'Un Concept o una Entity marcano che cosa è il frammento; un Context dove appartiene.',
} as const

export const contextDialogStrings = {
  title: 'Segna un Context',
  hint: 'Il Context organizza il frammento, non il documento: puoi tracciarlo anche attraverso più paragrafi.',
  confirm: 'Segna',
  listLabel: 'Context disponibili',
  empty: 'Nessun Context: creane uno qui sotto e il frammento lo inaugura.',
  fragment: (text: string): string => `Frammento: «${text.length > 120 ? `${text.slice(0, 120)}…` : text}»`,
  rangeCount: (count: number): string => (count === 1 ? '1 frammento' : `${count} frammenti`),
  newLabel: 'Nuovo Context',
  newPlaceholder: 'Nome del Context',
  create: 'Crea',
  creating: 'Creo…',
  createFailure: 'Context non creato.',
  newRootNote: 'Il nuovo Context nasce alla radice: scegline uno sopra per creargli un figlio.',
  newChildNote: 'Il nuovo Context nasce dentro quello selezionato.',
} as const

export const contextCommandStrings = {
  mark: {
    label: 'Segna Context',
    description: 'Traccia un Context attorno al testo selezionato, anche attraverso più paragrafi',
  },
  open: { label: 'Apri Context', description: 'Mostra il Context che contiene questo frammento' },
  remove: { label: 'Togli Context', description: 'Toglie questo frammento dal Context, senza toccare il testo' },
} as const

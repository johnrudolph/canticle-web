export interface CardComment {
    id: number;
    commentable_type: string;
    commentable_id: number;
    kind: 'comment' | 'deletion';
    body: string;
    resolved_at: string | null;
    resolved_note: string | null;
    created_at: string;
}

/** A row from any of the 4 card tables, loosely typed since each has its own column set. */
export interface CardRecord {
    id: number;
    key: string;
    name: string;
    print: boolean;
    deleted_at: string | null;
    comments: CardComment[];
    [column: string]: unknown;
}

export interface ColumnDef {
    key: string;
    label: string;
    type: 'text' | 'textarea' | 'boolean' | 'select' | 'multiselect';
    /** Show a filter dropdown over this column's distinct values (list/table view only). */
    filterable?: boolean;
    /** Show this column in the list table too (not just the edit form) — meant for the one "main text" field per card type, rendered wide. */
    showInTable?: boolean;
    /** Required for 'select'/'multiselect' — the fixed set of valid values. */
    options?: string[];
    /** 'select' only — value a brand-new card starts with, instead of blank. */
    defaultValue?: string;
    /** 'select' only — set false for fields where blank/null is never a valid choice (e.g. Relic type). Default true. */
    allowNone?: boolean;
}

export type CardType = 'location' | 'relic' | 'glyph' | 'golem_card';

/** Relic counts per slot, per type, and the slot x type matrix (see RelicController::buildRelicSummary). */
export interface RelicSummary {
    slots: string[];
    types: string[];
    matrix: Record<string, Record<string, number>>;
}

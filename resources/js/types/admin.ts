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
    /** Value a brand-new card starts with, instead of blank/false — a string for 'select', a boolean for 'boolean'. */
    defaultValue?: string | boolean;
    /** 'select' only — set false for fields where blank/null is never a valid choice (e.g. Relic type). Default true. */
    allowNone?: boolean;
}

export type CardType = 'location' | 'relic' | 'glyph' | 'golem_card';

/** Relic counts per slot, per symbol (branch), and the slot x symbol matrix (see RelicController::buildRelicSummary). */
export interface RelicSummary {
    slots: string[];
    columns: string[];
    matrix: Record<string, Record<string, number>>;
}

/** Per-branch Relic count (by symbol) side by side with Location count (by produces) — see LocationController::buildBranchSummary. */
export interface BranchSummary {
    branches: string[];
    emoji: Record<string, string>;
    relicCounts: Record<string, number>;
    locationCounts: Record<string, number>;
}

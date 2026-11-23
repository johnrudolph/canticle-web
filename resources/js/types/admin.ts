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
    type: 'text' | 'textarea' | 'boolean';
    /** Show a filter dropdown over this column's distinct values. */
    filterable?: boolean;
}

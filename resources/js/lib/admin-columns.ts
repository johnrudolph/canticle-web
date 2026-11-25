import type { ColumnDef } from '@/types/admin';

/** Branches a Location can produce / a Relic can belong to (by symbol). */
export const BRANCHES = [
    'Agriculture',
    'Art',
    'Commerce',
    'Dark',
    'Engineering',
    'Fire',
    'Golem',
    'Law',
    'Magic',
    'Music',
    'Religion',
    'Solidarity',
    'Time',
];

/** Relic `symbol` only ever takes one of these (no Relics in Agriculture/Commerce/Engineering). */
export const RELIC_SYMBOLS = ['⏳', '⚖️', '✨', '🌑', '🎨', '🐾', '📖', '🤝', '🔥', '🎶'];

export const RELIC_SLOTS = ['Body', 'Mind', 'Hand', 'Bag'];

export const RELIC_TYPES = ['Action', 'Passive', 'Event'];

/**
 * slot_emoji/is_action/is_passive/is_event are server-computed from
 * slot/type (see RelicController::beforeSave) — deliberately absent
 * here, never rendered as editable fields.
 */
export function relicColumns(locationNames: string[]): ColumnDef[] {
    return [
        {
            key: 'location',
            label: 'Location',
            type: 'select',
            filterable: true,
            options: locationNames,
        },
        { key: 'slot', label: 'Slot', type: 'select', filterable: true, options: RELIC_SLOTS },
        {
            key: 'type',
            label: 'Type',
            type: 'select',
            filterable: true,
            options: RELIC_TYPES,
            // Every Relic must have a type — Action/Passive/Event, never
            // blank. New Relics start on Action; the "—" none choice is
            // hidden for this field specifically (see allowNone below).
            defaultValue: 'Action',
            allowNone: false,
        },
        {
            key: 'symbol',
            label: 'Symbol',
            type: 'select',
            filterable: true,
            options: RELIC_SYMBOLS,
        },
        { key: 'effect', label: 'Effect', type: 'textarea', showInTable: true },
    ];
}

/**
 * produces_emoji/cost_emoji/has_back_text/has_relics/has_specific_relics/
 * produces_anything/has_cost/relic_names are all server-computed (see
 * LocationController::beforeSave) — deliberately absent here.
 * relic_names specifically is just "which Relics currently point at
 * this Location by name" (Relic.location) — an implied column from the
 * Relics table, never something to type in directly.
 * has_shard/has_tree stay real editable fields — nothing else implies
 * them.
 */
export const LOCATION_COLUMNS: ColumnDef[] = [
    { key: 'lore', label: 'Lore', type: 'textarea' },
    { key: 'rule', label: 'Rule', type: 'textarea', showInTable: true },
    { key: 'costs', label: 'Costs', type: 'multiselect', options: BRANCHES },
    { key: 'produces', label: 'Produces', type: 'select', filterable: true, options: BRANCHES },
    { key: 'relics', label: 'Relics', type: 'text' },
    { key: 'is_starter', label: 'Is starter', type: 'boolean', filterable: true },
    { key: 'is_advanced', label: 'Is advanced', type: 'boolean', filterable: true },
    { key: 'has_shard', label: 'Has shard', type: 'boolean' },
    { key: 'has_tree', label: 'Has tree', type: 'boolean' },
];

/** Same branch universe as RELIC_SYMBOLS, plus 👺 — the malevolent/self-inscription marker with no Relic-branch equivalent. */
export const GLYPH_TYPES = [...RELIC_SYMBOLS, '👺'];

export const GLYPH_COLUMNS: ColumnDef[] = [
    { key: 'type', label: 'Type', type: 'select', filterable: true, options: GLYPH_TYPES },
    { key: 'description', label: 'Description', type: 'textarea', showInTable: true },
];

export const GOLEM_CARD_COLUMNS: ColumnDef[] = [
    { key: 'action', label: 'Action', type: 'textarea', showInTable: true },
];

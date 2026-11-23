import type { ColumnDef } from '@/types/admin';

/** 11 branches a Location can produce / a Relic can belong to (by symbol). */
export const BRANCHES = [
    'Agriculture',
    'Art',
    'Commerce',
    'Dark',
    'Engineering',
    'Golem',
    'Law',
    'Magic',
    'Religion',
    'Solidarity',
    'Time',
];

/** Relic `symbol` only ever takes one of these 8 (no Relics in Agriculture/Commerce/Engineering). */
export const RELIC_SYMBOLS = ['⏳', '⚖️', '✨', '🌑', '🎨', '🐾', '📖', '🤝'];

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
        { key: 'type', label: 'Type', type: 'select', filterable: true, options: RELIC_TYPES },
        {
            key: 'symbol',
            label: 'Symbol',
            type: 'select',
            filterable: true,
            options: RELIC_SYMBOLS,
        },
        { key: 'effect', label: 'Effect', type: 'textarea' },
    ];
}

/**
 * produces_emoji/cost_emoji/has_back_text/has_relics/has_specific_relics/
 * produces_anything/has_cost are server-computed (see
 * LocationController::beforeSave) — deliberately absent here.
 * has_shard/has_tree stay real editable fields — nothing else implies
 * them.
 */
export const LOCATION_COLUMNS: ColumnDef[] = [
    { key: 'lore', label: 'Lore', type: 'textarea' },
    { key: 'rule', label: 'Rule', type: 'textarea' },
    { key: 'costs', label: 'Costs', type: 'multiselect', options: BRANCHES },
    { key: 'produces', label: 'Produces', type: 'select', filterable: true, options: BRANCHES },
    { key: 'relics', label: 'Relics', type: 'text' },
    { key: 'relic_names', label: 'Relic names', type: 'textarea' },
    { key: 'is_starter', label: 'Is starter', type: 'boolean', filterable: true },
    { key: 'has_shard', label: 'Has shard', type: 'boolean' },
    { key: 'has_tree', label: 'Has tree', type: 'boolean' },
];

export const GLYPH_COLUMNS: ColumnDef[] = [
    { key: 'type', label: 'Type', type: 'text', filterable: true },
    { key: 'description', label: 'Description', type: 'textarea' },
];

export const GOLEM_CARD_COLUMNS: ColumnDef[] = [
    { key: 'action', label: 'Action', type: 'textarea' },
];

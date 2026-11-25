import type { ColumnDef } from '@/types/admin';

export const RELIC_SLOTS = ['Body', 'Mind', 'Hand', 'Bag'];

export const RELIC_TYPES = ['Action', 'Passive', 'Event'];

/**
 * slot_emoji/is_action/is_passive/is_event are server-computed from
 * slot/type (see RelicController::beforeSave) — deliberately absent
 * here, never rendered as editable fields.
 *
 * `symbols` comes from the backend (RelicController::extraProps'
 * `relicSymbols`, sourced from App\Models\Cards\Branch) rather than a
 * hardcoded list here — this file used to keep its own copy of the
 * branch taxonomy and it drifted out of sync with the backend's.
 */
export function relicColumns(locationNames: string[], symbols: string[]): ColumnDef[] {
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
            options: symbols,
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
 *
 * `branches` comes from the backend (LocationController::extraProps'
 * `branches`, sourced from App\Models\Cards\Branch) rather than a
 * hardcoded list here — this file used to keep its own copy of the
 * branch taxonomy and it drifted out of sync with the backend's.
 */
export function locationColumns(branches: string[]): ColumnDef[] {
    return [
        { key: 'lore', label: 'Lore', type: 'textarea' },
        { key: 'rule', label: 'Rule', type: 'textarea', showInTable: true },
        { key: 'costs', label: 'Costs', type: 'multiselect', options: branches },
        { key: 'produces', label: 'Produces', type: 'select', filterable: true, options: branches },
        { key: 'relics', label: 'Relics', type: 'text', showInTable: true },
        { key: 'is_starter', label: 'Is starter', type: 'boolean' },
        { key: 'is_advanced', label: 'Is advanced', type: 'boolean' },
        { key: 'has_shard', label: 'Has shard', type: 'boolean' },
        { key: 'has_tree', label: 'Has tree', type: 'boolean' },
    ];
}

/**
 * `types` comes from the backend (GlyphController::extraProps'
 * `glyphTypes`, sourced from App\Models\Cards\Branch plus the 👺
 * malevolent marker) rather than a hardcoded list here — this file used
 * to keep its own copy of the branch taxonomy and it drifted out of
 * sync with the backend's.
 */
export function glyphColumns(types: string[]): ColumnDef[] {
    return [
        { key: 'type', label: 'Type', type: 'select', filterable: true, options: types },
        { key: 'description', label: 'Description', type: 'textarea', showInTable: true },
    ];
}

export const GOLEM_CARD_COLUMNS: ColumnDef[] = [
    { key: 'action', label: 'Action', type: 'textarea', showInTable: true },
];

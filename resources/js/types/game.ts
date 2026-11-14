export type RoleKey =
    | 'wanderer_a'
    | 'wanderer_b'
    | 'wanderer_c'
    | 'emperor'
    | 'golem';

export type EquipSlot = 'body' | 'mind' | 'hand_left' | 'hand_right' | 'bag';

export interface RoleSummary {
    role_key: RoleKey;
    user_name: string | null;
    is_mine: boolean;
}

export type InfrastructureType =
    | 'monolith_shard'
    | 'road'
    | 'walls'
    | 'fruit_tree';

export interface BoardCell {
    location_key: string;
    placed_round: number;
    relics: string[];
    relics_face_up: string[];
    infrastructure: InfrastructureType[];
}

export interface WandererState {
    cell: [number, number] | null;
    hp: number;
    actions_remaining: number;
    equipped: Record<EquipSlot, string | null>;
    holding: string[];
}

export interface GolemCardEntry {
    key: string;
    tapped: boolean;
}

export interface GolemState {
    cell: [number, number] | null;
    shame: number;
    glyphs: string[];
    revealed: GolemCardEntry[];
    draw_pile_count: number;
    discard_pile_count: number;
}

export interface GameStateDto {
    wanderer_count: number;
    round: number;
    phase: 'setup' | 'emperor' | 'wanderer' | 'finished';
    board: Record<string, BoardCell>;
    location_deck_count: number;
    location_hand: string[];
    location_hand_count: number;
    location_discard: string[];
    wanderers: Record<string, WandererState>;
    golem: GolemState;
    pending_setup_wanderers: RoleKey[];
    pending_golem_placement: boolean;
    wanderer_order: RoleKey[];
    active_wanderer_role: RoleKey | null;
    active_wanderer_relic_reasons: Record<string, string | null>;
    actions_per_wanderer_turn: number;
    golem_cards_this_round: number;
    infrastructure_pool: Record<InfrastructureType, number>;
    infrastructure_placed_this_turn: number;
    prophecy: number;
    golem_skipped: boolean;
    outcome: 'elite_victory' | 'simple_victory' | 'defeat' | null;
}

export interface LocationCatalogEntry {
    name: string;
    lore: string;
    back_text: string | null;
    produces: string | null;
    produces_emoji: string | null;
    costs: string[];
    relic_count: number;
}

export interface RelicCatalogEntry {
    name: string;
    effect: string;
    slot: 'Body' | 'Mind' | 'Hand' | 'Bag' | '';
    slot_emoji: string;
    symbol: string;
    location_name: string | null;
    is_event: boolean;
    is_bound: boolean;
}

export interface GolemCardCatalogEntry {
    name: string;
    action: string;
}

export interface GlyphCatalogEntry {
    name: string;
    type: string;
    description: string;
}

export interface GameLogEntry {
    id: number;
    type: string;
    role_key: RoleKey | null;
    payload: Record<string, unknown>;
    created_at: string | null;
}

export interface GameSummary {
    id: number;
    code: string;
    status: 'lobby' | 'active' | 'finished' | 'abandoned';
    wanderer_count: number;
    golem_enabled: boolean;
    is_host: boolean;
    my_roles: RoleKey[];
    roles: RoleSummary[];
    state: GameStateDto | null;
    location_catalog: Record<string, LocationCatalogEntry>;
    relic_catalog: Record<string, RelicCatalogEntry>;
    golem_card_catalog: Record<string, GolemCardCatalogEntry>;
    glyph_catalog: Record<string, GlyphCatalogEntry>;
    events: GameLogEntry[];
}

export interface GamePollResponse {
    status: 'lobby' | 'active' | 'finished' | 'abandoned';
    wanderer_count: number;
    golem_enabled: boolean;
    is_host: boolean;
    my_roles: RoleKey[];
    roles: RoleSummary[];
    state: GameStateDto | null;
    location_catalog: Record<string, LocationCatalogEntry>;
    relic_catalog: Record<string, RelicCatalogEntry>;
    golem_card_catalog: Record<string, GolemCardCatalogEntry>;
    glyph_catalog: Record<string, GlyphCatalogEntry>;
    events: GameLogEntry[];
}

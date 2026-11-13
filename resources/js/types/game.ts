export type RoleKey = 'wanderer_a' | 'wanderer_b' | 'wanderer_c' | 'emperor' | 'golem';

export interface RoleSummary {
    role_key: RoleKey;
    is_ready: boolean;
    user_name: string | null;
    is_mine: boolean;
}

export interface BoardCell {
    location_key: string;
    placed_round: number;
}

export interface WandererState {
    cell: [number, number] | null;
    hp: number;
    actions_remaining: number;
}

export interface GameStateDto {
    wanderer_count: number;
    round: number;
    phase: 'setup' | 'emperor' | 'wanderer' | 'finished';
    board: Record<string, BoardCell>;
    location_deck_count: number;
    location_hand: string[];
    location_discard: string[];
    wanderers: Record<string, WandererState>;
    pending_setup_wanderers: RoleKey[];
    wanderer_order: RoleKey[];
    active_wanderer_role: RoleKey | null;
    actions_per_wanderer_turn: number;
}

export interface LocationCatalogEntry {
    name: string;
    lore: string;
    produces: string | null;
    produces_emoji: string | null;
    costs: string[];
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
    events: GameLogEntry[];
}

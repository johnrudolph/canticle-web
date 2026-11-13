import type { GameLogEntry, RoleKey } from '@/types/game';

function roleLabel(role: RoleKey | null): string {
    if (!role) return 'Game';
    if (role === 'emperor') return 'Emperor';
    if (role === 'golem') return 'Golem';
    return `Wanderer ${role.replace('wanderer_', '').toUpperCase()}`;
}

function describe(
    event: GameLogEntry,
    locationNames: Record<string, string>,
    relicNames: Record<string, string>,
    glyphNames: Record<string, string>,
): string {
    const role = roleLabel(event.role_key);

    switch (event.type) {
        case 'wanderer_started':
            return `${role} started on the board.`;
        case 'golem_placed':
            return `The Golem was placed on the board.`;
        case 'round_started':
            return `Round ${event.payload.round} started.`;
        case 'location_played': {
            const key = event.payload.location_key as string;
            return `${role} built ${locationNames[key] ?? key}.`;
        }
        case 'emperor_phase_ended':
            return `${role} ended their phase.`;
        case 'infrastructure_placed':
            return `${role} placed ${event.payload.infrastructure}.`;
        case 'wanderer_turn_started':
            return `${role}'s turn (${event.payload.actions} actions).`;
        case 'wanderer_moved':
            return event.payload.free ? `${role} moved for free (Road).` : `${role} moved.`;
        case 'fruit_tree_bonus_action':
            return `${role} gains an extra action (Fruit tree).`;
        case 'location_searched': {
            const found = event.payload.found as string | null;
            if (!found) return `${role} searched and found nothing.`;
            if (event.payload.trashed_as_event) {
                return `${role} found ${relicNames[found] ?? found} — an Event, resolved and trashed.`;
            }
            return `${role} found ${relicNames[found] ?? found}.`;
        }
        case 'relic_equipped':
            return `${role} equipped ${relicNames[event.payload.relic_key as string] ?? event.payload.relic_key}.`;
        case 'relic_dropped':
            return `${role} dropped ${relicNames[event.payload.relic_key as string] ?? event.payload.relic_key}.`;
        case 'relic_picked_up':
            return `${role} picked up ${relicNames[event.payload.relic_key as string] ?? event.payload.relic_key}.`;
        case 'relic_used':
            return `${role} uses ${(event.payload.name as string) ?? event.payload.relic_key}.`;
        case 'relic_trashed':
            return `${relicNames[event.payload.relic_key as string] ?? event.payload.relic_key} is trashed.`;
        case 'wanderer_healed':
            return `${role} heals to ${event.payload.hp} Health.`;
        case 'wanderers_swapped':
            return `${role} swaps places with ${roleLabel(event.payload.with as RoleKey)}.`;
        case 'golem_card_discarded':
            return `The top card of the Golem's draw pile is discarded.`;
        case 'golem_card_resolved':
            return `Golem plays ${(event.payload.name as string) ?? event.payload.card_key}.`;
        case 'golem_moved':
            return `The Golem moves.`;
        case 'wanderer_damaged':
            return `${role} loses ${event.payload.amount} Health.`;
        case 'wanderer_hp_set':
            return `${role}'s Health is set to ${event.payload.hp}.`;
        case 'glyph_added':
            return `A Glyph is added to the Golem's mat: ${glyphNames[event.payload.glyph_key as string] ?? event.payload.glyph_key}.`;
        case 'glyph_discarded':
            return `A Glyph falls off the Golem's mat: ${glyphNames[event.payload.glyph_key as string] ?? event.payload.glyph_key}.`;
        case 'game_finished':
            return `The game has ended.`;
        default:
            return `${role}: ${event.type}`;
    }
}

export function ActivityLog({
    events,
    locationNames,
    relicNames,
    glyphNames,
}: {
    events: GameLogEntry[];
    locationNames: Record<string, string>;
    relicNames: Record<string, string>;
    glyphNames: Record<string, string>;
}) {
    return (
        <div className="flex max-h-48 flex-col-reverse overflow-y-auto rounded-md border bg-card p-2 text-xs">
            <div className="flex flex-col gap-1">
                {events.length === 0 && (
                    <p className="text-muted-foreground">No activity yet.</p>
                )}
                {events.map((event) => (
                    <p key={event.id} className="text-muted-foreground">
                        {describe(event, locationNames, relicNames, glyphNames)}
                    </p>
                ))}
            </div>
        </div>
    );
}

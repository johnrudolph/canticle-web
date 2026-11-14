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
    golemCardNames: Record<string, string>,
): string {
    const role = roleLabel(event.role_key);
    const relic = (key: unknown) =>
        relicNames[key as string] ?? (key as string);
    const card = (key: unknown) =>
        golemCardNames[key as string] ?? (key as string);

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
        case 'location_drawn':
            return `${role} draws ${locationNames[event.payload.location_key as string] ?? event.payload.location_key} into the Emperor's hand.`;
        case 'emperor_phase_ended':
            return `${role} ended their phase.`;
        case 'infrastructure_placed':
            return `${role} placed ${event.payload.infrastructure}.`;
        case 'infrastructure_removed':
            return `${event.payload.infrastructure_type ?? 'Infrastructure'} is removed from the board.`;
        case 'infrastructure_returned':
            return `${event.payload.infrastructure ?? 'Infrastructure'} returns to the Emperor's pool.`;
        case 'wanderer_turn_started':
            return `${role}'s turn (${event.payload.actions} actions).`;
        case 'wanderer_moved':
            return event.payload.free
                ? `${role} moved for free (Road).`
                : `${role} moved.`;
        case 'wanderer_move_blocked':
            return `${role} cannot move for the rest of this turn (Incarceration).`;
        case 'fruit_tree_bonus_action':
            return `${role} gains an extra action (Fruit tree).`;
        case 'wanderer_actions_adjusted': {
            const delta = event.payload.delta as number;
            return delta >= 0
                ? `${role} gains ${delta} action${delta === 1 ? '' : 's'}.`
                : `${role} loses ${-delta} action${-delta === 1 ? '' : 's'}.`;
        }
        case 'location_searched': {
            const found = event.payload.found as string | null;
            if (!found) return `${role} searched and found nothing.`;
            if (event.payload.trashed_as_event) {
                return `${role} found ${relic(found)} — an Event, resolved and trashed. Its action is refunded.`;
            }
            return `${role} found ${relic(found)}.`;
        }
        case 'relic_equipped':
            return `${role} equipped ${relic(event.payload.relic_key)}.`;
        case 'relic_dropped':
            return `${role} dropped ${relic(event.payload.relic_key)}.`;
        case 'relic_picked_up':
            return `${role} picked up ${relic(event.payload.relic_key)}.`;
        case 'relic_used':
            return `${role} uses ${(event.payload.name as string) ?? relic(event.payload.relic_key)}.`;
        case 'relic_trashed':
            return `${relic(event.payload.relic_key)} is trashed.`;
        case 'relic_trashed_from_board':
            return `${relic(event.payload.relic_key)} is trashed from the board.`;
        case 'relic_burned':
            return `${relic(event.payload.relic_key)} is burned (Divine Cabalist pyre).`;
        case 'relic_recovered':
            return `${role} recovers ${relic(event.payload.relic_key)} (Re-creation).`;
        case 'relic_moved':
            return `${relic(event.payload.relic_key)} moves to another space.`;
        case 'relic_exchanged':
            return `${relic(event.payload.relic_key)} passes from ${roleLabel(event.payload.from as RoleKey)} to ${roleLabel(event.payload.to as RoleKey)}.`;
        case 'relic_tucked':
            return `${relic(event.payload.relic_key)} is tucked away.`;
        case 'tucked_progress_lost':
            return `${relic(event.payload.relic_key)}'s tucked progress is lost.`;
        case 'card_tucked':
            return `${role} tucks a card under ${relic(event.payload.relic_key)}.`;
        case 'golem_log_progress_lost':
            return `Golem log's progress is lost — its tucked cards return to the Golem's discard pile.`;
        case 'wanderer_healed':
            return `${role} heals to ${event.payload.hp} Health.`;
        case 'wanderer_damaged':
            return `${role} loses ${event.payload.amount} Health.`;
        case 'wanderer_hp_set':
            return `${role}'s Health is set to ${event.payload.hp}.`;
        case 'cabalist_wraps_triggered':
            return `${role} is saved from exhaustion by Cabalist wraps.`;
        case 'wanderer_shamed':
            return `${role} gains a Shame token.`;
        case 'wanderer_scarred':
            return `${role} gains a Scar token.`;
        case 'wanderer_shame_removed':
            return `${role} loses a Shame token.`;
        case 'wanderer_scar_removed':
            return `${role} loses a Scar token.`;
        case 'shame_moved':
            return `A Shame token moves from ${roleLabel(event.payload.from as RoleKey)} to ${roleLabel(event.payload.to as RoleKey)}.`;
        case 'wanderers_swapped':
            return `${role} swaps places with ${roleLabel(event.payload.with as RoleKey)}.`;
        case 'emperor_timepiece_swap':
            return `${role} swaps places with the Emperor (Emperor's Timepiece).`;
        case 'prophecy_gained':
            return `${role} gains ${event.payload.amount} 📜 Prophecy.`;
        case 'round_shifted':
            return `The round counter shifts to round ${event.payload.round} (Time spasm).`;
        case 'relics_moved_to_golem':
            return `${event.payload.count} Relic${event.payload.count === 1 ? '' : 's'} move to the Golem's space (Golem avarice).`;
        case 'golem_card_discarded':
            return `The top card of the Golem's draw pile is discarded.`;
        case 'golem_card_removed_from_discard':
            return `${card(event.payload.card_key)} is removed from the Golem's discard pile.`;
        case 'golem_card_resolved':
            return `Golem plays ${(event.payload.name as string) ?? card(event.payload.card_key)}.`;
        case 'golem_card_tapped':
            return `A tapped Golem card (${(event.payload.name as string) ?? card(event.payload.card_key)}) does nothing.`;
        case 'golem_card_flipped_tapped':
            return `${card(event.payload.card_key)} is flipped face-down (tapped).`;
        case 'golem_card_tucked':
            return `${card(event.payload.card_key)} is tucked away${event.payload.location === 'defenders-guild' ? " (Defenders' Guild)" : ''}.`;
        case 'golem_draw_pile_revealed':
            return `${role} looks at the top of the Golem's draw pile.`;
        case 'golem_moved':
            return `The Golem moves.`;
        case 'golem_removed':
            return `The Golem is removed from the board (Poof) — the Golem phase is skipped for the rest of the game.`;
        case 'golem_shame_gained':
            return `The Golem gains Shame.`;
        case 'defenders_guild_cards_returned':
            return `Defenders' Guild returns its 4 tucked cards to the Golem's discard pile, granting 📜📜.`;
        case 'house_of_cryptids_cards_returned':
            return `House of Cryptids returns its 4 tucked cards to the Golem's discard pile, granting 📜📜.`;
        case 'glyph_added':
            return `A Glyph is added to the Golem's mat: ${glyphNames[event.payload.glyph_key as string] ?? event.payload.glyph_key}.`;
        case 'glyph_discarded':
            return `A Glyph falls off the Golem's mat: ${glyphNames[event.payload.glyph_key as string] ?? event.payload.glyph_key}.`;
        case 'glyphs_reshuffled':
            return `The Golem's Glyphs are reshuffled (Past troubles).`;
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
    golemCardNames,
}: {
    events: GameLogEntry[];
    locationNames: Record<string, string>;
    relicNames: Record<string, string>;
    glyphNames: Record<string, string>;
    golemCardNames: Record<string, string>;
}) {
    return (
        <div className="flex max-h-48 flex-col-reverse overflow-y-auto rounded-md border bg-card p-2 text-xs">
            <div className="flex flex-col gap-1">
                {events.length === 0 && (
                    <p className="text-muted-foreground">No activity yet.</p>
                )}
                {events.map((event) => (
                    <p key={event.id} className="text-muted-foreground">
                        {describe(
                            event,
                            locationNames,
                            relicNames,
                            glyphNames,
                            golemCardNames,
                        )}
                    </p>
                ))}
            </div>
        </div>
    );
}

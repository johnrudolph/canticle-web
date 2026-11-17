import type { Cell } from '@/lib/hex';
import type { GameLogEntry } from '@/types/game';

const CARD_MARKER_TYPES = [
    'golem_card_resolved',
    'golem_card_tapped',
    'golem_card_skipped',
];

export interface GolemTurnSnapshot {
    golemCell: Cell | null;
    wandererHp: Record<string, number>;
}

export interface GolemTurnSegment {
    /** The golem_card_resolved/tapped/skipped event naming this card. */
    marker: GameLogEntry;
    /** Every event this card's resolution caused, in order. */
    followups: GameLogEntry[];
    /** Board/HP state once this segment (and everything before it) has played out. */
    snapshotAfter: GolemTurnSnapshot;
}

function applyEventToSnapshot(
    snapshot: GolemTurnSnapshot,
    event: GameLogEntry,
): GolemTurnSnapshot {
    switch (event.type) {
        case 'golem_moved':
            return {
                ...snapshot,
                golemCell: event.payload.to as Cell,
            };
        case 'wanderer_damaged': {
            const role = event.role_key;
            if (!role) return snapshot;
            const amount = event.payload.amount as number;
            return {
                ...snapshot,
                wandererHp: {
                    ...snapshot.wandererHp,
                    [role]: Math.max(
                        0,
                        (snapshot.wandererHp[role] ?? 0) - amount,
                    ),
                },
            };
        }
        case 'wanderer_healed':
        case 'wanderer_hp_set': {
            const role = event.role_key;
            if (!role) return snapshot;
            return {
                ...snapshot,
                wandererHp: {
                    ...snapshot.wandererHp,
                    [role]: event.payload.hp as number,
                },
            };
        }
        default:
            return snapshot;
    }
}

/**
 * Splits a flat run of Golem-phase events into one segment per card
 * (resolved, tapped, or skipped), each carrying a cumulative
 * board/HP snapshot of "what things look like once this card, and
 * everything before it this phase, has finished" — lets the
 * Spotlight dialog step through card-by-card while keeping the board
 * in sync with wherever the player has clicked to.
 */
export function buildGolemTurnSegments(
    events: GameLogEntry[],
    startSnapshot: GolemTurnSnapshot,
): GolemTurnSegment[] {
    const segments: GolemTurnSegment[] = [];
    let current: GolemTurnSegment | null = null;
    let snapshot = startSnapshot;

    for (const event of events) {
        if (CARD_MARKER_TYPES.includes(event.type)) {
            if (current) {
                current.snapshotAfter = snapshot;
                segments.push(current);
            }
            current = { marker: event, followups: [], snapshotAfter: snapshot };
        } else if (current) {
            current.followups.push(event);
        }

        snapshot = applyEventToSnapshot(snapshot, event);
    }

    if (current) {
        current.snapshotAfter = snapshot;
        segments.push(current);
    }

    return segments;
}

export function containsGolemCardMarker(events: GameLogEntry[]): boolean {
    return events.some((e) => CARD_MARKER_TYPES.includes(e.type));
}

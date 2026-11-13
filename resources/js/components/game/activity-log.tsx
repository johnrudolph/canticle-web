import type { GameLogEntry, RoleKey } from '@/types/game';

function roleLabel(role: RoleKey | null): string {
    if (!role) return 'Game';
    if (role === 'emperor') return 'Emperor';
    if (role === 'golem') return 'Golem';
    return `Wanderer ${role.replace('wanderer_', '').toUpperCase()}`;
}

function describe(event: GameLogEntry, locationNames: Record<string, string>): string {
    const role = roleLabel(event.role_key);

    switch (event.type) {
        case 'wanderer_started':
            return `${role} started on the board.`;
        case 'round_started':
            return `Round ${event.payload.round} started.`;
        case 'location_played': {
            const key = event.payload.location_key as string;
            return `${role} built ${locationNames[key] ?? key}.`;
        }
        case 'emperor_phase_ended':
            return `${role} ended their phase.`;
        case 'wanderer_turn_started':
            return `${role}'s turn (${event.payload.actions} actions).`;
        case 'wanderer_moved':
            return `${role} moved.`;
        case 'golem_phase_skipped':
            return `Golem phase skipped (not yet implemented).`;
        case 'game_finished':
            return `The game has ended.`;
        default:
            return `${role}: ${event.type}`;
    }
}

export function ActivityLog({
    events,
    locationNames,
}: {
    events: GameLogEntry[];
    locationNames: Record<string, string>;
}) {
    return (
        <div className="flex max-h-48 flex-col-reverse overflow-y-auto rounded-md border bg-card p-2 text-xs">
            <div className="flex flex-col gap-1">
                {events.length === 0 && (
                    <p className="text-muted-foreground">No activity yet.</p>
                )}
                {events.map((event) => (
                    <p key={event.id} className="text-muted-foreground">
                        {describe(event, locationNames)}
                    </p>
                ))}
            </div>
        </div>
    );
}

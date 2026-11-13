import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ActivityLog } from '@/components/game/activity-log';
import { GolemPanel } from '@/components/game/golem-panel';
import { HexBoard } from '@/components/game/hex-board';
import { WandererRelicPanel } from '@/components/game/wanderer-relic-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useClipboard } from '@/hooks/use-clipboard';
import { useGamePolling } from '@/hooks/use-game-polling';
import { cellKey, directionBetween, neighbors, placeableCells } from '@/lib/hex';
import type { Cell } from '@/lib/hex';
import { postJson } from '@/lib/http';
import games from '@/routes/games';
import roles from '@/routes/games/roles';
import type {
    GamePollResponse,
    GameSummary,
    InfrastructureType,
    LocationCatalogEntry,
    RoleKey,
    RoleSummary,
} from '@/types/game';

const INFRASTRUCTURE_LABELS: Record<InfrastructureType, string> = {
    monolith_shard: '✨ Monolith Shard',
    road: '🧱 Road',
    walls: '🏰 Walls',
    fruit_tree: '🌳 Fruit tree',
};

const ROLE_LABELS: Record<RoleKey, string> = {
    emperor: 'Emperor',
    golem: 'Golem',
    wanderer_a: 'Wanderer A',
    wanderer_b: 'Wanderer B',
    wanderer_c: 'Wanderer C',
};

function occupiedNeighbors(cell: Cell, board: Record<string, unknown>): Cell[] {
    return neighbors(cell).filter((n) => cellKey(n) in board);
}

function locationCostSatisfied(
    cell: Cell,
    location: LocationCatalogEntry,
    board: Record<string, { location_key: string }>,
    locationCatalog: Record<string, LocationCatalogEntry>,
): boolean {
    return location.costs.every((cost) =>
        occupiedNeighbors(cell, board).some(
            (n) => locationCatalog[board[cellKey(n)].location_key]?.produces === cost,
        ),
    );
}

export default function GameShow({ game }: { game: GameSummary }) {
    const [status, setStatus] = useState(game.status);
    const [roleList, setRoleList] = useState<RoleSummary[]>(game.roles);
    const [myRoles, setMyRoles] = useState<RoleKey[]>(game.my_roles);
    const [state, setState] = useState(game.state);
    const [events, setEvents] = useState(game.events);
    const [processing, setProcessing] = useState(false);
    const [selectedLocationKey, setSelectedLocationKey] = useState<string | null>(null);
    const [selectedInfrastructureType, setSelectedInfrastructureType] = useState<InfrastructureType | null>(
        null,
    );
    const [discardKeys, setDiscardKeys] = useState<Set<string>>(new Set());
    const [copiedText, copy] = useClipboard();

    useEffect(() => {
        setStatus(game.status);
        setRoleList(game.roles);
        setMyRoles(game.my_roles);
        setState(game.state);
    }, [game.status, game.roles, game.my_roles, game.state]);

    function applyPoll(payload: GamePollResponse) {
        setStatus(payload.status);
        setRoleList(payload.roles);
        setMyRoles(payload.my_roles);
        setState(payload.state);
        setEvents(payload.events);
    }

    useGamePolling(game.code, applyPoll);

    function post(url: string) {
        router.post(url, {}, { onStart: () => setProcessing(true), onFinish: () => setProcessing(false) });
    }

    async function submitMove(roleKey: RoleKey, move: Record<string, unknown>) {
        setProcessing(true);
        try {
            const response = await postJson<GamePollResponse>(games.moves.store(game.code).url, {
                role_key: roleKey,
                move,
            });
            applyPoll(response);
            setSelectedLocationKey(null);
            setDiscardKeys(new Set());
        } catch (err) {
            toast.error(err instanceof Error ? err.message : 'That move was rejected.');
        } finally {
            setProcessing(false);
        }
    }

    const inviteUrl = `${window.location.origin}${games.show(game.code).url}`;

    if (status === 'lobby') {
        return (
            <>
                <Head title={`Game ${game.code}`} />
                <div className="min-h-dvh p-4">
                    <Card className="mx-auto max-w-lg">
                        <CardHeader>
                            <CardTitle>Waiting Room</CardTitle>
                            <p className="text-sm text-muted-foreground">
                                Room code:{' '}
                                <span className="font-mono font-semibold text-foreground">
                                    {game.code}
                                </span>
                            </p>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Button variant="outline" size="sm" onClick={() => copy(inviteUrl)}>
                                {copiedText === inviteUrl ? 'Copied!' : 'Copy invite link'}
                            </Button>

                            <div className="space-y-2">
                                {roleList.map((role) => (
                                    <div
                                        key={role.role_key}
                                        className="flex items-center justify-between rounded-md border p-2 text-sm"
                                    >
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">
                                                {ROLE_LABELS[role.role_key]}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {role.user_name ?? 'Open'}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {role.user_name && (
                                                <Badge variant={role.is_ready ? 'default' : 'secondary'}>
                                                    {role.is_ready ? 'Ready' : 'Not ready'}
                                                </Badge>
                                            )}
                                            {role.is_mine ? (
                                                <>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={processing}
                                                        onClick={() =>
                                                            post(
                                                                roles.ready(
                                                                    { game: game.code, roleKey: role.role_key },
                                                                ).url,
                                                            )
                                                        }
                                                    >
                                                        {role.is_ready ? 'Unready' : "I'm ready"}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={processing}
                                                        onClick={() =>
                                                            post(
                                                                roles.release(
                                                                    { game: game.code, roleKey: role.role_key },
                                                                ).url,
                                                            )
                                                        }
                                                    >
                                                        Release
                                                    </Button>
                                                </>
                                            ) : (
                                                !role.user_name && (
                                                    <Button
                                                        size="sm"
                                                        disabled={processing}
                                                        onClick={() =>
                                                            post(
                                                                roles.claim(
                                                                    { game: game.code, roleKey: role.role_key },
                                                                ).url,
                                                            )
                                                        }
                                                    >
                                                        Claim
                                                    </Button>
                                                )
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className="flex flex-wrap gap-2">
                                {game.is_host && (
                                    <Button
                                        size="sm"
                                        disabled={processing}
                                        onClick={() => post(games.start(game.code).url)}
                                    >
                                        Start game
                                    </Button>
                                )}
                                {game.is_host && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                        onClick={() => {
                                            if (confirm('Abandon this game before it even starts?')) {
                                                post(games.abandon(game.code).url);
                                            }
                                        }}
                                    >
                                        Abandon
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </>
        );
    }

    if (!state) {
        return (
            <>
                <Head title={`Game ${game.code}`} />
                <div className="min-h-dvh p-4">
                    {status === 'abandoned' ? 'This game was abandoned.' : 'Loading game...'}
                </div>
            </>
        );
    }

    const locationCatalog = game.location_catalog;

    let highlightedCells = new Set<string>();
    let onCellClick: ((cell: Cell) => void) | undefined;

    if (state.phase === 'setup') {
        const myPendingRole = (state.pending_setup_wanderers as RoleKey[]).find((r) =>
            myRoles.includes(r),
        );
        if (myPendingRole) {
            highlightedCells = new Set(Object.keys(state.board));
            onCellClick = (cell) => submitMove(myPendingRole, { type: 'choose_starting_cell', cell });
        } else if (state.pending_golem_placement && myRoles.includes('emperor')) {
            highlightedCells = new Set(Object.keys(state.board));
            onCellClick = (cell) => submitMove('emperor', { type: 'choose_golem_cell', cell });
        }
    } else if (state.phase === 'emperor' && myRoles.includes('emperor') && selectedLocationKey) {
        const location = locationCatalog[selectedLocationKey];
        highlightedCells = new Set(
            placeableCells(state.board)
                .filter((cell) => locationCostSatisfied(cell, location, state.board, locationCatalog))
                .map(cellKey),
        );
        onCellClick = (cell) =>
            submitMove('emperor', { type: 'play_location', location_key: selectedLocationKey, cell });
    } else if (
        state.phase === 'emperor' &&
        myRoles.includes('emperor') &&
        selectedInfrastructureType &&
        state.infrastructure_placed_this_turn < 2
    ) {
        highlightedCells = new Set(
            Object.entries(state.board)
                .filter(
                    ([key, cellData]) =>
                        !(
                            selectedInfrastructureType === 'monolith_shard' &&
                            cellData.infrastructure.includes('monolith_shard')
                        ) &&
                        !(selectedInfrastructureType === 'walls' && key === (state.golem.cell && cellKey(state.golem.cell))),
                )
                .map(([key]) => key),
        );
        onCellClick = (cell) =>
            submitMove('emperor', {
                type: 'place_infrastructure',
                infrastructure_type: selectedInfrastructureType,
                cell,
            });
    } else if (state.phase === 'wanderer' && state.active_wanderer_role && myRoles.includes(state.active_wanderer_role)) {
        const mine = state.wanderers[state.active_wanderer_role];
        if (mine.cell && mine.actions_remaining > 0) {
            highlightedCells = new Set(occupiedNeighbors(mine.cell, state.board).map(cellKey));
            onCellClick = (cell) => {
                const direction = directionBetween(mine.cell as Cell, cell);
                if (direction) {
                    submitMove(state.active_wanderer_role as RoleKey, { type: 'move_wanderer', direction });
                }
            };
        }
    }

    return (
        <>
            <Head title={`Game ${game.code}`} />
            <div className="flex h-dvh gap-3 overflow-hidden p-3">
                <div className="flex min-h-0 flex-1 items-center justify-center overflow-auto">
                    <HexBoard
                        board={state.board}
                        locationCatalog={locationCatalog}
                        wanderers={state.wanderers}
                        golemCell={state.golem.cell}
                        highlightedCells={highlightedCells}
                        onCellClick={onCellClick}
                    />
                </div>

                <div className="flex w-80 shrink-0 flex-col gap-2 overflow-y-auto">
                    <div className="rounded-md border bg-card px-3 py-2 text-sm">
                        <p className="font-medium">
                            Round {state.round}/8 — {state.phase}
                        </p>
                        <p className="text-muted-foreground">
                            📜 Prophecy: {state.prophecy} (12 = victory, 16 = elite victory)
                        </p>
                        {status === 'finished' && state.outcome && (
                            <p className="mt-1 font-medium">
                                {state.outcome === 'elite_victory' && '🏆 Elite Victory!'}
                                {state.outcome === 'simple_victory' && '🎉 Victory!'}
                                {state.outcome === 'defeat' && '💀 Defeat.'}
                            </p>
                        )}
                        {status === 'abandoned' && <p className="text-destructive">Abandoned.</p>}
                    </div>

                    {state.phase === 'setup' && (
                        <div className="rounded-md border bg-card px-3 py-2 text-sm text-muted-foreground">
                            {state.pending_setup_wanderers.length > 0
                                ? `Waiting for Wanderers to choose a starting space (${state.pending_setup_wanderers.length} left).`
                                : 'Waiting for the Emperor to place the Golem.'}
                        </div>
                    )}

                    {state.phase !== 'setup' && (
                        <GolemPanel
                            golem={state.golem}
                            golemCardCatalog={game.golem_card_catalog}
                            glyphCatalog={game.glyph_catalog}
                            golemCardsThisRound={state.golem_cards_this_round}
                        />
                    )}

                    {state.phase === 'emperor' && myRoles.includes('emperor') && (
                        <div className="rounded-md border bg-card p-2">
                            <p className="mb-2 text-sm font-medium">Your hand</p>
                            <div className="flex flex-col gap-1">
                                {state.location_hand.map((key) => {
                                    const loc = locationCatalog[key];
                                    return (
                                        <button
                                            key={key}
                                            type="button"
                                            onClick={() => {
                                                setSelectedInfrastructureType(null);
                                                setSelectedLocationKey(
                                                    selectedLocationKey === key ? null : key,
                                                );
                                            }}
                                            className={`rounded-md border p-2 text-left text-xs ${
                                                selectedLocationKey === key
                                                    ? 'border-primary bg-primary/10'
                                                    : ''
                                            }`}
                                        >
                                            <span className="font-medium">
                                                {loc.produces_emoji} {loc.name}
                                            </span>
                                            {loc.costs.length > 0 && (
                                                <span className="block text-muted-foreground">
                                                    Needs: {loc.costs.join(', ')}
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>

                            <p className="mt-3 mb-1 text-sm font-medium">
                                Infrastructure ({state.infrastructure_placed_this_turn}/2 placed this turn)
                            </p>
                            <div className="flex flex-col gap-1">
                                {(Object.keys(INFRASTRUCTURE_LABELS) as InfrastructureType[]).map((type) => (
                                    <button
                                        key={type}
                                        type="button"
                                        disabled={
                                            state.infrastructure_pool[type] <= 0 ||
                                            state.infrastructure_placed_this_turn >= 2
                                        }
                                        onClick={() => {
                                            setSelectedLocationKey(null);
                                            setSelectedInfrastructureType(
                                                selectedInfrastructureType === type ? null : type,
                                            );
                                        }}
                                        className={`flex items-center justify-between rounded-md border p-2 text-left text-xs disabled:opacity-40 ${
                                            selectedInfrastructureType === type
                                                ? 'border-primary bg-primary/10'
                                                : ''
                                        }`}
                                    >
                                        <span>{INFRASTRUCTURE_LABELS[type]}</span>
                                        <span className="text-muted-foreground">
                                            {state.infrastructure_pool[type]} left
                                        </span>
                                    </button>
                                ))}
                            </div>

                            <Button
                                className="mt-3 w-full"
                                size="sm"
                                disabled={processing}
                                onClick={() => {
                                    setSelectedInfrastructureType(null);
                                    submitMove('emperor', {
                                        type: 'end_emperor_phase',
                                        discard: Array.from(discardKeys),
                                    });
                                }}
                            >
                                End Emperor phase
                            </Button>
                        </div>
                    )}

                    {state.phase === 'emperor' && !myRoles.includes('emperor') && (
                        <div className="rounded-md border bg-card px-3 py-2 text-sm text-muted-foreground">
                            Waiting for the Emperor...
                        </div>
                    )}

                    {state.phase === 'wanderer' && state.active_wanderer_role && (
                        <div className="rounded-md border bg-card px-3 py-2 text-sm">
                            <p>
                                {ROLE_LABELS[state.active_wanderer_role]}'s turn —{' '}
                                {state.wanderers[state.active_wanderer_role].actions_remaining} actions
                                left
                            </p>
                            {myRoles.includes(state.active_wanderer_role) && (
                                <div className="mt-2 flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={
                                            processing ||
                                            state.wanderers[state.active_wanderer_role].actions_remaining <= 0
                                        }
                                        onClick={() =>
                                            submitMove(state.active_wanderer_role as RoleKey, {
                                                type: 'search_location',
                                            })
                                        }
                                    >
                                        🔎 Search
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                        onClick={() =>
                                            submitMove(state.active_wanderer_role as RoleKey, {
                                                type: 'end_wanderer_turn',
                                            })
                                        }
                                    >
                                        End turn
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}

                    {state.phase !== 'setup' &&
                        myRoles
                            .filter((role): role is 'wanderer_a' | 'wanderer_b' | 'wanderer_c' =>
                                role.startsWith('wanderer_'),
                            )
                            .map((role) => {
                                const wanderer = state.wanderers[role];
                                const canAct =
                                    state.phase === 'wanderer' &&
                                    state.active_wanderer_role === role &&
                                    wanderer.actions_remaining > 0;
                                const allies = Object.entries(state.wanderers)
                                    .filter(
                                        ([otherRole, other]) =>
                                            otherRole !== role &&
                                            other.cell &&
                                            wanderer.cell &&
                                            cellKey(other.cell) === cellKey(wanderer.cell),
                                    )
                                    .map(([otherRole]) => ({
                                        role: otherRole,
                                        label: ROLE_LABELS[otherRole as RoleKey],
                                    }));

                                return (
                                    <WandererRelicPanel
                                        key={role}
                                        role={role}
                                        wanderer={wanderer}
                                        relicCatalog={game.relic_catalog}
                                        processing={processing}
                                        onSubmit={(move) => submitMove(role, move)}
                                        canAct={canAct}
                                        allies={allies}
                                    />
                                );
                            })}

                    <ActivityLog
                        events={events}
                        locationNames={Object.fromEntries(
                            Object.entries(locationCatalog).map(([k, v]) => [k, v.name]),
                        )}
                        relicNames={Object.fromEntries(
                            Object.entries(game.relic_catalog).map(([k, v]) => [k, v.name]),
                        )}
                        glyphNames={Object.fromEntries(
                            Object.entries(game.glyph_catalog).map(([k, v]) => [k, v.name]),
                        )}
                    />

                    {game.is_host && status === 'active' && (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={processing}
                            onClick={() => {
                                if (confirm('Abandon this game for everyone?')) {
                                    post(games.abandon(game.code).url);
                                }
                            }}
                        >
                            Abandon game
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}

import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ActivityLog } from '@/components/game/activity-log';
import { GolemPanel } from '@/components/game/golem-panel';
import { HexBoard } from '@/components/game/hex-board';
import { LocationDetailDialog } from '@/components/game/location-detail-dialog';
import { NewRelicDialog } from '@/components/game/new-relic-dialog';
import { WandererRelicPanel } from '@/components/game/wanderer-relic-panel';
import { WandererSummaryRow } from '@/components/game/wanderer-summary-row';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { useClipboard } from '@/hooks/use-clipboard';
import { useGamePolling } from '@/hooks/use-game-polling';
import {
    cellKey,
    directionBetween,
    neighbors,
    placeableCells,
} from '@/lib/hex';
import type { Cell } from '@/lib/hex';
import { postJson } from '@/lib/http';
import { home } from '@/routes';
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
            (n) =>
                locationCatalog[board[cellKey(n)].location_key]?.produces ===
                cost,
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
    const [selectedLocationKey, setSelectedLocationKey] = useState<
        string | null
    >(null);
    const [selectedInfrastructureType, setSelectedInfrastructureType] =
        useState<InfrastructureType | null>(null);
    const [discardKeys, setDiscardKeys] = useState<Set<string>>(new Set());
    const [emperorStep, setEmperorStep] = useState<
        'location' | 'infrastructure' | 'discard'
    >('location');
    const [handInfoKey, setHandInfoKey] = useState<string | null>(null);
    const [copiedText, copy] = useClipboard();
    const [newRelic, setNewRelic] = useState<{
        role: RoleKey;
        relicKey: string;
    } | null>(null);
    const holdingSeenRef = useRef<Record<string, Set<string>>>({});
    const emperorStepRoundRef = useRef<number | null>(null);

    useEffect(() => {
        setStatus(game.status);
        setRoleList(game.roles);
        setMyRoles(game.my_roles);
        setState(game.state);

        // Seed the "already seen" set from the page's initial load so the
        // first poll doesn't treat pre-existing held Relics as newly found.
        if (game.state) {
            for (const role of game.my_roles) {
                const wanderer = game.state.wanderers[role];
                if (wanderer) {
                    holdingSeenRef.current[role] = new Set(wanderer.holding);
                }
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [game.status, game.roles, game.my_roles, game.state]);

    // Reset the Emperor's turn back to step 1 at the start of each new
    // Emperor phase (a fresh round, or coming back from the Wanderer/Golem
    // phases) — otherwise it'd stay wherever it was left mid-turn.
    useEffect(() => {
        if (
            state?.phase === 'emperor' &&
            emperorStepRoundRef.current !== state.round
        ) {
            emperorStepRoundRef.current = state.round;
            setEmperorStep('location');
        }
    }, [state?.phase, state?.round]);

    function detectNewRelic(nextState: typeof state, roles: RoleKey[]) {
        if (!nextState) return;

        for (const role of roles) {
            const wanderer = nextState.wanderers[role];
            if (!wanderer) continue;

            const seen = holdingSeenRef.current[role] ?? new Set<string>();
            const fresh = wanderer.holding.find((key) => !seen.has(key));
            holdingSeenRef.current[role] = new Set(wanderer.holding);

            if (fresh) {
                setNewRelic({ role, relicKey: fresh });
            }

            // The dialog has no "decide later" dismiss of its own — it
            // only closes once polled state confirms the equip/drop
            // actually went through (the Relic left holding for real).
            // If a submit from inside it failed server-side, holding
            // still contains the key, so this intentionally does not
            // close it — the player sees the error toast and can retry
            // from the same dialog instead of being left with it stuck,
            // undecided, and no way back in.
            setNewRelic((current) => {
                if (
                    current?.role === role &&
                    !wanderer.holding.includes(current.relicKey)
                ) {
                    return null;
                }

                return current;
            });
        }
    }

    function applyPoll(payload: GamePollResponse) {
        detectNewRelic(payload.state, payload.my_roles);
        setStatus(payload.status);
        setRoleList(payload.roles);
        setMyRoles(payload.my_roles);
        setState(payload.state);
        setEvents(payload.events);
    }

    useGamePolling(game.code, applyPoll);

    function post(url: string) {
        router.post(
            url,
            {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    }

    async function submitMove(roleKey: RoleKey, move: Record<string, unknown>) {
        setProcessing(true);
        try {
            const response = await postJson<GamePollResponse>(
                games.moves.store(game.code).url,
                {
                    role_key: roleKey,
                    move,
                },
            );
            applyPoll(response);
            setSelectedLocationKey(null);
            setDiscardKeys(new Set());
        } catch (err) {
            toast.error(
                err instanceof Error ? err.message : 'That move was rejected.',
            );
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
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => copy(inviteUrl)}
                            >
                                {copiedText === inviteUrl
                                    ? 'Copied!'
                                    : 'Copy invite link'}
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
                                            {role.is_mine ? (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    disabled={processing}
                                                    onClick={() =>
                                                        post(
                                                            roles.release({
                                                                game: game.code,
                                                                roleKey:
                                                                    role.role_key,
                                                            }).url,
                                                        )
                                                    }
                                                >
                                                    Release
                                                </Button>
                                            ) : (
                                                !role.user_name && (
                                                    <Button
                                                        size="sm"
                                                        disabled={processing}
                                                        onClick={() =>
                                                            post(
                                                                roles.claim({
                                                                    game: game.code,
                                                                    roleKey:
                                                                        role.role_key,
                                                                }).url,
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
                                        onClick={() =>
                                            post(games.start(game.code).url)
                                        }
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
                                            if (
                                                confirm(
                                                    'Abandon this game before it even starts?',
                                                )
                                            ) {
                                                post(
                                                    games.abandon(game.code)
                                                        .url,
                                                );
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
                    {status === 'abandoned' ? (
                        <div className="space-y-3">
                            <p>This game was abandoned.</p>
                            <Button asChild variant="outline" size="sm">
                                <Link href={home()}>Return home</Link>
                            </Button>
                        </div>
                    ) : (
                        'Loading game...'
                    )}
                </div>
            </>
        );
    }

    const locationCatalog = game.location_catalog;

    let highlightedCells = new Set<string>();
    let onCellClick: ((cell: Cell) => void) | undefined;

    if (state.phase === 'setup') {
        const myPendingRole = (state.pending_setup_wanderers as RoleKey[]).find(
            (r) => myRoles.includes(r),
        );
        if (myPendingRole) {
            highlightedCells = new Set(Object.keys(state.board));
            onCellClick = (cell) =>
                submitMove(myPendingRole, {
                    type: 'choose_starting_cell',
                    cell,
                });
        } else if (
            state.pending_golem_placement &&
            myRoles.includes('emperor')
        ) {
            highlightedCells = new Set(Object.keys(state.board));
            onCellClick = (cell) =>
                submitMove('emperor', { type: 'choose_golem_cell', cell });
        }
    } else if (
        state.phase === 'emperor' &&
        myRoles.includes('emperor') &&
        selectedLocationKey
    ) {
        const location = locationCatalog[selectedLocationKey];
        highlightedCells = new Set(
            placeableCells(state.board)
                .filter((cell) =>
                    locationCostSatisfied(
                        cell,
                        location,
                        state.board,
                        locationCatalog,
                    ),
                )
                .map(cellKey),
        );
        onCellClick = (cell) =>
            submitMove('emperor', {
                type: 'play_location',
                location_key: selectedLocationKey,
                cell,
            });
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
                        !(
                            selectedInfrastructureType === 'walls' &&
                            key ===
                                (state.golem.cell && cellKey(state.golem.cell))
                        ),
                )
                .map(([key]) => key),
        );
        onCellClick = (cell) =>
            submitMove('emperor', {
                type: 'place_infrastructure',
                infrastructure_type: selectedInfrastructureType,
                cell,
            });
    } else if (
        state.phase === 'wanderer' &&
        state.active_wanderer_role &&
        myRoles.includes(state.active_wanderer_role)
    ) {
        const mine = state.wanderers[state.active_wanderer_role];
        if (mine.cell && mine.actions_remaining > 0) {
            highlightedCells = new Set(
                occupiedNeighbors(mine.cell, state.board).map(cellKey),
            );
            onCellClick = (cell) => {
                const direction = directionBetween(mine.cell as Cell, cell);
                if (direction) {
                    submitMove(state.active_wanderer_role as RoleKey, {
                        type: 'move_wanderer',
                        direction,
                    });
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
                        relicCatalog={game.relic_catalog}
                        wanderers={state.wanderers}
                        golemCell={state.golem.cell}
                        highlightedCells={highlightedCells}
                        onCellClick={onCellClick}
                        myRoles={myRoles}
                        onPickupRelic={(role, relicKey) =>
                            submitMove(role as RoleKey, {
                                type: 'pickup_relic',
                                relic_key: relicKey,
                            })
                        }
                    />
                </div>

                <div className="flex w-[40rem] shrink-0 flex-col gap-2 overflow-y-auto">
                    <div className="rounded-md border bg-card px-3 py-2 text-sm">
                        <p className="font-medium">
                            Round {state.round}/8 — {state.phase}
                        </p>
                        <p className="text-muted-foreground">
                            📜 Prophecy: {state.prophecy} (12 = victory, 16 =
                            elite victory)
                        </p>
                        {status === 'finished' && state.outcome && (
                            <p className="mt-1 font-medium">
                                {state.outcome === 'elite_victory' &&
                                    '🏆 Elite Victory!'}
                                {state.outcome === 'simple_victory' &&
                                    '🎉 Victory!'}
                                {state.outcome === 'defeat' && '💀 Defeat.'}
                            </p>
                        )}
                        {status === 'abandoned' && (
                            <p className="text-destructive">Abandoned.</p>
                        )}
                        {state.golem_skipped && (
                            <p className="mt-1 font-medium">
                                💨 The Golem is gone — Poof! No more Golem
                                phases this game.
                            </p>
                        )}
                    </div>

                    {state.phase === 'setup' && (
                        <div className="rounded-md border bg-card px-3 py-2 text-sm text-muted-foreground">
                            {state.pending_setup_wanderers.length > 0
                                ? `Waiting for Wanderers to choose a starting space (${state.pending_setup_wanderers.length} left).`
                                : 'Waiting for the Emperor to place the Golem.'}
                        </div>
                    )}

                    {/* Emperor, then each Wanderer, then the Golem — turn order. */}
                    {state.phase === 'emperor' &&
                        myRoles.includes('emperor') && (
                            <div className="rounded-md border border-primary bg-card p-2 ring-1 ring-primary">
                                <div className="mb-2 flex items-center justify-between">
                                    <p className="text-sm font-medium">
                                        👑 Emperor's turn
                                    </p>
                                    <div className="flex gap-1 text-[10px] text-muted-foreground">
                                        <span
                                            className={
                                                emperorStep === 'location'
                                                    ? 'font-semibold text-foreground'
                                                    : ''
                                            }
                                        >
                                            1. Play
                                        </span>
                                        <span>→</span>
                                        <span
                                            className={
                                                emperorStep === 'infrastructure'
                                                    ? 'font-semibold text-foreground'
                                                    : ''
                                            }
                                        >
                                            2. Infrastructure
                                        </span>
                                        <span>→</span>
                                        <span
                                            className={
                                                emperorStep === 'discard'
                                                    ? 'font-semibold text-foreground'
                                                    : ''
                                            }
                                        >
                                            3. Discard
                                        </span>
                                    </div>
                                </div>

                                {emperorStep === 'location' && (
                                    <>
                                        <p className="mb-2 text-xs text-muted-foreground">
                                            Play 1 Location from your hand
                                            (click it, then click a highlighted
                                            space on the board). Grayed-out ones
                                            have nowhere on the board they could
                                            go right now.
                                        </p>
                                        <div className="flex flex-col gap-1">
                                            {state.location_hand.map((key) => {
                                                const loc =
                                                    locationCatalog[key];
                                                const playable = placeableCells(
                                                    state.board,
                                                ).some((cell) =>
                                                    locationCostSatisfied(
                                                        cell,
                                                        loc,
                                                        state.board,
                                                        locationCatalog,
                                                    ),
                                                );
                                                return (
                                                    <button
                                                        key={key}
                                                        type="button"
                                                        disabled={!playable}
                                                        onClick={() =>
                                                            setSelectedLocationKey(
                                                                selectedLocationKey ===
                                                                    key
                                                                    ? null
                                                                    : key,
                                                            )
                                                        }
                                                        className={`rounded-md border p-2 text-left text-xs ${
                                                            !playable
                                                                ? 'cursor-not-allowed bg-muted/50 opacity-50'
                                                                : selectedLocationKey ===
                                                                    key
                                                                  ? 'border-primary bg-primary/10'
                                                                  : ''
                                                        }`}
                                                    >
                                                        <span className="font-medium">
                                                            {loc.produces_emoji}{' '}
                                                            {loc.name}
                                                        </span>
                                                        {loc.costs.length >
                                                            0 && (
                                                            <span className="block text-muted-foreground">
                                                                Needs:{' '}
                                                                {loc.costs.join(
                                                                    ', ',
                                                                )}
                                                            </span>
                                                        )}
                                                        {loc.back_text && (
                                                            <span className="mt-1 block text-muted-foreground">
                                                                {loc.back_text}
                                                            </span>
                                                        )}
                                                        <span className="mt-1 block text-muted-foreground">
                                                            Deals{' '}
                                                            {loc.relic_count}{' '}
                                                            face-down Relic
                                                            {loc.relic_count ===
                                                            1
                                                                ? ''
                                                                : 's'}
                                                            .
                                                        </span>
                                                    </button>
                                                );
                                            })}
                                        </div>

                                        <Button
                                            className="mt-3 w-full"
                                            size="sm"
                                            onClick={() => {
                                                setSelectedLocationKey(null);
                                                setEmperorStep(
                                                    'infrastructure',
                                                );
                                            }}
                                        >
                                            Next: Place Infrastructure →
                                        </Button>
                                    </>
                                )}

                                {emperorStep === 'infrastructure' && (
                                    <>
                                        <p className="mb-2 text-xs text-muted-foreground">
                                            Place up to 2 Infrastructure tokens
                                            from your pool (optional — click a
                                            type, then a highlighted space on
                                            the board).
                                        </p>
                                        <p className="mb-1 text-sm font-medium">
                                            Infrastructure (
                                            {
                                                state.infrastructure_placed_this_turn
                                            }
                                            /2 placed this turn)
                                        </p>
                                        <div className="flex flex-col gap-1">
                                            {(
                                                Object.keys(
                                                    INFRASTRUCTURE_LABELS,
                                                ) as InfrastructureType[]
                                            ).map((type) => (
                                                <button
                                                    key={type}
                                                    type="button"
                                                    disabled={
                                                        state
                                                            .infrastructure_pool[
                                                            type
                                                        ] <= 0 ||
                                                        state.infrastructure_placed_this_turn >=
                                                            2
                                                    }
                                                    onClick={() =>
                                                        setSelectedInfrastructureType(
                                                            selectedInfrastructureType ===
                                                                type
                                                                ? null
                                                                : type,
                                                        )
                                                    }
                                                    className={`flex items-center justify-between rounded-md border p-2 text-left text-xs disabled:opacity-40 ${
                                                        selectedInfrastructureType ===
                                                        type
                                                            ? 'border-primary bg-primary/10'
                                                            : ''
                                                    }`}
                                                >
                                                    <span>
                                                        {
                                                            INFRASTRUCTURE_LABELS[
                                                                type
                                                            ]
                                                        }
                                                    </span>
                                                    <span className="text-muted-foreground">
                                                        {
                                                            state
                                                                .infrastructure_pool[
                                                                type
                                                            ]
                                                        }{' '}
                                                        left
                                                    </span>
                                                </button>
                                            ))}
                                        </div>

                                        <div className="mt-3 flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => {
                                                    setSelectedInfrastructureType(
                                                        null,
                                                    );
                                                    setEmperorStep('location');
                                                }}
                                            >
                                                ← Back
                                            </Button>
                                            <Button
                                                className="flex-1"
                                                size="sm"
                                                onClick={() => {
                                                    setSelectedInfrastructureType(
                                                        null,
                                                    );
                                                    setEmperorStep('discard');
                                                }}
                                            >
                                                Next: Discard →
                                            </Button>
                                        </div>
                                    </>
                                )}

                                {emperorStep === 'discard' && (
                                    <>
                                        <p className="mb-2 text-xs text-muted-foreground">
                                            Discard as many cards from your hand
                                            as you want, then draw back up to 5.
                                            Mark the ones to discard (or none),
                                            then confirm below — this ends your
                                            turn.
                                        </p>
                                        <div className="flex flex-col gap-1">
                                            {state.location_hand.map((key) => {
                                                const loc =
                                                    locationCatalog[key];
                                                const marked =
                                                    discardKeys.has(key);
                                                return (
                                                    <div
                                                        key={key}
                                                        className={`flex items-center gap-2 rounded-md border p-2 text-xs ${
                                                            marked
                                                                ? 'opacity-50'
                                                                : ''
                                                        }`}
                                                    >
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                setHandInfoKey(
                                                                    key,
                                                                )
                                                            }
                                                            className="flex size-5 shrink-0 items-center justify-center rounded-full bg-muted hover:bg-muted-foreground/20"
                                                            aria-label={`Inspect ${loc.name}`}
                                                        >
                                                            ℹ️
                                                        </button>
                                                        <label className="flex flex-1 items-center gap-1.5">
                                                            <Checkbox
                                                                checked={marked}
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) => {
                                                                    setDiscardKeys(
                                                                        (
                                                                            prev,
                                                                        ) => {
                                                                            const next =
                                                                                new Set(
                                                                                    prev,
                                                                                );
                                                                            if (
                                                                                checked
                                                                            ) {
                                                                                next.add(
                                                                                    key,
                                                                                );
                                                                            } else {
                                                                                next.delete(
                                                                                    key,
                                                                                );
                                                                            }
                                                                            return next;
                                                                        },
                                                                    );
                                                                }}
                                                            />
                                                            <span className="font-medium">
                                                                {
                                                                    loc.produces_emoji
                                                                }{' '}
                                                                {loc.name}
                                                            </span>
                                                        </label>
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        <div className="mt-3 flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setEmperorStep(
                                                        'infrastructure',
                                                    )
                                                }
                                            >
                                                ← Back
                                            </Button>
                                            <Button
                                                className="flex-1"
                                                size="sm"
                                                variant={
                                                    discardKeys.size === 0
                                                        ? 'outline'
                                                        : 'default'
                                                }
                                                disabled={processing}
                                                onClick={() => {
                                                    setDiscardKeys(new Set());
                                                    submitMove('emperor', {
                                                        type: 'end_emperor_phase',
                                                        discard: [],
                                                    });
                                                }}
                                            >
                                                Keep all, end turn
                                            </Button>
                                            <Button
                                                className="flex-1"
                                                size="sm"
                                                disabled={
                                                    processing ||
                                                    discardKeys.size === 0
                                                }
                                                onClick={() => {
                                                    const toDiscard =
                                                        Array.from(discardKeys);
                                                    setDiscardKeys(new Set());
                                                    submitMove('emperor', {
                                                        type: 'end_emperor_phase',
                                                        discard: toDiscard,
                                                    });
                                                }}
                                            >
                                                Discard {discardKeys.size}, end
                                                turn
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </div>
                        )}

                    {state.phase === 'emperor' &&
                        !myRoles.includes('emperor') && (
                            <div className="rounded-md border bg-card px-3 py-2 text-sm text-muted-foreground">
                                Waiting for the Emperor... (
                                {state.location_hand_count} cards in hand)
                            </div>
                        )}

                    {state.phase === 'wanderer' &&
                        state.active_wanderer_role && (
                            <div className="rounded-md border border-primary bg-card px-3 py-2 text-sm ring-1 ring-primary">
                                <p>
                                    ▶️ {ROLE_LABELS[state.active_wanderer_role]}
                                    's turn —{' '}
                                    {
                                        state.wanderers[
                                            state.active_wanderer_role
                                        ].actions_remaining
                                    }{' '}
                                    actions left
                                </p>
                                {myRoles.includes(
                                    state.active_wanderer_role,
                                ) && (
                                    <div className="mt-2 flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={
                                                processing ||
                                                state.wanderers[
                                                    state.active_wanderer_role
                                                ].actions_remaining <= 0
                                            }
                                            onClick={() =>
                                                submitMove(
                                                    state.active_wanderer_role as RoleKey,
                                                    {
                                                        type: 'search_location',
                                                    },
                                                )
                                            }
                                        >
                                            🔎 Search
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={processing}
                                            onClick={() =>
                                                submitMove(
                                                    state.active_wanderer_role as RoleKey,
                                                    {
                                                        type: 'end_wanderer_turn',
                                                    },
                                                )
                                            }
                                        >
                                            End turn
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}

                    {state.phase !== 'setup' &&
                        (
                            Object.keys(state.wanderers) as (
                                | 'wanderer_a'
                                | 'wanderer_b'
                                | 'wanderer_c'
                            )[]
                        ).map((role) => {
                            const wanderer = state.wanderers[role];
                            const isActive =
                                state.phase === 'wanderer' &&
                                state.active_wanderer_role === role;
                            const canAct =
                                isActive &&
                                myRoles.includes(role) &&
                                wanderer.actions_remaining > 0;
                            const allies = Object.entries(state.wanderers)
                                .filter(
                                    ([otherRole, other]) =>
                                        otherRole !== role &&
                                        other.cell &&
                                        wanderer.cell &&
                                        cellKey(other.cell) ===
                                            cellKey(wanderer.cell),
                                )
                                .map(([otherRole]) => ({
                                    role: otherRole,
                                    label: ROLE_LABELS[otherRole as RoleKey],
                                }));

                            if (!isActive) {
                                return (
                                    <WandererSummaryRow
                                        key={role}
                                        role={role}
                                        wanderer={wanderer}
                                        relicCatalog={game.relic_catalog}
                                        canAct={canAct}
                                        processing={processing}
                                        onSubmit={(move) =>
                                            submitMove(role, move)
                                        }
                                    />
                                );
                            }

                            return (
                                <WandererRelicPanel
                                    key={role}
                                    role={role}
                                    wanderer={wanderer}
                                    relicCatalog={game.relic_catalog}
                                    processing={processing}
                                    onSubmit={(move) => submitMove(role, move)}
                                    canAct={canAct}
                                    isActive={isActive}
                                    allies={allies}
                                    relicUnusableReasons={
                                        state.active_wanderer_relic_reasons
                                    }
                                />
                            );
                        })}

                    {state.phase !== 'setup' && (
                        <GolemPanel
                            golem={state.golem}
                            golemCardCatalog={game.golem_card_catalog}
                            glyphCatalog={game.glyph_catalog}
                            golemCardsThisRound={state.golem_cards_this_round}
                        />
                    )}

                    <ActivityLog
                        events={events}
                        locationNames={Object.fromEntries(
                            Object.entries(locationCatalog).map(([k, v]) => [
                                k,
                                v.name,
                            ]),
                        )}
                        relicNames={Object.fromEntries(
                            Object.entries(game.relic_catalog).map(([k, v]) => [
                                k,
                                v.name,
                            ]),
                        )}
                        glyphNames={Object.fromEntries(
                            Object.entries(game.glyph_catalog).map(([k, v]) => [
                                k,
                                v.name,
                            ]),
                        )}
                        golemCardNames={Object.fromEntries(
                            Object.entries(game.golem_card_catalog).map(
                                ([k, v]) => [k, v.name],
                            ),
                        )}
                    />

                    {game.is_host && status === 'active' && (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={processing}
                            onClick={() => {
                                if (
                                    confirm('Abandon this game for everyone?')
                                ) {
                                    post(games.abandon(game.code).url);
                                }
                            }}
                        >
                            Abandon game
                        </Button>
                    )}
                </div>
            </div>

            <NewRelicDialog
                open={newRelic !== null}
                relicKey={newRelic?.relicKey ?? null}
                entry={
                    newRelic ? game.relic_catalog[newRelic.relicKey] : undefined
                }
                equipped={
                    newRelic ? state.wanderers[newRelic.role].equipped : null
                }
                relicCatalog={game.relic_catalog}
                processing={processing}
                onSubmit={(move) => newRelic && submitMove(newRelic.role, move)}
            />

            <LocationDetailDialog
                open={handInfoKey !== null}
                onOpenChange={(open) => !open && setHandInfoKey(null)}
                location={
                    handInfoKey ? locationCatalog[handInfoKey] : undefined
                }
                cellData={undefined}
            />
        </>
    );
}

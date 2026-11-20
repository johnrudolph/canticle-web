import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ActivityLog } from '@/components/game/activity-log';
import { CabalistBrandDialog } from '@/components/game/cabalist-brand-dialog';
import { EpiphanyDialog } from '@/components/game/epiphany-dialog';
import { EventRelicDialog } from '@/components/game/event-relic-dialog';
import { GolemPanel } from '@/components/game/golem-panel';
import { GolemTurnSpotlightDialog } from '@/components/game/golem-turn-spotlight-dialog';
import { HexBoard } from '@/components/game/hex-board';
import { InsightFromTheFutureDialog } from '@/components/game/insight-from-the-future-dialog';
import { InvigorationDialog } from '@/components/game/invigoration-dialog';
import { LocationDetailDialog } from '@/components/game/location-detail-dialog';
import { NewRelicDialog } from '@/components/game/new-relic-dialog';
import { RelicPickerDialog } from '@/components/game/relic-picker-dialog';
import { RiftInTimeDialog } from '@/components/game/rift-in-time-dialog';
import { WandererPickerDialog } from '@/components/game/wanderer-picker-dialog';
import { WandererRelicPanel } from '@/components/game/wanderer-relic-panel';
import { WandererSummaryRow } from '@/components/game/wanderer-summary-row';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useClipboard } from '@/hooks/use-clipboard';
import { useGamePolling } from '@/hooks/use-game-polling';
import {
    buildGolemTurnSegments,
    containsGolemCardMarker,
} from '@/lib/golem-turn-spotlight';
import type {
    GolemTurnSegment,
    GolemTurnSnapshot,
} from '@/lib/golem-turn-spotlight';
import {
    cellKey,
    DIRECTIONS,
    directionBetween,
    neighbor,
    neighbors,
    placeableCells,
} from '@/lib/hex';
import type { Cell } from '@/lib/hex';
import { postJson } from '@/lib/http';
import {
    INFRASTRUCTURE_DESCRIPTIONS,
    INFRASTRUCTURE_LABELS,
} from '@/lib/infrastructure';
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

/** Branch name (e.g. "Art") -> the emoji whichever Location produces it uses. */
function branchEmoji(
    branch: string,
    locationCatalog: Record<string, LocationCatalogEntry>,
): string | null {
    const match = Object.values(locationCatalog).find(
        (l) => l.produces === branch,
    );
    return match?.produces_emoji ?? null;
}

function adjacencyRequirementText(
    location: LocationCatalogEntry,
    locationCatalog: Record<string, LocationCatalogEntry>,
): string {
    if (location.costs.length === 0) {
        return 'No adjacency requirement.';
    }

    return `Requires adjacent: ${location.costs
        .map((c) => {
            const emoji = branchEmoji(c, locationCatalog);
            return emoji ? `${emoji} ${c}` : c;
        })
        .join(', ')}`;
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
    const [insightPeek, setInsightPeek] = useState<{
        role: RoleKey;
        relicKey: string;
        deck: string[];
    } | null>(null);
    const [cabalistBrandPrompt, setCabalistBrandPrompt] = useState<{
        role: RoleKey;
        relicKey: string;
    } | null>(null);
    const [wandererPrompt, setWandererPrompt] = useState<{
        title: string;
        description?: string;
        candidates: { role: string; label: string }[];
        onChoose: (role: string) => void;
    } | null>(null);
    const [relicPrompt, setRelicPrompt] = useState<{
        title: string;
        description?: string;
        candidates: { key: string; label: string }[];
        multiple?: boolean;
        confirmLabel?: string;
        onConfirm: (keys: string[]) => void;
    } | null>(null);
    const [boardPick, setBoardPick] = useState<{
        description: string;
        eligible: Set<string>;
        onPick: (cell: Cell) => void;
    } | null>(null);
    const [golemLogPeek, setGolemLogPeek] = useState<{
        role: RoleKey;
        cardKeys: string[];
    } | null>(null);
    // Revelatory Glyph: "When moving, Wanderers may choose to lose any
    // amount of Health. +1 action for each Health lost." Not a move
    // type of its own — just an extra field on the next move_wanderer
    // click — so it's staged here rather than prompted through a
    // dialog, reset back to 0 once spent.
    const [revelatorySacrificeHp, setRevelatorySacrificeHp] = useState(0);
    const [eventRelicQueue, setEventRelicQueue] = useState<
        { role: RoleKey; relicKey: string }[]
    >([]);
    const [golemSpotlightSegments, setGolemSpotlightSegments] = useState<
        GolemTurnSegment[]
    >([]);
    const [golemSpotlightIndex, setGolemSpotlightIndex] = useState(0);
    const holdingSeenRef = useRef<Record<string, Set<string>>>({});
    const seenEventIdRef = useRef<number | null>(null);
    const previousPhaseRef = useRef<string | null>(null);

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

        // Same idea for Event Relics: don't re-surface a notification for
        // events that already happened before this page load.
        seenEventIdRef.current = game.events.reduce(
            (max, e) => Math.max(max, e.id),
            0,
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [game.status, game.roles, game.my_roles, game.state]);

    // Reset the Emperor's turn back to step 1 every time the game phase
    // transitions INTO 'emperor' from something else — not keyed by round
    // number. Time spasm can move the round counter backward mid-round,
    // so the same round number can see two distinct Emperor phases (e.g.
    // round 2's Emperor phase, then Time spasm rewinds to round 1, then
    // the normal end-of-round increment brings it back to round 2 for a
    // second, different Emperor phase). Keying off round number caused
    // the second phase to be mistaken for "already seen," leaving the
    // stepper stuck wherever the first one ended (reported: starting the
    // Emperor's turn dumped straight into the discard step).
    useEffect(() => {
        if (
            state?.phase === 'emperor' &&
            previousPhaseRef.current !== 'emperor'
        ) {
            setEmperorStep('location');
        }
        previousPhaseRef.current = state?.phase ?? null;
    }, [state?.phase]);

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

    function detectEventRelic(payload: GamePollResponse, lastSeen: number) {
        const found = payload.events.filter(
            (e) =>
                e.id > lastSeen &&
                e.type === 'location_searched' &&
                e.payload.trashed_as_event === true &&
                // Invigoration/Rift in time/Epiphany don't resolve on the
                // spot — each blocks on its own dedicated dialog (driven
                // by pending_invigoration/pending_rift_in_time/
                // pending_epiphany) instead of the generic "found an
                // Event, it resolved" notification, which would be false
                // for all three right now.
                e.payload.found !== 'invigoration' &&
                e.payload.found !== 'rift-in-time' &&
                e.payload.found !== 'epiphany',
        );

        if (found.length > 0) {
            setEventRelicQueue((queue) => [
                ...queue,
                ...found.map((e) => ({
                    role: e.role_key as RoleKey,
                    relicKey: e.payload.found as string,
                })),
            ]);
        }
    }

    function detectGolemTurn(
        payload: GamePollResponse,
        lastSeen: number,
        preMoveSnapshot: GolemTurnSnapshot | null,
    ) {
        const fresh = payload.events.filter((e) => e.id > lastSeen);
        if (!preMoveSnapshot || !containsGolemCardMarker(fresh)) return;

        const segments = buildGolemTurnSegments(fresh, preMoveSnapshot);
        if (segments.length > 0) {
            setGolemSpotlightSegments(segments);
            setGolemSpotlightIndex(0);
        }
    }

    function applyPoll(
        payload: GamePollResponse,
        preMoveSnapshot: GolemTurnSnapshot | null = null,
    ) {
        const lastSeen = seenEventIdRef.current ?? 0;
        detectNewRelic(payload.state, payload.my_roles);
        detectEventRelic(payload, lastSeen);
        detectGolemTurn(payload, lastSeen, preMoveSnapshot);
        seenEventIdRef.current = payload.events.reduce(
            (max, e) => Math.max(max, e.id),
            lastSeen,
        );
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

    async function submitMove(
        roleKey: RoleKey,
        move: Record<string, unknown>,
    ): Promise<GamePollResponse | null> {
        // Snapshotted before the move so the Golem Turn Spotlight (if this
        // move triggers one) can play the Golem's phase back step by step
        // instead of jumping straight to the end result.
        const preMoveSnapshot: GolemTurnSnapshot | null =
            move.type === 'end_wanderer_turn' && state
                ? {
                      golemCell: state.golem.cell,
                      wandererHp: Object.fromEntries(
                          Object.entries(state.wanderers).map(
                              ([role, wanderer]) => [role, wanderer.hp],
                          ),
                      ),
                  }
                : null;

        setProcessing(true);
        try {
            const response = await postJson<GamePollResponse>(
                games.moves.store(game.code).url,
                {
                    role_key: roleKey,
                    move,
                },
            );
            applyPoll(response, preMoveSnapshot);
            setSelectedLocationKey(null);
            setDiscardKeys(new Set());
            return response;
        } catch (err) {
            toast.error(
                err instanceof Error ? err.message : 'That move was rejected.',
            );
            return null;
        } finally {
            setProcessing(false);
        }
    }

    function wandererLabelFor(role: string): string {
        return ROLE_LABELS[role as RoleKey] ?? role;
    }

    /**
     * Routes every Relic whose "Use" needs more input than the generic
     * submit can supply — see PROMPTED_RELICS in WandererRelicPanel.
     * Each case gathers whatever extra choice(s) that Relic's engine
     * code actually reads (see GameEngine.php's matching resolveRelicXxx
     * for the exact fields), via a chain of wandererPrompt/relicPrompt/
     * boardPick dialogs, then calls submitMove with the full payload.
     */
    function handleUsePrompted(
        role: RoleKey,
        relicKey: string,
        allyRole?: string,
    ) {
        if (!state) return;
        const base = relicKey.replace(/-\d+$/, '');
        const wanderer = state.wanderers[role];
        const casterCell = wanderer.cell;
        const relicCatalog = game.relic_catalog;

        const otherWanderers = Object.keys(state.wanderers).filter(
            (r) => r !== role,
        );

        switch (base) {
            case 'holy-water': {
                const targetRole = allyRole ?? role;
                const targetEquipped = Object.values(
                    state.wanderers[targetRole].equipped,
                );
                const hasShame = targetEquipped.includes('shame_token');
                const hasScar = targetEquipped.includes('scar_token');

                if (hasShame && hasScar) {
                    setRelicPrompt({
                        title: 'Holy water',
                        description: `Remove which token from ${wandererLabelFor(targetRole)}?`,
                        candidates: [
                            { key: 'shame_token', label: 'Shame token' },
                            { key: 'scar_token', label: 'Scar token' },
                        ],
                        onConfirm: (keys) => {
                            setRelicPrompt(null);
                            submitMove(role, {
                                type: 'use_relic',
                                relic_key: relicKey,
                                target_role: allyRole,
                                remove_type: keys[0],
                            });
                        },
                    });
                } else {
                    submitMove(role, {
                        type: 'use_relic',
                        relic_key: relicKey,
                        target_role: allyRole,
                        remove_type: hasShame
                            ? 'shame_token'
                            : hasScar
                              ? 'scar_token'
                              : undefined,
                    });
                }
                return;
            }

            case 'quantum-robes': {
                setWandererPrompt({
                    title: 'Quantum robes',
                    description: 'Swap places with any other Wanderer.',
                    candidates: otherWanderers.map((r) => ({
                        role: r,
                        label: wandererLabelFor(r),
                    })),
                    onChoose: (targetRole) => {
                        setWandererPrompt(null);
                        submitMove(role, {
                            type: 'use_relic',
                            relic_key: relicKey,
                            target_role: targetRole,
                        });
                    },
                });
                return;
            }

            case 'golem-prod': {
                if (!state.golem.cell) return;
                setBoardPick({
                    description: 'Golem prod: push the Golem which way?',
                    eligible: new Set(neighbors(state.golem.cell).map(cellKey)),
                    onPick: (cell) => {
                        const direction = directionBetween(
                            state.golem.cell as Cell,
                            cell,
                        );
                        setBoardPick(null);
                        if (!direction) return;
                        submitMove(role, {
                            type: 'use_relic',
                            relic_key: relicKey,
                            direction,
                        });
                    },
                });
                return;
            }

            case 'artists-insight': {
                if (!casterCell) return;
                const directions: string[] = [];
                const stepPick = (fromCell: Cell) => {
                    setBoardPick({
                        description: `Artist's insight: move (step ${directions.length + 1} of 2) — or cancel to finish with ${directions.length}.`,
                        eligible: new Set(
                            occupiedNeighbors(fromCell, state.board).map(
                                cellKey,
                            ),
                        ),
                        onPick: (cell) => {
                            const direction = directionBetween(fromCell, cell);
                            if (!direction) return;
                            directions.push(direction);
                            if (directions.length >= 2) {
                                setBoardPick(null);
                                submitMove(role, {
                                    type: 'use_relic',
                                    relic_key: relicKey,
                                    directions,
                                });
                            } else {
                                stepPick(cell);
                            }
                        },
                    });
                };
                stepPick(casterCell);
                return;
            }

            case 'teleportation-stone': {
                const allyCandidates = (
                    allyRole ? [allyRole] : otherWanderers
                ).filter(
                    (r) =>
                        state.wanderers[r].cell &&
                        casterCell &&
                        cellKey(state.wanderers[r].cell as Cell) ===
                            cellKey(casterCell),
                );
                const pickDestination = (targetRole: string) => {
                    const shardCells = Object.entries(state.board)
                        .filter(([, cellData]) =>
                            cellData.infrastructure.includes('monolith_shard'),
                        )
                        .map(([key]) => key);
                    setBoardPick({
                        description:
                            'Teleportation stone: pick a Monolith Shard space.',
                        eligible: new Set(shardCells),
                        onPick: (cell) => {
                            setBoardPick(null);
                            submitMove(role, {
                                type: 'use_relic',
                                relic_key: relicKey,
                                target_role: targetRole,
                                cell,
                            });
                        },
                    });
                };
                if (allyRole) {
                    pickDestination(allyRole);
                } else {
                    setWandererPrompt({
                        title: 'Teleportation stone',
                        description: 'Move which Ally to a Shard space?',
                        candidates: allyCandidates.map((r) => ({
                            role: r,
                            label: wandererLabelFor(r),
                        })),
                        onChoose: (targetRole) => {
                            setWandererPrompt(null);
                            pickDestination(targetRole);
                        },
                    });
                }
                return;
            }

            case 'hands-of-deliverance': {
                setBoardPick({
                    description:
                        'Hands of deliverance: pick the space to move a Relic FROM.',
                    eligible: new Set(
                        Object.entries(state.board)
                            .filter(
                                ([, cellData]) =>
                                    cellData.relics_face_up.length > 0,
                            )
                            .map(([key]) => key),
                    ),
                    onPick: (fromCell) => {
                        const fromKey = cellKey(fromCell);
                        const pile = state.board[fromKey].relics_face_up;
                        setBoardPick(null);
                        setRelicPrompt({
                            title: 'Hands of deliverance',
                            description: 'Which Relic to move?',
                            candidates: pile.map((key, i) => ({
                                key: `${key}:${i}`,
                                label: relicCatalog[key]?.name ?? key,
                            })),
                            onConfirm: (keys) => {
                                setRelicPrompt(null);
                                const chosen = keys[0];
                                if (!chosen) return;
                                const boardRelicKey = chosen.split(':')[0];
                                setBoardPick({
                                    description:
                                        'Hands of deliverance: pick the space to move it TO.',
                                    eligible: new Set(Object.keys(state.board)),
                                    onPick: (toCell) => {
                                        setBoardPick(null);
                                        submitMove(role, {
                                            type: 'use_relic',
                                            relic_key: relicKey,
                                            from_cell: fromCell,
                                            to_cell: toCell,
                                            board_relic_key: boardRelicKey,
                                        });
                                    },
                                });
                            },
                        });
                    },
                });
                return;
            }

            case 'warlocks-robes': {
                const pickRelicAndMode = (targetRole: string) => {
                    setWandererPrompt(null);
                    const myRelics = Object.values(wanderer.equipped).filter(
                        (k): k is string =>
                            !!k &&
                            k !== 'shame_token' &&
                            k !== 'scar_token' &&
                            k.replace(/-\d+$/, '') !== 'warlocks-robes',
                    );
                    const theirRelics = Object.values(
                        state.wanderers[targetRole].equipped,
                    ).filter((k): k is string => !!k && !k.endsWith('_token'));
                    setRelicPrompt({
                        title: 'Warlock’s robes',
                        description:
                            'Pick one of your Relics for them to use, or one of theirs for you to use.',
                        candidates: [
                            ...myRelics.map((k) => ({
                                key: `ally_uses_mine:${k}`,
                                label: `They use yours: ${relicCatalog[k]?.name ?? k}`,
                            })),
                            ...theirRelics.map((k) => ({
                                key: `i_use_theirs:${k}`,
                                label: `You use theirs: ${relicCatalog[k]?.name ?? k}`,
                            })),
                        ],
                        onConfirm: (keys) => {
                            setRelicPrompt(null);
                            const chosen = keys[0];
                            if (!chosen) return;
                            const [mode, otherRelicKey] = chosen.split(':');
                            submitMove(role, {
                                type: 'use_relic',
                                relic_key: relicKey,
                                target_role: targetRole,
                                mode,
                                other_relic_key: otherRelicKey,
                            });
                        },
                    });
                };
                if (allyRole) {
                    pickRelicAndMode(allyRole);
                } else {
                    setWandererPrompt({
                        title: 'Warlock’s robes',
                        description: 'Choose an Ally.',
                        candidates: otherWanderers
                            .filter(
                                (r) =>
                                    state.wanderers[r].cell &&
                                    casterCell &&
                                    cellKey(state.wanderers[r].cell as Cell) ===
                                        cellKey(casterCell),
                            )
                            .map((r) => ({
                                role: r,
                                label: wandererLabelFor(r),
                            })),
                        onChoose: pickRelicAndMode,
                    });
                }
                return;
            }

            case 'cabalist-brew': {
                const pickRelic = (performerRole: string) => {
                    const theirRelics = Object.values(
                        state.wanderers[performerRole].equipped,
                    ).filter(
                        (k): k is string =>
                            !!k &&
                            !k.endsWith('_token') &&
                            k.replace(/-\d+$/, '') !== 'cabalist-brew' &&
                            !!relicCatalog[k]?.effect.includes('🌿'),
                    );
                    setRelicPrompt({
                        title: 'Cabalist brew',
                        description: `Which of ${wandererLabelFor(performerRole)}'s 🌿 Relics gets tripled?`,
                        candidates: theirRelics.map((k) => ({
                            key: k,
                            label: relicCatalog[k]?.name ?? k,
                        })),
                        onConfirm: (keys) => {
                            setRelicPrompt(null);
                            const chosen = keys[0];
                            submitMove(role, {
                                type: 'use_relic',
                                relic_key: relicKey,
                                target_role: performerRole,
                                other_relic_key: chosen,
                            });
                        },
                    });
                };
                pickRelic(allyRole ?? role);
                return;
            }

            case 'magistrates-robes': {
                const allTargets = [
                    ...Object.keys(state.wanderers).map((r) => ({
                        role: r,
                        label: wandererLabelFor(r),
                    })),
                    { role: 'golem', label: 'Golem' },
                ];
                setWandererPrompt({
                    title: "Magistrate's robes",
                    description: 'Move Shame FROM which Wanderer or Golem?',
                    candidates: allTargets,
                    onChoose: (fromRole) => {
                        setWandererPrompt({
                            title: "Magistrate's robes",
                            description: `Move Shame TO which Wanderer or Golem? (from ${wandererLabelFor(fromRole)})`,
                            candidates: allTargets.filter(
                                (c) => c.role !== fromRole,
                            ),
                            onChoose: (toRole) => {
                                setWandererPrompt(null);
                                submitMove(role, {
                                    type: 'use_relic',
                                    relic_key: relicKey,
                                    from_role: fromRole,
                                    to_role: toRole,
                                });
                            },
                        });
                    },
                });
                return;
            }

            case 'divine-cabalist-pyre': {
                if (!casterCell) return;
                const pile =
                    state.board[cellKey(casterCell)]?.relics_face_up ?? [];
                setRelicPrompt({
                    title: 'Divine Cabalist pyre',
                    description: 'Burn which Relics on your space?',
                    multiple: true,
                    confirmLabel: 'Burn',
                    candidates: pile.map((key, i) => ({
                        key: `${key}:${i}`,
                        label: relicCatalog[key]?.name ?? key,
                    })),
                    onConfirm: (keys) => {
                        setRelicPrompt(null);
                        submitMove(role, {
                            type: 'use_relic',
                            relic_key: relicKey,
                            board_relic_keys: keys.map((k) => k.split(':')[0]),
                        });
                    },
                });
                return;
            }

            case 'show-solidarity': {
                const golemCellKey = state.golem.cell
                    ? cellKey(state.golem.cell)
                    : null;
                const candidates: { key: string; label: string }[] = [];
                for (const [r, w] of Object.entries(state.wanderers)) {
                    if (!w.cell || cellKey(w.cell) !== golemCellKey) continue;
                    for (const [slot, relicKeyAt] of Object.entries(
                        w.equipped,
                    )) {
                        if (!relicKeyAt || relicKeyAt.endsWith('_token'))
                            continue;
                        if (relicCatalog[relicKeyAt]?.is_bound) continue;
                        candidates.push({
                            key: `${r}:${slot}:${relicKeyAt}`,
                            label: `${wandererLabelFor(r)}: ${relicCatalog[relicKeyAt]?.name ?? relicKeyAt}`,
                        });
                    }
                }
                setRelicPrompt({
                    title: 'Show solidarity',
                    description:
                        'Which Relics (across everyone sharing the Golem’s space) get trashed? Every 5, 1 Golem discard card is trashed and you gain 📜.',
                    multiple: true,
                    confirmLabel: 'Trash',
                    candidates,
                    onConfirm: (keys) => {
                        setRelicPrompt(null);
                        submitMove(role, {
                            type: 'use_relic',
                            relic_key: relicKey,
                            trashed: keys.map((k) => {
                                const [r, , relicKeyAt] = k.split(':');
                                return {
                                    role: r,
                                    relic_key: relicKeyAt,
                                };
                            }),
                        });
                    },
                });
                return;
            }

            case 'merchants-gloves': {
                setWandererPrompt({
                    title: "Merchant's gloves",
                    description: 'Take a Relic FROM which Wanderer?',
                    candidates: Object.keys(state.wanderers).map((r) => ({
                        role: r,
                        label: wandererLabelFor(r),
                    })),
                    onChoose: (fromRole) => {
                        const fromRelics = [
                            ...Object.values(
                                state.wanderers[fromRole].equipped,
                            ).filter(
                                (k): k is string =>
                                    !!k &&
                                    !k.endsWith('_token') &&
                                    !relicCatalog[k]?.is_bound,
                            ),
                            ...state.wanderers[fromRole].holding,
                        ];
                        setRelicPrompt({
                            title: "Merchant's gloves",
                            description: `Which of ${wandererLabelFor(fromRole)}'s Relics?`,
                            candidates: fromRelics.map((k) => ({
                                key: k,
                                label: relicCatalog[k]?.name ?? k,
                            })),
                            onConfirm: (keys) => {
                                setRelicPrompt(null);
                                const chosenRelic = keys[0];
                                if (!chosenRelic) return;
                                setWandererPrompt({
                                    title: "Merchant's gloves",
                                    description: `Give ${relicCatalog[chosenRelic]?.name ?? chosenRelic} to whom?`,
                                    candidates: Object.keys(state.wanderers)
                                        .filter((r) => r !== fromRole)
                                        .map((r) => ({
                                            role: r,
                                            label: wandererLabelFor(r),
                                        })),
                                    onChoose: (toRole) => {
                                        setWandererPrompt(null);
                                        submitMove(role, {
                                            type: 'use_relic',
                                            relic_key: relicKey,
                                            exchanges: [
                                                {
                                                    from_role: fromRole,
                                                    to_role: toRole,
                                                    relic_key: chosenRelic,
                                                },
                                            ],
                                        });
                                    },
                                });
                            },
                        });
                    },
                });
                return;
            }

            case 'golem-log': {
                submitMove(role, {
                    type: 'use_relic',
                    relic_key: relicKey,
                }).then((response) => {
                    if (!response) return;
                    const revealed = [...response.events]
                        .reverse()
                        .find((e) => e.type === 'golem_draw_pile_revealed');
                    const cardKeys = revealed?.payload.card_keys as
                        | string[]
                        | undefined;
                    if (cardKeys && cardKeys.length > 0) {
                        setGolemLogPeek({ role, cardKeys });
                    }
                });
                return;
            }

            default:
                return;
        }
    }

    async function peekInsightFromTheFuture(role: RoleKey, relicKey: string) {
        const response = await submitMove(role, {
            type: 'peek_location_deck',
            relic_key: relicKey,
        });

        if (response?.revealed_location_deck) {
            setInsightPeek({
                role,
                relicKey,
                deck: response.revealed_location_deck,
            });
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

    const epiphanyCasterCell = state.pending_epiphany
        ? state.wanderers[state.pending_epiphany]?.cell
        : null;
    const epiphanyCandidates = epiphanyCasterCell
        ? Object.entries(state.board)
              .filter(
                  ([key, cellData]) =>
                      key !== cellKey(epiphanyCasterCell) &&
                      cellData.relics.length > 0,
              )
              .map(([key, cellData]) => ({
                  cell: key.split(',').map(Number) as [number, number],
                  cellKey: key,
                  cellData,
              }))
        : [];

    const golemChoiceRole = state.pending_golem_card_choice?.awaiting.find(
        (role) => myRoles.includes(role),
    );
    const golemChoiceCandidates: { key: string; label: string }[] =
        golemChoiceRole &&
        state.pending_golem_card_choice?.type === 'trash_relic'
            ? Object.values(state.wanderers[golemChoiceRole].equipped)
                  .filter((k): k is string => !!k && !k.endsWith('_token'))
                  .map((k) => ({
                      key: k,
                      label: game.relic_catalog[k]?.name ?? k,
                  }))
            : golemChoiceRole &&
                state.pending_golem_card_choice?.type === 'move_direction' &&
                state.wanderers[golemChoiceRole].cell
              ? DIRECTIONS.filter(
                    (d) =>
                        cellKey(
                            neighbor(
                                state.wanderers[golemChoiceRole].cell as Cell,
                                d,
                            ),
                        ) in state.board,
                ).map((d) => ({ key: d, label: d }))
              : [];

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
        onCellClick = (cell) => {
            submitMove('emperor', {
                type: 'play_location',
                location_key: selectedLocationKey,
                cell,
            }).then((response) => {
                // You only ever play exactly 1 Location per turn, so a
                // successful play always means this step is done —
                // unlike Infrastructure (0-2, so it stays manual) or
                // Discard (a deliberate final choice).
                if (response) setEmperorStep('infrastructure');
            });
        };
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
        const placedBefore = state.infrastructure_placed_this_turn;
        onCellClick = (cell) => {
            submitMove('emperor', {
                type: 'place_infrastructure',
                infrastructure_type: selectedInfrastructureType,
                cell,
            }).then((response) => {
                // A successful placement always increments by exactly 1
                // — once that reaches the cap of 2, there's nothing left
                // to do in this step either.
                if (response && placedBefore + 1 >= 2) {
                    setSelectedInfrastructureType(null);
                    setEmperorStep('discard');
                }
            });
        };
    } else if (
        state.phase === 'wanderer' &&
        state.active_wanderer_role &&
        myRoles.includes(state.active_wanderer_role)
    ) {
        const mine = state.wanderers[state.active_wanderer_role];
        // Road: "Wanderers move from a space with a Road for free" —
        // moving off a Road space never costs an action, so it must
        // stay available even at 0 actions remaining, not just gated
        // on actions_remaining > 0 like every other move.
        const onRoad =
            mine.cell !== null &&
            state.board[cellKey(mine.cell)]?.infrastructure.includes('road');
        if (
            mine.cell &&
            mine.hp > 0 &&
            (mine.actions_remaining > 0 || onRoad)
        ) {
            highlightedCells = new Set(
                occupiedNeighbors(mine.cell, state.board).map(cellKey),
            );
            onCellClick = (cell) => {
                const direction = directionBetween(mine.cell as Cell, cell);
                if (direction) {
                    const sacrifice = state.golem.glyphs.includes('revelatory')
                        ? revelatorySacrificeHp
                        : 0;
                    submitMove(state.active_wanderer_role as RoleKey, {
                        type: 'move_wanderer',
                        direction,
                        ...(sacrifice > 0 ? { sacrifice_hp: sacrifice } : {}),
                    });
                    setRevelatorySacrificeHp(0);
                }
            };
        }
    }

    // A pending interaction's own board-cell pick takes priority over
    // the normal per-phase click behavior above — e.g. picking
    // Teleportation stone's destination instead of just moving.
    if (boardPick) {
        highlightedCells = boardPick.eligible;
        onCellClick = boardPick.onPick;
    }

    return (
        <>
            <Head title={`Game ${game.code}`} />
            <div className="flex h-dvh gap-3 overflow-hidden p-3">
                <div className="relative flex min-h-0 flex-1 items-center justify-center overflow-auto">
                    {boardPick && (
                        <div className="absolute top-2 left-1/2 z-10 flex -translate-x-1/2 items-center gap-2 rounded-md border bg-card px-3 py-1.5 text-xs shadow-md">
                            <span>{boardPick.description}</span>
                            <Button
                                size="sm"
                                variant="ghost"
                                className="h-6 px-2 text-[10px]"
                                onClick={() => setBoardPick(null)}
                            >
                                Cancel
                            </Button>
                        </div>
                    )}
                    {!boardPick &&
                        state &&
                        state.phase === 'wanderer' &&
                        state.golem.glyphs.includes('revelatory') &&
                        state.active_wanderer_role &&
                        myRoles.includes(state.active_wanderer_role) && (
                            <div className="absolute top-2 left-1/2 z-10 flex -translate-x-1/2 items-center gap-2 rounded-md border bg-card px-3 py-1.5 text-xs shadow-md">
                                <span>
                                    Revelatory: sacrifice Health for your next
                                    move?
                                </span>
                                <input
                                    type="number"
                                    min={0}
                                    max={Math.max(
                                        0,
                                        state.wanderers[
                                            state.active_wanderer_role
                                        ].hp - 1,
                                    )}
                                    value={revelatorySacrificeHp}
                                    onChange={(e) =>
                                        setRevelatorySacrificeHp(
                                            Math.max(
                                                0,
                                                parseInt(
                                                    e.target.value || '0',
                                                    10,
                                                ),
                                            ),
                                        )
                                    }
                                    className="h-6 w-14 rounded border bg-background px-1 text-xs"
                                />
                                <span className="text-muted-foreground">
                                    (+{revelatorySacrificeHp} actions)
                                </span>
                            </div>
                        )}
                    <HexBoard
                        board={state.board}
                        locationCatalog={locationCatalog}
                        relicCatalog={game.relic_catalog}
                        golemCardCatalog={game.golem_card_catalog}
                        wanderers={state.wanderers}
                        golemCell={
                            golemSpotlightSegments[golemSpotlightIndex]
                                ?.snapshotAfter.golemCell ?? state.golem.cell
                        }
                        highlightedCells={highlightedCells}
                        activeWandererRole={
                            state.phase === 'wanderer'
                                ? state.active_wanderer_role
                                : null
                        }
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
                                            Place up to{' '}
                                            {state.infrastructure_cap_this_turn}{' '}
                                            Infrastructure token
                                            {state.infrastructure_cap_this_turn ===
                                            1
                                                ? ''
                                                : 's'}{' '}
                                            from your pool (optional — click a
                                            type, then a highlighted space on
                                            the board).
                                            {state.infrastructure_cap_this_turn >
                                                2 &&
                                                " Raised by Builder's gloves."}
                                        </p>
                                        <p className="mb-1 text-sm font-medium">
                                            Infrastructure (
                                            {
                                                state.infrastructure_placed_this_turn
                                            }
                                            /
                                            {state.infrastructure_cap_this_turn}{' '}
                                            placed this turn)
                                        </p>
                                        <div className="flex flex-col gap-1">
                                            {(
                                                Object.keys(
                                                    INFRASTRUCTURE_LABELS,
                                                ) as InfrastructureType[]
                                            ).map((type) => (
                                                <Tooltip key={type}>
                                                    <TooltipTrigger asChild>
                                                        <button
                                                            type="button"
                                                            disabled={
                                                                state
                                                                    .infrastructure_pool[
                                                                    type
                                                                ] <= 0 ||
                                                                state.infrastructure_placed_this_turn >=
                                                                    state.infrastructure_cap_this_turn
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
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        {
                                                            INFRASTRUCTURE_DESCRIPTIONS[
                                                                type
                                                            ]
                                                        }
                                                    </TooltipContent>
                                                </Tooltip>
                                            ))}
                                        </div>

                                        {state
                                            .infrastructure_placed_this_turn_log
                                            .length > 0 && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="mt-2 w-full"
                                                disabled={processing}
                                                onClick={() =>
                                                    submitMove('emperor', {
                                                        type: 'undo_infrastructure_placement',
                                                    })
                                                }
                                            >
                                                ↩️ Undo last placement (
                                                {
                                                    INFRASTRUCTURE_LABELS[
                                                        state
                                                            .infrastructure_placed_this_turn_log[
                                                            state
                                                                .infrastructure_placed_this_turn_log
                                                                .length - 1
                                                        ].type
                                                    ]
                                                }
                                                )
                                            </Button>
                                        )}

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
                                                        className={`flex items-start gap-2 rounded-md border p-2 text-xs ${
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
                                                            className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-muted hover:bg-muted-foreground/20"
                                                            aria-label={`Inspect ${loc.name}`}
                                                        >
                                                            ℹ️
                                                        </button>
                                                        <label className="flex min-w-0 flex-1 items-start gap-1.5">
                                                            <Checkbox
                                                                className="mt-0.5"
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
                                                            <span className="min-w-0 flex-1">
                                                                <span className="font-medium">
                                                                    {
                                                                        loc.produces_emoji
                                                                    }{' '}
                                                                    {loc.name}
                                                                </span>
                                                                <span className="mt-0.5 block text-muted-foreground">
                                                                    {adjacencyRequirementText(
                                                                        loc,
                                                                        locationCatalog,
                                                                    )}
                                                                </span>
                                                                {loc.back_text && (
                                                                    <span className="mt-0.5 block text-muted-foreground">
                                                                        Rule:{' '}
                                                                        {
                                                                            loc.back_text
                                                                        }
                                                                    </span>
                                                                )}
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
                                        {(() => {
                                            const mine =
                                                state.wanderers[
                                                    state.active_wanderer_role as RoleKey
                                                ];
                                            const relicsHere = mine.cell
                                                ? state.board[
                                                      cellKey(mine.cell)
                                                  ].relics.length
                                                : 0;
                                            const exhausted = mine.hp <= 0;
                                            const restButton = (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={
                                                        processing ||
                                                        mine.actions_remaining <=
                                                            0 ||
                                                        mine.hp >= 15
                                                    }
                                                    onClick={() =>
                                                        submitMove(
                                                            state.active_wanderer_role as RoleKey,
                                                            { type: 'rest' },
                                                        )
                                                    }
                                                >
                                                    🌿 Rest (+2 Health)
                                                </Button>
                                            );
                                            const searchButton = (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={
                                                        processing ||
                                                        mine.actions_remaining <=
                                                            0 ||
                                                        relicsHere === 0 ||
                                                        exhausted
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
                                            );

                                            const searchWithTooltip =
                                                relicsHere > 0 && !exhausted ? (
                                                    searchButton
                                                ) : (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <span>
                                                                {searchButton}
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent>
                                                            {exhausted
                                                                ? 'At 0 Health, resting is the only action you can take.'
                                                                : 'No face-down Relics left here to search for.'}
                                                        </TooltipContent>
                                                    </Tooltip>
                                                );

                                            return (
                                                <>
                                                    {restButton}
                                                    {searchWithTooltip}
                                                </>
                                            );
                                        })()}
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
                            const activeSpotlightSegment =
                                golemSpotlightSegments[golemSpotlightIndex];
                            const wanderer = activeSpotlightSegment
                                ? {
                                      ...state.wanderers[role],
                                      hp:
                                          activeSpotlightSegment.snapshotAfter
                                              .wandererHp[role] ??
                                          state.wanderers[role].hp,
                                  }
                                : state.wanderers[role];
                            const isActive =
                                state.phase === 'wanderer' &&
                                state.active_wanderer_role === role;
                            const canAct =
                                isActive &&
                                myRoles.includes(role) &&
                                wanderer.actions_remaining > 0;
                            const tuckLocationKey = wanderer.cell
                                ? state.board[cellKey(wanderer.cell)]
                                      ?.location_key
                                : undefined;
                            const canTuck =
                                isActive &&
                                myRoles.includes(role) &&
                                (tuckLocationKey === 'bazaar' ||
                                    tuckLocationKey === 'cathedral');
                            const isActiveAndMine =
                                isActive && myRoles.includes(role);
                            const freeActions: {
                                label: string;
                                onClick: () => void;
                            }[] = [];

                            if (isActiveAndMine && wanderer.cell) {
                                const myCell = wanderer.cell;
                                const myCellKey = cellKey(myCell);
                                const myLocation =
                                    locationCatalog[
                                        state.board[myCellKey]?.location_key
                                    ];

                                // Perplexing
                                const perplexingTargetCell =
                                    state.location_just_built_cell;
                                if (
                                    state.golem.glyphs.includes('perplexing') &&
                                    perplexingTargetCell &&
                                    cellKey(perplexingTargetCell) !== myCellKey
                                ) {
                                    const targetKey =
                                        state.board[
                                            cellKey(perplexingTargetCell)
                                        ]?.location_key;
                                    freeActions.push({
                                        label: `Move to ${locationCatalog[targetKey ?? '']?.name ?? 'new Location'} (Perplexing)`,
                                        onClick: () =>
                                            submitMove(role, {
                                                type: 'move_to_new_location',
                                            }),
                                    });
                                }

                                // Parallel
                                if (
                                    state.golem.glyphs.includes('parallel') &&
                                    !state.parallel_used_this_round &&
                                    state.golem.revealed.length > 0
                                ) {
                                    freeActions.push({
                                        label: 'Skip next Golem card (Parallel)',
                                        onClick: () =>
                                            submitMove(role, {
                                                type: 'skip_golem_card',
                                            }),
                                    });
                                }

                                // Purification pools
                                if (
                                    myLocation &&
                                    state.board[myCellKey]?.location_key ===
                                        'purification-pools'
                                ) {
                                    freeActions.push({
                                        label: 'Cleanse (Purification pools)',
                                        onClick: () =>
                                            submitMove(role, {
                                                type: 'cleanse_at_purification_pools',
                                            }),
                                    });
                                }

                                // Smoldering
                                const nonBoundEquipped = Object.values(
                                    wanderer.equipped,
                                ).filter(
                                    (k): k is string =>
                                        !!k &&
                                        !k.endsWith('_token') &&
                                        !game.relic_catalog[k]?.is_bound,
                                );
                                if (
                                    state.golem.glyphs.includes('smoldering') &&
                                    nonBoundEquipped.length > 0
                                ) {
                                    freeActions.push({
                                        label: 'Trash Relic(s) for Health (Smoldering)',
                                        onClick: () =>
                                            setRelicPrompt({
                                                title: 'Smoldering',
                                                description:
                                                    'Trash any of your Relics for 2 Health each.',
                                                multiple: true,
                                                confirmLabel: 'Trash',
                                                candidates:
                                                    nonBoundEquipped.map(
                                                        (k) => ({
                                                            key: k,
                                                            label:
                                                                game
                                                                    .relic_catalog[
                                                                    k
                                                                ]?.name ?? k,
                                                        }),
                                                    ),
                                                onConfirm: (keys) => {
                                                    setRelicPrompt(null);
                                                    submitMove(role, {
                                                        type: 'trash_relic_for_health',
                                                        relic_keys: keys,
                                                    });
                                                },
                                            }),
                                    });
                                }

                                // Neighborly
                                const myGreenRelics = Object.values(
                                    wanderer.equipped,
                                ).filter(
                                    (k): k is string =>
                                        !!k &&
                                        !k.endsWith('_token') &&
                                        !!game.relic_catalog[
                                            k
                                        ]?.effect.includes('🌿'),
                                );
                                const adjacentAllies = Object.entries(
                                    state.wanderers,
                                ).filter(
                                    ([r, w]) =>
                                        r !== role &&
                                        w.cell &&
                                        occupiedNeighbors(
                                            myCell,
                                            state.board,
                                        ).some(
                                            (n) =>
                                                cellKey(n) ===
                                                cellKey(w.cell as Cell),
                                        ),
                                );
                                if (
                                    state.golem.glyphs.includes('neighborly') &&
                                    myGreenRelics.length > 0 &&
                                    adjacentAllies.length > 0
                                ) {
                                    freeActions.push({
                                        label: 'Assist an Ally (Neighborly)',
                                        onClick: () =>
                                            setWandererPrompt({
                                                title: 'Neighborly',
                                                description:
                                                    'Move to which adjacent Ally?',
                                                candidates: adjacentAllies.map(
                                                    ([r]) => ({
                                                        role: r,
                                                        label: wandererLabelFor(
                                                            r,
                                                        ),
                                                    }),
                                                ),
                                                onChoose: (toRole) => {
                                                    setWandererPrompt(null);
                                                    setRelicPrompt({
                                                        title: 'Neighborly',
                                                        description:
                                                            'Use which of your own 🌿 Relics?',
                                                        candidates:
                                                            myGreenRelics.map(
                                                                (k) => ({
                                                                    key: k,
                                                                    label:
                                                                        game
                                                                            .relic_catalog[
                                                                            k
                                                                        ]
                                                                            ?.name ??
                                                                        k,
                                                                }),
                                                            ),
                                                        onConfirm: (keys) => {
                                                            setRelicPrompt(
                                                                null,
                                                            );
                                                            const chosen =
                                                                keys[0];
                                                            if (!chosen) return;
                                                            submitMove(role, {
                                                                type: 'neighborly_assist',
                                                                to_role: toRole,
                                                                relic_key:
                                                                    chosen,
                                                            });
                                                        },
                                                    });
                                                },
                                            }),
                                    });
                                }

                                // Magnetic mail
                                const shardAdjacentAllies =
                                    adjacentAllies.filter(
                                        ([, w]) =>
                                            w.cell &&
                                            state.board[
                                                cellKey(w.cell)
                                            ]?.infrastructure.includes(
                                                'monolith_shard',
                                            ),
                                    );
                                if (
                                    Object.values(wanderer.equipped).includes(
                                        'magnetic-mail',
                                    ) &&
                                    shardAdjacentAllies.length > 0
                                ) {
                                    freeActions.push({
                                        label: 'Move to Ally on a Shard (Magnetic mail)',
                                        onClick: () =>
                                            setWandererPrompt({
                                                title: 'Magnetic mail',
                                                description:
                                                    'Move to which Ally?',
                                                candidates:
                                                    shardAdjacentAllies.map(
                                                        ([r]) => ({
                                                            role: r,
                                                            label: wandererLabelFor(
                                                                r,
                                                            ),
                                                        }),
                                                    ),
                                                onChoose: (targetRole) => {
                                                    setWandererPrompt(null);
                                                    submitMove(role, {
                                                        type: 'magnetic_mail_move',
                                                        target_role: targetRole,
                                                    });
                                                },
                                            }),
                                    });
                                }

                                // Telekinesis
                                if (
                                    Object.values(wanderer.equipped).includes(
                                        'telekenesis',
                                    ) &&
                                    state.board[
                                        myCellKey
                                    ]?.infrastructure.includes('monolith_shard')
                                ) {
                                    freeActions.push({
                                        label: 'Move anywhere (Telekinesis)',
                                        onClick: () =>
                                            setBoardPick({
                                                description:
                                                    'Telekinesis: pick any Location.',
                                                eligible: new Set(
                                                    Object.keys(state.board),
                                                ),
                                                onPick: (cell) => {
                                                    setBoardPick(null);
                                                    submitMove(role, {
                                                        type: 'telekinesis_move',
                                                        cell,
                                                    });
                                                },
                                            }),
                                    });
                                }

                                // Laborer's Jacket
                                if (
                                    Object.values(wanderer.equipped).includes(
                                        'laborers-jacket',
                                    ) &&
                                    !wanderer.laborers_jacket_used_this_round &&
                                    myLocation?.produces_emoji === '🤝'
                                ) {
                                    const otherActive = Object.keys(
                                        state.wanderers,
                                    ).filter((r) => r !== role);
                                    freeActions.push({
                                        label: "Move Allies (Laborer's Jacket)",
                                        onClick: () => {
                                            const destinations: Record<
                                                string,
                                                Cell
                                            > = {};
                                            const pickFor = (
                                                remaining: string[],
                                            ) => {
                                                if (remaining.length === 0) {
                                                    submitMove(role, {
                                                        type: 'laborers_jacket_move_allies',
                                                        destinations,
                                                    });
                                                    return;
                                                }
                                                const [next, ...rest] =
                                                    remaining;
                                                setBoardPick({
                                                    description: `Laborer's Jacket: move ${wandererLabelFor(next)} to which space?`,
                                                    eligible: new Set(
                                                        Object.keys(
                                                            state.board,
                                                        ),
                                                    ),
                                                    onPick: (cell) => {
                                                        destinations[next] =
                                                            cell;
                                                        setBoardPick(null);
                                                        pickFor(rest);
                                                    },
                                                });
                                            };
                                            pickFor(otherActive);
                                        },
                                    });
                                }
                            }

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
                                    tuckLocationKey={
                                        canTuck
                                            ? (tuckLocationKey as
                                                  | 'bazaar'
                                                  | 'cathedral')
                                            : null
                                    }
                                    freeActions={freeActions}
                                    relicUnusableReasons={
                                        state.active_wanderer_relic_reasons
                                    }
                                    onUseInsightFromTheFuture={(relicKey) =>
                                        peekInsightFromTheFuture(role, relicKey)
                                    }
                                    onUseCabalistBrand={(relicKey) =>
                                        setCabalistBrandPrompt({
                                            role,
                                            relicKey,
                                        })
                                    }
                                    onUsePrompted={(relicKey, allyRole) =>
                                        handleUsePrompted(
                                            role,
                                            relicKey,
                                            allyRole,
                                        )
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

            <EventRelicDialog
                open={eventRelicQueue.length > 0}
                role={eventRelicQueue[0]?.role ?? null}
                relicKey={eventRelicQueue[0]?.relicKey ?? null}
                entry={
                    eventRelicQueue[0]
                        ? game.relic_catalog[eventRelicQueue[0].relicKey]
                        : undefined
                }
                onDismiss={() => setEventRelicQueue((queue) => queue.slice(1))}
            />

            <LocationDetailDialog
                open={handInfoKey !== null}
                onOpenChange={(open) => !open && setHandInfoKey(null)}
                location={
                    handInfoKey ? locationCatalog[handInfoKey] : undefined
                }
                cellData={undefined}
                locationCatalog={locationCatalog}
            />

            <InsightFromTheFutureDialog
                open={insightPeek !== null}
                onOpenChange={(open) => !open && setInsightPeek(null)}
                deck={insightPeek?.deck ?? []}
                locationCatalog={locationCatalog}
                processing={processing}
                onChoose={(locationKey) => {
                    if (!insightPeek) return;
                    submitMove(insightPeek.role, {
                        type: 'use_relic',
                        relic_key: insightPeek.relicKey,
                        location_key: locationKey,
                    });
                    setInsightPeek(null);
                }}
            />

            <InvigorationDialog
                open={
                    state.pending_invigoration !== null &&
                    myRoles.includes(state.pending_invigoration)
                }
                processing={processing}
                onDecide={(take) =>
                    state.pending_invigoration &&
                    submitMove(state.pending_invigoration, {
                        type: 'resolve_invigoration',
                        take,
                    })
                }
            />

            <CabalistBrandDialog
                open={cabalistBrandPrompt !== null}
                activeGlyphs={state.golem.glyphs}
                glyphCatalog={game.glyph_catalog}
                processing={processing}
                onConfirm={(glyphKeys) => {
                    if (!cabalistBrandPrompt) return;
                    submitMove(cabalistBrandPrompt.role, {
                        type: 'use_relic',
                        relic_key: cabalistBrandPrompt.relicKey,
                        glyph_keys: glyphKeys,
                    });
                    setCabalistBrandPrompt(null);
                }}
                onCancel={() => setCabalistBrandPrompt(null)}
            />

            <WandererPickerDialog
                open={wandererPrompt !== null}
                title={wandererPrompt?.title ?? ''}
                description={wandererPrompt?.description}
                candidates={wandererPrompt?.candidates ?? []}
                processing={processing}
                onChoose={(role) => wandererPrompt?.onChoose(role)}
                onCancel={() => setWandererPrompt(null)}
            />

            <RelicPickerDialog
                open={relicPrompt !== null}
                title={relicPrompt?.title ?? ''}
                description={relicPrompt?.description}
                candidates={relicPrompt?.candidates ?? []}
                relicCatalog={game.relic_catalog}
                multiple={relicPrompt?.multiple}
                confirmLabel={relicPrompt?.confirmLabel}
                processing={processing}
                onConfirm={(keys) => relicPrompt?.onConfirm(keys)}
                onCancel={() => setRelicPrompt(null)}
            />

            <RelicPickerDialog
                open={golemLogPeek !== null}
                title="Golem log"
                description="Tuck which of the top 3 revealed cards?"
                candidates={(golemLogPeek?.cardKeys ?? []).map((key, i) => ({
                    key: `${key}:${i}`,
                    label: game.golem_card_catalog[key]?.name ?? key,
                }))}
                relicCatalog={{}}
                processing={processing}
                confirmLabel="Tuck"
                onConfirm={(keys) => {
                    if (!golemLogPeek) return;
                    const chosen = keys[0]?.split(':')[0];
                    submitMove(golemLogPeek.role, {
                        type: 'resolve_golem_log',
                        card_key: chosen,
                    });
                    setGolemLogPeek(null);
                }}
            />

            <RiftInTimeDialog
                open={
                    state.pending_rift_in_time !== null &&
                    myRoles.includes(state.pending_rift_in_time)
                }
                activeGlyphs={state.golem.glyphs}
                glyphCatalog={game.glyph_catalog}
                processing={processing}
                onChoose={(glyphKey) =>
                    state.pending_rift_in_time &&
                    submitMove(state.pending_rift_in_time, {
                        type: 'resolve_rift_in_time',
                        glyph_key: glyphKey,
                    })
                }
            />

            <EpiphanyDialog
                open={
                    state.pending_epiphany !== null &&
                    myRoles.includes(state.pending_epiphany)
                }
                candidates={epiphanyCandidates}
                locationCatalog={locationCatalog}
                processing={processing}
                onChoose={(cell) =>
                    state.pending_epiphany &&
                    submitMove(state.pending_epiphany, {
                        type: 'resolve_epiphany',
                        cell,
                    })
                }
            />

            <RelicPickerDialog
                open={!!golemChoiceRole}
                title={
                    game.golem_card_catalog[
                        state.pending_golem_card_choice?.card_key ?? ''
                    ]?.name ?? 'The Golem'
                }
                description={
                    state.pending_golem_card_choice?.type === 'trash_relic'
                        ? 'You must trash one of your equipped Relics.'
                        : 'You must flee one space.'
                }
                candidates={golemChoiceCandidates}
                relicCatalog={game.relic_catalog}
                processing={processing}
                confirmLabel={
                    state.pending_golem_card_choice?.type === 'trash_relic'
                        ? 'Trash'
                        : 'Flee'
                }
                onConfirm={(keys) => {
                    if (!golemChoiceRole || !state.pending_golem_card_choice)
                        return;
                    const key = keys[0];
                    if (!key) return;
                    submitMove(
                        golemChoiceRole,
                        state.pending_golem_card_choice.type === 'trash_relic'
                            ? {
                                  type: 'resolve_golem_card_choice',
                                  relic_key: key,
                              }
                            : {
                                  type: 'resolve_golem_card_choice',
                                  direction: key,
                              },
                    );
                }}
            />

            <GolemTurnSpotlightDialog
                open={golemSpotlightSegments.length > 0}
                segment={golemSpotlightSegments[golemSpotlightIndex] ?? null}
                index={golemSpotlightIndex}
                total={golemSpotlightSegments.length}
                activeGlyphs={state.golem.glyphs}
                glyphCatalog={game.glyph_catalog}
                golemCardCatalog={game.golem_card_catalog}
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
                    Object.entries(game.golem_card_catalog).map(([k, v]) => [
                        k,
                        v.name,
                    ]),
                )}
                onContinue={() => {
                    setGolemSpotlightIndex((i) => {
                        if (i + 1 >= golemSpotlightSegments.length) {
                            setGolemSpotlightSegments([]);
                            return 0;
                        }
                        return i + 1;
                    });
                }}
            />
        </>
    );
}

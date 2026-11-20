import { useState } from 'react';
import {
    boardPixelBounds,
    CARD_HEIGHT,
    CARD_WIDTH,
    cellFromKey,
    cellKey,
    cellTopLeft,
    type Cell,
} from '@/lib/hex';
import type {
    BoardCell,
    GolemCardCatalogEntry,
    LocationCatalogEntry,
    RelicCatalogEntry,
    WandererState,
} from '@/types/game';
import { LocationDetailDialog } from './location-detail-dialog';
import { RoleAvatar } from './role-avatar';

const INFRASTRUCTURE_ICONS: Record<string, string> = {
    monolith_shard: '✨',
    road: '🧱',
    walls: '🏰',
    fruit_tree: '🌳',
};

/**
 * The face-down pile, rendered as an actual card-back shape with the
 * remaining count on it — not a tiny inline emoji+number, which read as
 * noise rather than "a stack of cards."
 */
function FaceDownPileBadge({ count }: { count: number }) {
    return (
        <div
            className="flex h-6 w-5 shrink-0 items-center justify-center rounded-sm border border-amber-800 bg-amber-700 text-xs font-bold text-white dark:border-amber-600 dark:bg-amber-800"
            title={`${count} face-down Relic${count === 1 ? '' : 's'}`}
        >
            {count}
        </div>
    );
}

/**
 * One known, face-up Relic — same card shape as the face-down pile but
 * light/"flipped," showing its branch symbol instead of a count, so the
 * two states read as clearly different at a glance.
 */
function FaceUpRelicBadge({ symbol }: { symbol: string }) {
    return (
        <div className="flex h-6 w-5 shrink-0 items-center justify-center rounded-sm border bg-background text-xs">
            {symbol}
        </div>
    );
}

/**
 * Bazaar/Cathedral/Golem sanctuary tuck Relics; Defenders' Guild/House
 * of Cryptids tuck Golem cards — either way, a bookmark-shaped badge
 * with the count, distinct from the face-down/face-up Relic badges.
 */
function TuckedBadge({ count }: { count: number }) {
    return (
        <div
            className="flex h-6 items-center justify-center gap-0.5 rounded-sm border border-indigo-800 bg-indigo-700 px-1 text-xs font-bold text-white dark:border-indigo-600 dark:bg-indigo-800"
            title={`${count} tucked`}
        >
            🔖 {count}
        </div>
    );
}

export function HexBoard({
    board,
    locationCatalog,
    relicCatalog,
    golemCardCatalog,
    wanderers,
    golemCell,
    highlightedCells,
    activeWandererRole,
    onCellClick,
    myRoles = [],
    onPickupRelic,
}: {
    board: Record<string, BoardCell>;
    locationCatalog: Record<string, LocationCatalogEntry>;
    relicCatalog: Record<string, RelicCatalogEntry>;
    golemCardCatalog: Record<string, GolemCardCatalogEntry>;
    wanderers: Record<string, WandererState>;
    golemCell?: Cell | null;
    highlightedCells?: Set<string>;
    /** Whoever's turn it currently is — gently highlighted on the board. */
    activeWandererRole?: string | null;
    onCellClick?: (cell: Cell) => void;
    myRoles?: string[];
    onPickupRelic?: (role: string, relicKey: string) => void;
}) {
    const [infoKey, setInfoKey] = useState<string | null>(null);

    const occupiedCells = Object.keys(board).map(cellFromKey);
    const highlightCells = highlightedCells
        ? Array.from(highlightedCells).map(cellFromKey)
        : [];
    const bounds = boardPixelBounds([...occupiedCells, ...highlightCells]);

    const wandererByCell = new Map<string, string[]>();
    for (const [role, w] of Object.entries(wanderers)) {
        if (!w.cell) continue;
        const key = cellKey(w.cell);
        wandererByCell.set(key, [...(wandererByCell.get(key) ?? []), role]);
    }

    const activeWandererCellKey = activeWandererRole
        ? wanderers[activeWandererRole]?.cell &&
          cellKey(wanderers[activeWandererRole].cell as Cell)
        : null;

    return (
        <>
            <div
                className="relative"
                style={{
                    width: bounds.width,
                    height: bounds.height,
                }}
            >
                {Object.entries(board).map(([key, cellData]) => {
                    const cell = cellFromKey(key);
                    const pos = cellTopLeft(cell);
                    const location = locationCatalog[cellData.location_key];
                    const isHighlighted = highlightedCells?.has(key) ?? false;
                    const isActiveWandererCell = activeWandererCellKey === key;

                    return (
                        <div
                            key={key}
                            className={`absolute overflow-hidden rounded-md border-2 transition-colors ${
                                isHighlighted
                                    ? 'border-primary bg-primary/10'
                                    : 'border-border bg-card'
                            } ${
                                isActiveWandererCell
                                    ? 'ring-2 ring-amber-400/70 ring-offset-1 ring-offset-background'
                                    : ''
                            }`}
                            style={{
                                left: pos.x - bounds.minX,
                                top: pos.y - bounds.minY,
                                width: CARD_WIDTH,
                                height: CARD_HEIGHT,
                            }}
                        >
                            <button
                                type="button"
                                disabled={!onCellClick}
                                onClick={() => onCellClick?.(cell)}
                                className={`flex size-full flex-col items-start gap-1 p-1.5 text-left ${
                                    isHighlighted ? 'hover:bg-primary/20' : ''
                                }`}
                            >
                                <div className="flex items-baseline gap-1">
                                    <span className="text-lg">
                                        {location?.produces_emoji}
                                    </span>
                                    <span className="text-xs leading-tight font-semibold">
                                        {location?.name ??
                                            cellData.location_key}
                                    </span>
                                </div>

                                {location?.back_text && (
                                    <span className="line-clamp-3 text-[9px] leading-tight text-muted-foreground">
                                        {location.back_text}
                                    </span>
                                )}

                                <div className="mt-auto flex w-full flex-wrap items-end gap-1">
                                    {cellData.relics.length > 0 && (
                                        <FaceDownPileBadge
                                            count={cellData.relics.length}
                                        />
                                    )}
                                    {cellData.relics_face_up.map(
                                        (relicKey, i) => (
                                            <FaceUpRelicBadge
                                                key={`${relicKey}-${i}`}
                                                symbol={
                                                    relicCatalog[relicKey]
                                                        ?.symbol ?? '🎴'
                                                }
                                            />
                                        ),
                                    )}
                                    {cellData.tucked.length > 0 && (
                                        <TuckedBadge
                                            count={cellData.tucked.length}
                                        />
                                    )}
                                    {cellData.infrastructure.length > 0 && (
                                        <span className="text-xs">
                                            {[
                                                ...new Set(
                                                    cellData.infrastructure,
                                                ),
                                            ]
                                                .map(
                                                    (type) =>
                                                        INFRASTRUCTURE_ICONS[
                                                            type
                                                        ],
                                                )
                                                .join(' ')}
                                        </span>
                                    )}
                                    <div className="flex gap-1">
                                        {golemCell &&
                                            cellKey(golemCell) === key && (
                                                <RoleAvatar role="golem" />
                                            )}
                                        {(wandererByCell.get(key) ?? []).map(
                                            (role) => (
                                                <RoleAvatar
                                                    key={role}
                                                    role={
                                                        role as
                                                            | 'wanderer_a'
                                                            | 'wanderer_b'
                                                            | 'wanderer_c'
                                                    }
                                                    active={
                                                        role ===
                                                        activeWandererRole
                                                    }
                                                />
                                            ),
                                        )}
                                    </div>
                                </div>
                            </button>

                            {location && (
                                <button
                                    type="button"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        setInfoKey(key);
                                    }}
                                    className="absolute top-0.5 right-0.5 flex size-5 items-center justify-center rounded-full bg-background/80 text-[11px] leading-none hover:bg-background"
                                    aria-label={`Inspect ${location.name}`}
                                >
                                    ℹ️
                                </button>
                            )}
                        </div>
                    );
                })}

                {highlightedCells &&
                    Array.from(highlightedCells)
                        .filter((key) => !(key in board))
                        .map((key) => {
                            const cell = cellFromKey(key);
                            const pos = cellTopLeft(cell);

                            return (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => onCellClick?.(cell)}
                                    className="absolute rounded-md border-2 border-dashed border-primary bg-primary/5 hover:bg-primary/15"
                                    style={{
                                        left: pos.x - bounds.minX,
                                        top: pos.y - bounds.minY,
                                        width: CARD_WIDTH,
                                        height: CARD_HEIGHT,
                                    }}
                                />
                            );
                        })}
            </div>

            <LocationDetailDialog
                open={infoKey !== null}
                onOpenChange={(open) => !open && setInfoKey(null)}
                location={
                    infoKey
                        ? locationCatalog[board[infoKey].location_key]
                        : undefined
                }
                cellData={infoKey ? board[infoKey] : undefined}
                relicCatalog={relicCatalog}
                golemCardCatalog={golemCardCatalog}
                wanderersHere={
                    infoKey ? (wandererByCell.get(infoKey) ?? []) : []
                }
                golemHere={
                    infoKey !== null &&
                    !!golemCell &&
                    cellKey(golemCell) === infoKey
                }
                myRoleHere={
                    infoKey
                        ? ((wandererByCell.get(infoKey) ?? []).find((role) =>
                              myRoles.includes(role),
                          ) ?? null)
                        : null
                }
                onPickupRelic={onPickupRelic}
            />
        </>
    );
}

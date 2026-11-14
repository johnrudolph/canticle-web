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
    LocationCatalogEntry,
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

export function HexBoard({
    board,
    locationCatalog,
    wanderers,
    golemCell,
    highlightedCells,
    onCellClick,
}: {
    board: Record<string, BoardCell>;
    locationCatalog: Record<string, LocationCatalogEntry>;
    wanderers: Record<string, WandererState>;
    golemCell?: Cell | null;
    highlightedCells?: Set<string>;
    onCellClick?: (cell: Cell) => void;
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

                    return (
                        <div
                            key={key}
                            className={`absolute overflow-hidden rounded-md border-2 transition-colors ${
                                isHighlighted
                                    ? 'border-primary bg-primary/10'
                                    : 'border-border bg-card'
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
                                className={`flex size-full flex-col items-start justify-between p-1.5 text-left ${
                                    isHighlighted ? 'hover:bg-primary/20' : ''
                                }`}
                            >
                                <span className="text-lg">
                                    {location?.produces_emoji}
                                </span>
                                <span className="text-xs leading-tight font-semibold">
                                    {location?.name ?? cellData.location_key}
                                </span>
                                {location?.back_text && (
                                    <span className="line-clamp-3 text-[9px] leading-tight text-muted-foreground">
                                        {location.back_text}
                                    </span>
                                )}
                                {cellData.relics.length > 0 && (
                                    <span className="text-sm font-bold text-amber-600 dark:text-amber-400">
                                        🎴 {cellData.relics.length}
                                    </span>
                                )}
                                {cellData.infrastructure.length > 0 && (
                                    <span className="text-xs">
                                        {[...new Set(cellData.infrastructure)]
                                            .map(
                                                (type) =>
                                                    INFRASTRUCTURE_ICONS[type],
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
                                            />
                                        ),
                                    )}
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
                wanderersHere={
                    infoKey ? (wandererByCell.get(infoKey) ?? []) : []
                }
                golemHere={
                    infoKey !== null &&
                    !!golemCell &&
                    cellKey(golemCell) === infoKey
                }
            />
        </>
    );
}

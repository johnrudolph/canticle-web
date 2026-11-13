import {
    boardPixelBounds,
    CARD_HEIGHT,
    CARD_WIDTH,
    cellFromKey,
    cellKey,
    cellTopLeft,
    type Cell,
} from '@/lib/hex';
import type { BoardCell, LocationCatalogEntry, WandererState } from '@/types/game';

const WANDERER_COLORS: Record<string, string> = {
    wanderer_a: '#2563eb',
    wanderer_b: '#dc2626',
    wanderer_c: '#16a34a',
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
                    <button
                        key={key}
                        type="button"
                        disabled={!onCellClick}
                        onClick={() => onCellClick?.(cell)}
                        className={`absolute flex flex-col items-center justify-between overflow-hidden rounded-md border-2 p-1.5 text-center transition-colors ${
                            isHighlighted
                                ? 'border-primary bg-primary/10 hover:bg-primary/20'
                                : 'border-border bg-card'
                        }`}
                        style={{
                            left: pos.x - bounds.minX,
                            top: pos.y - bounds.minY,
                            width: CARD_WIDTH,
                            height: CARD_HEIGHT,
                        }}
                    >
                        <span className="text-lg">
                            {location?.produces_emoji}
                        </span>
                        <span className="text-xs leading-tight font-medium">
                            {location?.name ?? cellData.location_key}
                        </span>
                        {cellData.relics.length > 0 && (
                            <span className="text-[10px] text-muted-foreground">
                                🎴 {cellData.relics.length}
                            </span>
                        )}
                        <div className="flex gap-1">
                            {golemCell && cellKey(golemCell) === key && (
                                <span className="flex size-5 items-center justify-center rounded-full bg-black text-[10px] font-bold text-white dark:bg-white dark:text-black">
                                    👺
                                </span>
                            )}
                            {(wandererByCell.get(key) ?? []).map((role) => (
                                <span
                                    key={role}
                                    className="flex size-5 items-center justify-center rounded-full text-[10px] font-bold text-white"
                                    style={{
                                        backgroundColor:
                                            WANDERER_COLORS[role] ?? '#666',
                                    }}
                                >
                                    {role.replace('wanderer_', '').toUpperCase()}
                                </span>
                            ))}
                        </div>
                    </button>
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
    );
}

/**
 * Mirrors app/Game/Board.php. Display/UX only — the server is always the
 * source of truth for whether a move is actually legal.
 *
 * The city is rectangular cards laid out in offset columns so every space
 * borders 6 others. Odd columns sit half a card-height lower than even
 * columns (the designer's layout screenshot shows the middle column of
 * the starting 6 sitting lower than the flanking columns).
 */

export type Cell = [number, number];

export const DIRECTIONS = ['N', 'NE', 'SE', 'S', 'SW', 'NW'] as const;
export type Direction = (typeof DIRECTIONS)[number];

export function cellKey(cell: Cell): string {
    return `${cell[0]},${cell[1]}`;
}

export function cellFromKey(key: string): Cell {
    const [col, row] = key.split(',').map(Number);
    return [col, row];
}

function isColEven(col: number): boolean {
    return ((col % 2) + 2) % 2 === 0;
}

export function neighbor(cell: Cell, direction: Direction): Cell {
    const [col, row] = cell;
    const colEven = isColEven(col);

    switch (direction) {
        case 'N':
            return [col, row - 1];
        case 'S':
            return [col, row + 1];
        case 'NE':
            return colEven ? [col + 1, row - 1] : [col + 1, row];
        case 'SE':
            return colEven ? [col + 1, row] : [col + 1, row + 1];
        case 'NW':
            return colEven ? [col - 1, row - 1] : [col - 1, row];
        case 'SW':
            return colEven ? [col - 1, row] : [col - 1, row + 1];
    }
}

export function neighbors(cell: Cell): Cell[] {
    return DIRECTIONS.map((d) => neighbor(cell, d));
}

export function directionBetween(from: Cell, to: Cell): Direction | null {
    for (const direction of DIRECTIONS) {
        const [col, row] = neighbor(from, direction);
        if (col === to[0] && row === to[1]) return direction;
    }
    return null;
}

/** Every empty cell adjacent to at least one occupied cell. */
export function placeableCells(occupied: Record<string, unknown>): Cell[] {
    const seen = new Map<string, Cell>();

    for (const key of Object.keys(occupied)) {
        for (const n of neighbors(cellFromKey(key))) {
            const nKey = cellKey(n);
            if (!(nKey in occupied)) {
                seen.set(nKey, n);
            }
        }
    }

    return Array.from(seen.values());
}

// --- Pixel geometry for rectangular cards in the offset-column layout ---

export const CARD_WIDTH = 132;
export const CARD_HEIGHT = 176;
const GAP = 16;
const HORIZ_SPACING = CARD_WIDTH + GAP;
const VERT_SPACING = CARD_HEIGHT + GAP;
const ROW_OFFSET = VERT_SPACING / 2;

export function cellTopLeft(cell: Cell): { x: number; y: number } {
    const [col, row] = cell;
    const x = col * HORIZ_SPACING;
    const y = row * VERT_SPACING + (isColEven(col) ? 0 : ROW_OFFSET);
    return { x, y };
}

export function boardPixelBounds(cells: Cell[]): {
    width: number;
    height: number;
    minX: number;
    minY: number;
} {
    if (cells.length === 0) {
        return { width: CARD_WIDTH, height: CARD_HEIGHT, minX: 0, minY: 0 };
    }

    const points = cells.map(cellTopLeft);
    const minX = Math.min(...points.map((p) => p.x));
    const maxX = Math.max(...points.map((p) => p.x)) + CARD_WIDTH;
    const minY = Math.min(...points.map((p) => p.y));
    const maxY = Math.max(...points.map((p) => p.y)) + CARD_HEIGHT;

    return { width: maxX - minX, height: maxY - minY, minX, minY };
}

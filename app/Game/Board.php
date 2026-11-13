<?php

namespace App\Game;

/**
 * Pure hex-grid math for the city. Rectangular cards laid out in offset
 * columns so every space borders 6 others — confirmed against the
 * designer's starting-layout screenshot. Unlike a fixed board, the city
 * has no bounded shape: it starts with 6 cells and grows by exactly 1 per
 * round for 8 rounds (Carcassonne-style, anywhere adjacent to the existing
 * city). "Validity" of a cell is therefore just "is a Location placed
 * there," tracked by the caller, not by this class.
 *
 * Odd columns are shifted down half a card-height relative to even
 * columns (the opposite of beast-master-web's Board, which shifts odd
 * columns up — confirmed against the designer's layout image, where the
 * middle column of the starting 6 sits lower than the flanking columns).
 *
 * A cell is represented as [col, row]. Cells are addressed as strings
 * "col,row" (key()) wherever used as array/object keys.
 */
final class Board
{
    public const DIRECTIONS = ['N', 'NE', 'SE', 'S', 'SW', 'NW'];

    /**
     * The 6 starting cells, in the configuration from the designer's
     * layout screenshot: two flanking columns of 2, with a middle column
     * of 2 shifted down half a card-height, so the middle column's top
     * cell touches all 5 other cells.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function startingCells(): array
    {
        return [[0, 0], [0, 1], [1, 0], [1, 1], [2, 0], [2, 1]];
    }

    /**
     * @param  array{0: int, 1: int}  $cell
     */
    public static function key(array $cell): string
    {
        return "{$cell[0]},{$cell[1]}";
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function fromKey(string $key): array
    {
        [$col, $row] = explode(',', $key);

        return [(int) $col, (int) $row];
    }

    /**
     * @param  array{0: int, 1: int}  $cell
     * @return array{0: int, 1: int}
     */
    public static function neighbor(array $cell, string $direction): array
    {
        [$col, $row] = $cell;
        $colEven = self::isColEven($col);

        return match ($direction) {
            'N' => [$col, $row - 1],
            'S' => [$col, $row + 1],
            'NE' => $colEven ? [$col + 1, $row - 1] : [$col + 1, $row],
            'SE' => $colEven ? [$col + 1, $row] : [$col + 1, $row + 1],
            'NW' => $colEven ? [$col - 1, $row - 1] : [$col - 1, $row],
            'SW' => $colEven ? [$col - 1, $row] : [$col - 1, $row + 1],
            default => throw new \InvalidArgumentException("Unknown direction: {$direction}"),
        };
    }

    private static function isColEven(int $col): bool
    {
        return (($col % 2) + 2) % 2 === 0;
    }

    /**
     * @param  array{0: int, 1: int}  $cell
     * @return array<string, array{0: int, 1: int}>
     */
    public static function neighbors(array $cell): array
    {
        $result = [];

        foreach (self::DIRECTIONS as $direction) {
            $result[$direction] = self::neighbor($cell, $direction);
        }

        return $result;
    }

    /**
     * Which of the 6 directions leads from $from to $to, or null if
     * they aren't adjacent.
     *
     * @param  array{0: int, 1: int}  $from
     * @param  array{0: int, 1: int}  $to
     */
    public static function directionBetween(array $from, array $to): ?string
    {
        foreach (self::DIRECTIONS as $direction) {
            if (self::neighbor($from, $direction) === $to) {
                return $direction;
            }
        }

        return null;
    }

    /**
     * Every occupied neighbor of $cell, given the set of occupied cell
     * keys (the board's current state).
     *
     * @param  array<string, mixed>  $occupied  keyed by cell key
     * @return list<array{0: int, 1: int}>
     */
    public static function occupiedNeighbors(array $cell, array $occupied): array
    {
        return array_values(array_filter(
            self::neighbors($cell),
            fn (array $n) => array_key_exists(self::key($n), $occupied)
        ));
    }

    /**
     * Every empty cell adjacent to at least one occupied cell — the set of
     * spaces the Emperor could place a new Location onto (before resource
     * requirements are checked).
     *
     * @param  array<string, mixed>  $occupied  keyed by cell key
     * @return list<array{0: int, 1: int}>
     */
    public static function placeableCells(array $occupied): array
    {
        $candidates = [];

        foreach (array_keys($occupied) as $key) {
            foreach (self::neighbors(self::fromKey($key)) as $neighbor) {
                $neighborKey = self::key($neighbor);

                if (! array_key_exists($neighborKey, $occupied)) {
                    $candidates[$neighborKey] = $neighbor;
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * BFS shortest path (in hops) from $from to $to, walking only over
     * occupied cells (the Golem, like Wanderers, only ever stands on a
     * built Location). Null if unreachable.
     *
     * @param  array<string, mixed>  $occupied  keyed by cell key
     */
    public static function distance(array $from, array $to, array $occupied): ?int
    {
        $path = self::shortestPath($from, $to, $occupied);

        return $path === null ? null : count($path);
    }

    /**
     * The sequence of cells from (but not including) $from to $to along a
     * shortest path over occupied cells, or null if unreachable. Used to
     * step the Golem N spaces toward a target.
     *
     * @param  array<string, mixed>  $occupied  keyed by cell key
     * @return list<array{0: int, 1: int}>|null
     */
    public static function shortestPath(array $from, array $to, array $occupied): ?array
    {
        if (self::key($from) === self::key($to)) {
            return [];
        }

        $visited = [self::key($from) => true];
        $queue = [[self::key($from), []]];

        while ($queue !== []) {
            [$currentKey, $path] = array_shift($queue);

            foreach (self::occupiedNeighbors(self::fromKey($currentKey), $occupied) as $neighbor) {
                $neighborKey = self::key($neighbor);

                if (isset($visited[$neighborKey])) {
                    continue;
                }

                $newPath = [...$path, $neighbor];

                if ($neighborKey === self::key($to)) {
                    return $newPath;
                }

                $visited[$neighborKey] = true;
                $queue[] = [$neighborKey, $newPath];
            }
        }

        return null;
    }

    /**
     * Every occupied cell reachable within $maxDistance hops of $center
     * (BFS over occupied cells), including $center itself.
     *
     * @param  array<string, mixed>  $occupied  keyed by cell key
     * @return list<array{0: int, 1: int}>
     */
    public static function cellsWithinDistance(array $center, int $maxDistance, array $occupied): array
    {
        $result = [$center];
        $visited = [self::key($center) => true];
        $frontier = [$center];

        for ($i = 0; $i < $maxDistance; $i++) {
            $next = [];

            foreach ($frontier as $cell) {
                foreach (self::occupiedNeighbors($cell, $occupied) as $neighbor) {
                    $neighborKey = self::key($neighbor);

                    if (isset($visited[$neighborKey])) {
                        continue;
                    }

                    $visited[$neighborKey] = true;
                    $result[] = $neighbor;
                    $next[] = $neighbor;
                }
            }

            $frontier = $next;
        }

        return $result;
    }
}

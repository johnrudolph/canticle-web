<?php

namespace App\Game\Catalog;

use Illuminate\Support\Str;

/**
 * Loads resources/data/canticle/locations.json into Location DTOs. The
 * sheet's `print` flag is a designer-side Figma-plugin artifact, not a
 * gameplay signal — every record is live content (confirmed with the
 * designer).
 */
final class LocationCatalog
{
    /** @var array<string, Location>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, Location> keyed by Location::$key
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = config('canticle.output_path').'/locations.json';
        $rows = CatalogFile::readJson($path);

        $locations = [];

        foreach ($rows as $row) {
            $name = trim((string) $row['name']);

            if ($name === '') {
                continue;
            }

            $key = Str::slug($name);
            $costs = array_values(array_filter([
                trim((string) ($row['cost_1'] ?? '')),
                trim((string) ($row['cost_2'] ?? '')),
                trim((string) ($row['cost_3'] ?? '')),
                trim((string) ($row['cost_4'] ?? '')),
            ], fn (string $c) => $c !== ''));

            $locations[$key] = new Location(
                key: $key,
                name: $name,
                lore: trim((string) ($row['lore'] ?? '')),
                backText: self::nullableText($row['rule'] ?? null),
                produces: self::nullableText($row['produces'] ?? null),
                producesEmoji: self::nullableText($row['produces_emoji'] ?? null),
                costs: $costs,
                relicCount: self::leadingInt($row['Relics'] ?? null),
                dealsFromBranch: self::dealsFromBranch($row['Relics'] ?? null),
                isStarter: ($row['is_starter'] ?? null) === 'Show',
                hasShard: ($row['has_shard'] ?? null) === 'Show',
                hasTree: ($row['has_tree'] ?? null) === 'Show',
            );
        }

        return self::$cache = $locations;
    }

    public static function find(string $key): ?Location
    {
        return self::all()[$key] ?? null;
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Parses the leading integer off strings like "6 🎨" or "1 of each
     * type already produced by the city" (0 for the latter — free-text
     * relic grants aren't modeled yet, see rules-todo.md).
     */
    private static function leadingInt(mixed $value): int
    {
        $text = trim((string) $value);

        return preg_match('/^(\d+)/', $text, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * Which Relic deck's branch symbol a Location's pile actually comes
     * from — NOT always the same as $producesEmoji. Most Locations'
     * "Relics" column is "<count> <symbol>" where the symbol happens to
     * match what they produce (e.g. "6 🎨" for an Art Location that also
     * produces 🎨), but a couple (Beds of poison: "6 🌑" despite
     * producing nothing adjacency-wise) deal from a branch unrelated to
     * their own production. Dynamic, city-wide dealing text ("1 of each
     * type already produced by the city" — Bazaar, Library) has no fixed
     * symbol at all; those are handled by RelicDealer's own special-cased
     * dealing instead, so this returns null for them.
     */
    private static function dealsFromBranch(mixed $value): ?string
    {
        $text = trim((string) $value);
        $withoutCount = trim((string) preg_replace('/^\d+\s*/', '', $text));

        if ($withoutCount === '' || str_contains($withoutCount, 'already produced')) {
            return null;
        }

        return $withoutCount;
    }
}

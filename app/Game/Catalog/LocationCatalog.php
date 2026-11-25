<?php

namespace App\Game\Catalog;

use App\Models\Cards\Location as LocationModel;

/**
 * Loads the `locations` table into Location DTOs. The `print` flag is a
 * designer-side Figma-plugin artifact, not a gameplay signal — every
 * record is live content (confirmed with the designer).
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

        $locations = [];

        foreach (LocationModel::query()->orderBy('id')->get() as $row) {
            $costs = array_values(array_filter([
                trim((string) $row->cost_1),
                trim((string) $row->cost_2),
                trim((string) $row->cost_3),
                trim((string) $row->cost_4),
            ], fn (string $c) => $c !== ''));

            $locations[$row->key] = new Location(
                key: $row->key,
                name: $row->name,
                lore: trim((string) $row->lore),
                backText: self::nullableText($row->rule),
                produces: self::nullableText($row->produces),
                producesEmoji: self::nullableText($row->produces_emoji),
                costs: $costs,
                relicCount: self::leadingInt($row->relics),
                dealsFromBranch: self::dealsFromBranch($row->relics),
                isStarter: $row->is_starter,
                isAdvanced: $row->is_advanced,
                hasShard: $row->has_shard,
                hasTree: $row->has_tree,
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

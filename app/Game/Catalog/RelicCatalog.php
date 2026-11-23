<?php

namespace App\Game\Catalog;

use App\Models\Cards\Relic as RelicModel;

/**
 * Loads the `relics` table into Relic DTOs. Keys are frozen at creation
 * time (see SeedCanticleCards / the admin editor) rather than
 * recomputed here — GameEngine.php hardcodes hundreds of them (e.g.
 * 'dark-shackles', 'inscribe-3'), so a card's key must never change
 * after it's created, even if its name is later edited.
 */
final class RelicCatalog
{
    /** @var array<string, Relic>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, Relic> keyed by Relic::$key
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $relics = [];

        foreach (RelicModel::query()->orderBy('id')->get() as $row) {
            $effect = trim((string) $row->effect);

            $relics[$row->key] = new Relic(
                key: $row->key,
                name: $row->name,
                effect: $effect,
                slot: trim((string) $row->slot),
                slotEmoji: trim((string) $row->slot_emoji),
                symbol: trim((string) $row->symbol),
                locationName: $row->location === null || trim((string) $row->location) === '' ? null : trim((string) $row->location),
                isEvent: $row->type === 'Event',
                // "Bound (🪡)" is printed as the first line of the effect
                // text itself (no dedicated column in the sheet) — see
                // e.g. Peacemaker's spirit, Hands of deliverance.
                isBound: str_starts_with($effect, 'Bound'),
                isAction: $row->type === 'Action',
            );
        }

        return self::$cache = $relics;
    }

    public static function find(string $key): ?Relic
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @return list<string> relic keys belonging to the deck for a given
     *                      branch emoji (a Location's produces_emoji)
     */
    public static function keysForBranch(string $branchEmoji): array
    {
        return array_values(array_map(
            fn (Relic $r) => $r->key,
            array_filter(self::all(), fn (Relic $r) => $r->symbol === $branchEmoji)
        ));
    }

    /**
     * Relic keys locked to a specific Location by name (printed on the
     * physical card's back) — must be included first when that Location's
     * Relic pile is dealt, confirmed with the designer 2026-11-12.
     *
     * @return list<string>
     */
    public static function keysLockedToLocation(string $locationName): array
    {
        return array_values(array_map(
            fn (Relic $r) => $r->key,
            array_filter(self::all(), fn (Relic $r) => $r->locationName === $locationName)
        ));
    }
}

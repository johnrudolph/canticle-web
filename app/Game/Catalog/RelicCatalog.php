<?php

namespace App\Game\Catalog;

use Illuminate\Support\Str;

/**
 * Loads resources/data/canticle/relics.json into Relic DTOs. A handful of
 * names repeat (e.g. "Inscribe" appears 12 times across different slots —
 * genuinely distinct cards), so keys are slugified name + a stable
 * disambiguating suffix on repeats, in file order.
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

        $path = config('canticle.output_path').'/relics.json';
        $contents = file_get_contents($path) ?: throw new \RuntimeException("Could not read {$path}");
        $rows = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $relics = [];
        $seenSlugs = [];

        foreach ($rows as $row) {
            $name = trim((string) $row['name']);

            if ($name === '') {
                continue;
            }

            $baseSlug = Str::slug($name);
            $seenSlugs[$baseSlug] = ($seenSlugs[$baseSlug] ?? 0) + 1;
            $key = $seenSlugs[$baseSlug] === 1 ? $baseSlug : "{$baseSlug}-{$seenSlugs[$baseSlug]}";

            $locationName = trim((string) ($row['location'] ?? ''));

            $relics[$key] = new Relic(
                key: $key,
                name: $name,
                effect: trim((string) ($row['effect'] ?? '')),
                slot: trim((string) ($row['slot'] ?? '')),
                slotEmoji: trim((string) ($row['slot_emoji'] ?? '')),
                symbol: trim((string) ($row['symbol'] ?? '')),
                locationName: $locationName === '' ? null : $locationName,
                isEvent: trim((string) ($row['type'] ?? '')) === 'Event',
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

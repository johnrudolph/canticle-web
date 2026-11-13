<?php

namespace App\Game\Catalog;

use Illuminate\Support\Str;

/**
 * Loads resources/data/canticle/golem_cards.json. 3 names repeat
 * ("Self inscription" ×3, genuinely identical reprints) — same stable
 * disambiguating-suffix scheme as RelicCatalog.
 */
final class GolemCardCatalog
{
    /** @var array<string, GolemCard>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, GolemCard>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = config('canticle.output_path').'/golem_cards.json';
        $rows = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $cards = [];
        $seenSlugs = [];

        foreach ($rows as $row) {
            $name = trim((string) $row['name']);

            if ($name === '') {
                continue;
            }

            $baseSlug = Str::slug($name);
            $seenSlugs[$baseSlug] = ($seenSlugs[$baseSlug] ?? 0) + 1;
            $key = $seenSlugs[$baseSlug] === 1 ? $baseSlug : "{$baseSlug}-{$seenSlugs[$baseSlug]}";

            $cards[$key] = new GolemCard(
                key: $key,
                name: $name,
                action: trim((string) ($row['action'] ?? '')),
            );
        }

        return self::$cache = $cards;
    }

    public static function find(string $key): ?GolemCard
    {
        return self::all()[$key] ?? null;
    }
}

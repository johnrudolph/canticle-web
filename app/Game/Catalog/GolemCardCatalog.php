<?php

namespace App\Game\Catalog;

use App\Models\Cards\GolemCard as GolemCardModel;

/**
 * Loads the `golem_cards` table. 3 names repeat ("Self inscription" ×3,
 * genuinely identical reprints) — their keys were disambiguated once at
 * seed/creation time (self-inscription, self-inscription-2, -3) and are
 * frozen from then on, same as every other card type.
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

        $cards = [];

        foreach (GolemCardModel::query()->orderBy('id')->get() as $row) {
            $cards[$row->key] = new GolemCard(
                key: $row->key,
                name: $row->name,
                action: trim((string) $row->action),
            );
        }

        return self::$cache = $cards;
    }

    public static function find(string $key): ?GolemCard
    {
        return self::all()[$key] ?? null;
    }
}

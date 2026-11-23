<?php

namespace App\Game\Catalog;

use App\Models\Cards\Glyph as GlyphModel;

final class GlyphCatalog
{
    /** @var array<string, Glyph>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, Glyph>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $glyphs = [];

        foreach (GlyphModel::query()->orderBy('id')->get() as $row) {
            $glyphs[$row->key] = new Glyph(
                key: $row->key,
                name: $row->name,
                type: trim((string) $row->type),
                description: trim((string) $row->description),
            );
        }

        return self::$cache = $glyphs;
    }

    public static function find(string $key): ?Glyph
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function keysForType(string $type): array
    {
        return array_values(array_map(
            fn (Glyph $g) => $g->key,
            array_filter(self::all(), fn (Glyph $g) => $g->type === $type)
        ));
    }
}

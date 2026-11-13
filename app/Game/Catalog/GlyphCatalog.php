<?php

namespace App\Game\Catalog;

use Illuminate\Support\Str;

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

        $path = config('canticle.output_path').'/glyphs.json';
        $contents = file_get_contents($path) ?: throw new \RuntimeException("Could not read {$path}");
        $rows = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $glyphs = [];
        $seenSlugs = [];

        foreach ($rows as $row) {
            $name = trim((string) $row['Name']);

            if ($name === '') {
                continue;
            }

            $baseSlug = Str::slug($name);
            $seenSlugs[$baseSlug] = ($seenSlugs[$baseSlug] ?? 0) + 1;
            $key = $seenSlugs[$baseSlug] === 1 ? $baseSlug : "{$baseSlug}-{$seenSlugs[$baseSlug]}";

            $glyphs[$key] = new Glyph(
                key: $key,
                name: $name,
                type: trim((string) ($row['Type'] ?? '')),
                description: trim((string) ($row['Description'] ?? '')),
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

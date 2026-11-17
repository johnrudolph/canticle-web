<?php

namespace App\Game\Catalog;

use Illuminate\Support\Facades\Cache;

/**
 * Shared disk-read for every *Catalog::all() (Location/Relic/Glyph/
 * GolemCard) — these files never change within a deploy, but every
 * poll from every connected player rebuilds the full catalog payload
 * from scratch, which previously meant re-reading and re-parsing
 * ~80KB of JSON from disk on every single request. Cached by file
 * mtime so a `canticle:sync-cards` re-sync (which does change the
 * file, usually without a deploy) invalidates itself automatically —
 * no need to remember to clear a cache key by hand.
 */
final class CatalogFile
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function readJson(string $path): array
    {
        $mtime = filemtime($path);

        if ($mtime === false) {
            throw new \RuntimeException("Could not read {$path}");
        }

        return Cache::rememberForever(
            "canticle_catalog:{$path}:{$mtime}",
            function () use ($path) {
                $contents = file_get_contents($path) ?: throw new \RuntimeException("Could not read {$path}");

                return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            }
        );
    }
}

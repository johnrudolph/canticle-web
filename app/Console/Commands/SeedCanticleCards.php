<?php

namespace App\Console\Commands;

use App\Game\Catalog\CatalogFile;
use App\Models\Cards\Glyph;
use App\Models\Cards\GolemCard;
use App\Models\Cards\Location;
use App\Models\Cards\Relic;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * One-time (re-runnable) cutover from resources/data/canticle/*.json —
 * the last Google Sheets sync — into the database tables that are now
 * the real source of truth. Computes `key` with the exact same
 * slugify-plus-disambiguating-suffix scheme the *Catalog classes used
 * to apply at read time (see e.g. RelicCatalog::all()), so every key
 * already hardcoded throughout GameEngine.php (e.g. 'dark-shackles',
 * 'inscribe-3') comes out identical. Upserts by `key`, so it's safe to
 * run again.
 */
class SeedCanticleCards extends Command
{
    protected $signature = 'canticle:seed-cards';

    protected $description = "Upsert resources/data/canticle/*.json into the locations/relics/glyphs/golem_cards tables";

    public function handle(): int
    {
        $this->seedLocations();
        $this->seedRelics();
        $this->seedGlyphs();
        $this->seedGolemCards();

        return self::SUCCESS;
    }

    private function seedLocations(): void
    {
        $rows = CatalogFile::readJson(config('canticle.output_path').'/locations.json');
        $seenSlugs = [];
        $count = 0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $key = $this->nextKey($seenSlugs, $name);

            Location::updateOrCreate(['key' => $key], [
                'print' => (bool) ($row['print'] ?? false),
                'name' => $name,
                'lore' => trim((string) ($row['lore'] ?? '')),
                'rule' => trim((string) ($row['rule'] ?? '')),
                'cost_1' => trim((string) ($row['cost_1'] ?? '')),
                'cost_2' => trim((string) ($row['cost_2'] ?? '')),
                'cost_3' => trim((string) ($row['cost_3'] ?? '')),
                'cost_4' => trim((string) ($row['cost_4'] ?? '')),
                'produces' => trim((string) ($row['produces'] ?? '')),
                'produces_emoji' => trim((string) ($row['produces_emoji'] ?? '')),
                'relics' => trim((string) ($row['Relics'] ?? '')),
                'relic_names' => trim((string) ($row['Relic_names'] ?? '')),
                'is_starter' => $this->isShow($row['is_starter'] ?? null),
                'has_shard' => $this->isShow($row['has_shard'] ?? null),
                'has_tree' => $this->isShow($row['has_tree'] ?? null),
                'has_back_text' => $this->isShow($row['has_back_text'] ?? null),
                'has_relics' => $this->isShow($row['has_relics'] ?? null),
                'has_specific_relics' => $this->isShow($row['has_specific_relics'] ?? null),
                'produces_anything' => $this->isShow($row['produces_anything'] ?? null),
                'has_cost' => $this->isShow($row['has_cost'] ?? null),
                'cost_emoji' => trim((string) ($row['cost_emoji'] ?? '')),
            ]);
            $count++;
        }

        $this->info("Seeded {$count} locations.");
    }

    private function seedRelics(): void
    {
        $rows = CatalogFile::readJson(config('canticle.output_path').'/relics.json');
        $seenSlugs = [];
        $count = 0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $key = $this->nextKey($seenSlugs, $name);

            Relic::updateOrCreate(['key' => $key], [
                'print' => (bool) ($row['print'] ?? false),
                'name' => $name,
                'location' => $this->nullableText($row['location'] ?? null),
                'slot' => trim((string) ($row['slot'] ?? '')),
                'type' => trim((string) ($row['type'] ?? '')),
                'symbol' => trim((string) ($row['symbol'] ?? '')),
                'effect' => trim((string) ($row['effect'] ?? '')),
                'slot_emoji' => trim((string) ($row['slot_emoji'] ?? '')),
                'is_action' => $this->isShow($row['is_action'] ?? null),
                'is_passive' => $this->isShow($row['is_passive'] ?? null),
                'is_event' => $this->isShow($row['is_event'] ?? null),
            ]);
            $count++;
        }

        $this->info("Seeded {$count} relics.");
    }

    private function seedGlyphs(): void
    {
        $rows = CatalogFile::readJson(config('canticle.output_path').'/glyphs.json');
        $seenSlugs = [];
        $count = 0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['Name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $key = $this->nextKey($seenSlugs, $name);

            Glyph::updateOrCreate(['key' => $key], [
                'print' => (bool) ($row['Print'] ?? false),
                'name' => $name,
                'type' => trim((string) ($row['Type'] ?? '')),
                'description' => trim((string) ($row['Description'] ?? '')),
            ]);
            $count++;
        }

        $this->info("Seeded {$count} glyphs.");
    }

    private function seedGolemCards(): void
    {
        $rows = CatalogFile::readJson(config('canticle.output_path').'/golem_cards.json');
        $seenSlugs = [];
        $count = 0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $key = $this->nextKey($seenSlugs, $name);

            GolemCard::updateOrCreate(['key' => $key], [
                'print' => (bool) ($row['print'] ?? false),
                'name' => $name,
                'action' => trim((string) ($row['action'] ?? '')),
            ]);
            $count++;
        }

        $this->info("Seeded {$count} golem cards.");
    }

    /**
     * Same scheme as every *Catalog::all() today: slugify the name, and
     * if that slug has already been used in this table, append
     * "-2"/"-3"/... in encounter order.
     *
     * @param  array<string, int>  $seenSlugs
     */
    private function nextKey(array &$seenSlugs, string $name): string
    {
        $baseSlug = Str::slug($name);
        $seenSlugs[$baseSlug] = ($seenSlugs[$baseSlug] ?? 0) + 1;

        return $seenSlugs[$baseSlug] === 1 ? $baseSlug : "{$baseSlug}-{$seenSlugs[$baseSlug]}";
    }

    private function isShow(mixed $value): bool
    {
        return trim((string) $value) === 'Show';
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

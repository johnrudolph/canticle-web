<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * The local half of the card-comment and rulebook-justification
 * workflows. Three things happen:
 *
 * 1. Fetches unresolved comments from prod's dev-sync API (anything
 *    someone flagged as "this card needs a code change" or "this card
 *    was deleted, go clean up references") and prints a readable
 *    report, grouped by card type.
 * 2. Fetches unresolved rulebook revisions and prints each one's
 *    justification — meant to be quoted in the PR that updates
 *    GameEngine.php to match — then unconditionally overwrites
 *    rules/rules.md with prod's CURRENT rulebook text (regardless of
 *    whether any revision on it is resolved), same as the card JSON
 *    below: `git diff rules/rules.md` is the actual rule-text change.
 * 3. Re-fetches the full current card set from prod's public
 *    /api/cards and overwrites resources/data/canticle/*.json with it
 *    — these files aren't read by the app anymore (see
 *    App\Game\Catalog\*Catalog, which hit the database), they exist
 *    purely so `git diff` shows exactly what changed in prod between
 *    pulls, without needing DB access to see card history.
 *
 * Resolve a flagged comment/revision with canticle:resolve-comment /
 * canticle:resolve-rulebook-change once its fix has actually landed.
 */
class PullCanticleChanges extends Command
{
    protected $signature = 'canticle:pull-changes';

    protected $description = 'Pull unresolved card comments, rulebook revisions, and the current card set from prod';

    public function handle(): int
    {
        $prodUrl = $this->prodUrl();
        $apiKey = $this->apiKey();

        if ($prodUrl === null || $apiKey === null) {
            return self::FAILURE;
        }

        $this->reportComments($prodUrl, $apiKey);
        $this->reportRulebookRevisions($prodUrl, $apiKey);
        $this->refreshRulebookSnapshot($prodUrl, $apiKey);
        $this->refreshCardSnapshot($prodUrl);

        return self::SUCCESS;
    }

    private function reportComments(string $prodUrl, string $apiKey): void
    {
        $response = Http::withToken($apiKey)->get("{$prodUrl}/api/dev-sync/comments");

        if ($response->failed()) {
            $this->error("Failed to fetch comments: HTTP {$response->status()}");

            return;
        }

        $comments = $response->json('comments') ?? [];

        if ($comments === []) {
            $this->info('No unresolved comments.');

            return;
        }

        $byType = [];
        foreach ($comments as $comment) {
            $byType[$comment['card']['type'] ?? 'unknown'][] = $comment;
        }

        foreach ($byType as $type => $group) {
            $this->line('');
            $this->line("<fg=yellow;options=bold>{$type}</>");

            foreach ($group as $comment) {
                $label = $comment['kind'] === 'deletion' ? '<fg=red>[deletion]</>' : '[comment]';
                $cardName = $comment['card']['name'] ?? '(unknown card)';
                $cardKey = $comment['card']['key'] ?? '?';

                $this->line("  #{$comment['id']} {$label} {$cardName} ({$cardKey})");
                $this->line("      {$comment['body']}");
            }
        }

        $this->line('');
        $this->info(count($comments).' unresolved comment(s). Resolve with: php artisan canticle:resolve-comment {id}');
    }

    private function reportRulebookRevisions(string $prodUrl, string $apiKey): void
    {
        $response = Http::withToken($apiKey)->get("{$prodUrl}/api/dev-sync/rulebook-revisions");

        if ($response->failed()) {
            $this->error("Failed to fetch rulebook revisions: HTTP {$response->status()}");

            return;
        }

        $revisions = $response->json('revisions') ?? [];

        if ($revisions === []) {
            return;
        }

        $this->line('');
        $this->line('<fg=yellow;options=bold>rulebook</>');

        foreach ($revisions as $revision) {
            $this->line("  #{$revision['id']} {$revision['created_at']}");
            $this->line("      {$revision['justification']}");
        }

        $this->line('');
        $this->info(count($revisions).' unresolved rulebook revision(s). Resolve with: php artisan canticle:resolve-rulebook-change {id}');
    }

    private function refreshRulebookSnapshot(string $prodUrl, string $apiKey): void
    {
        $response = Http::withToken($apiKey)->get("{$prodUrl}/api/dev-sync/rulebook");

        if ($response->failed()) {
            $this->error("Failed to fetch the current rulebook: HTTP {$response->status()}");

            return;
        }

        File::put(base_path('rules/rules.md'), $response->json('content') ?? '');
        $this->info('Refreshed rules/rules.md from prod — check `git diff` for the actual rule-text change.');
    }

    /**
     * GET /api/cards returns each card's raw DB columns (snake_case,
     * real booleans). canticle:seed-cards — which DatabaseSeeder runs
     * on every fresh migrate/test-boot — still expects the original
     * legacy Sheets-export shape ("Show"/"Hide" strings, PascalCase
     * glyph columns, a capitalized "Relics" column, etc.), since these
     * same files are its seed input, not just a display snapshot. The
     * two shapes must stay compatible, so this translates DB shape back
     * into legacy shape on the way down rather than writing the raw
     * API response verbatim.
     */
    private function refreshCardSnapshot(string $prodUrl): void
    {
        $response = Http::get("{$prodUrl}/api/cards");

        if ($response->failed()) {
            $this->error("Failed to fetch card snapshot: HTTP {$response->status()}");

            return;
        }

        $cards = $response->json();
        $outputPath = config('canticle.output_path');
        File::ensureDirectoryExists($outputPath);

        $transformFor = [
            'locations' => fn (array $row) => [
                'print' => $row['print'],
                'name' => $row['name'],
                'Relics' => $row['relics'] ?? '',
                'lore' => $row['lore'] ?? '',
                'rule' => $row['rule'] ?? '',
                'cost_1' => $row['cost_1'] ?? '',
                'cost_2' => $row['cost_2'] ?? '',
                'cost_3' => $row['cost_3'] ?? '',
                'cost_4' => $row['cost_4'] ?? '',
                'produces' => $row['produces'] ?? '',
                'has_shard' => $row['has_shard'] ? 'Show' : 'Hide',
                'has_tree' => $row['has_tree'] ? 'Show' : 'Hide',
                'has_back_text' => $row['has_back_text'] ? 'Show' : 'Hide',
                'Relic_names' => $row['relic_names'] ?? '',
                'has_relics' => $row['has_relics'] ? 'Show' : 'Hide',
                'has_specific_relics' => $row['has_specific_relics'] ? 'Show' : 'Hide',
                'produces_anything' => $row['produces_anything'] ? 'Show' : 'Hide',
                'produces_emoji' => $row['produces_emoji'] ?? '',
                'has_cost' => $row['has_cost'] ? 'Show' : 'Hide',
                'cost_emoji' => $row['cost_emoji'] ?? '',
                'is_starter' => $row['is_starter'] ? 'Show' : 'Hide',
            ],
            'relics' => fn (array $row) => [
                'print' => $row['print'],
                'name' => $row['name'],
                'location' => $row['location'] ?? '',
                'slot' => $row['slot'] ?? '',
                'type' => $row['type'] ?? '',
                'symbol' => $row['symbol'] ?? '',
                'effect' => $row['effect'] ?? '',
                'slot_emoji' => $row['slot_emoji'] ?? '',
                'is_action' => $row['is_action'] ? 'Show' : 'Hide',
                'is_passive' => $row['is_passive'] ? 'Show' : 'Hide',
                'is_event' => $row['is_event'] ? 'Show' : 'Hide',
            ],
            'glyphs' => fn (array $row) => [
                'Print' => $row['print'],
                'Name' => $row['name'],
                'Type' => $row['type'] ?? '',
                'Description' => $row['description'] ?? '',
            ],
            'golem_cards' => fn (array $row) => [
                'print' => $row['print'],
                'name' => $row['name'],
                'action' => $row['action'] ?? '',
            ],
        ];

        $fileFor = [
            'locations' => 'locations.json',
            'relics' => 'relics.json',
            'glyphs' => 'glyphs.json',
            'golem_cards' => 'golem_cards.json',
        ];

        foreach ($fileFor as $key => $filename) {
            $rows = array_map($transformFor[$key], $cards[$key] ?? []);

            File::put(
                "{$outputPath}/{$filename}",
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            );
        }

        $this->info('Refreshed resources/data/canticle/*.json from prod — check `git diff` for what changed.');
    }

    private function prodUrl(): ?string
    {
        $url = config('canticle.prod_url');

        if (! is_string($url) || $url === '') {
            $this->error('PROD_URL is not configured.');

            return null;
        }

        return str_starts_with($url, 'http') ? rtrim($url, '/') : 'https://'.rtrim($url, '/');
    }

    private function apiKey(): ?string
    {
        $key = config('debug_api.key');

        if (! is_string($key) || $key === '') {
            $this->error('DEBUG_API_KEY is not configured.');

            return null;
        }

        return $key;
    }
}

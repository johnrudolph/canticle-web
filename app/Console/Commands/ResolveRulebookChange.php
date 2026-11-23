<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Reports back to prod that a rulebook revision's justification has
 * been acted on — the matching engine change landed, typically in the
 * PR this revision's justification was quoted in.
 */
class ResolveRulebookChange extends Command
{
    protected $signature = 'canticle:resolve-rulebook-change {id} {--note=}';

    protected $description = "Mark a rulebook revision resolved on prod, via the dev-sync API";

    public function handle(): int
    {
        $prodUrl = config('canticle.prod_url');
        $apiKey = config('debug_api.key');

        if (! is_string($prodUrl) || $prodUrl === '') {
            $this->error('PROD_URL is not configured.');

            return self::FAILURE;
        }

        if (! is_string($apiKey) || $apiKey === '') {
            $this->error('DEBUG_API_KEY is not configured.');

            return self::FAILURE;
        }

        $normalizedUrl = str_starts_with($prodUrl, 'http') ? rtrim($prodUrl, '/') : 'https://'.rtrim($prodUrl, '/');
        $id = $this->argument('id');

        $response = Http::withToken($apiKey)->post("{$normalizedUrl}/api/dev-sync/rulebook-revisions/{$id}/resolve", [
            'note' => $this->option('note'),
        ]);

        if ($response->failed()) {
            $this->error("Failed to resolve rulebook revision #{$id}: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $this->info("Rulebook revision #{$id} marked resolved.");

        return self::SUCCESS;
    }
}

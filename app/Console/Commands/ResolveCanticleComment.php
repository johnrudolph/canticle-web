<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Reports back to prod that a comment from canticle:pull-changes has
 * been handled — the fix landed in GameEngine.php, or (for kind=deletion
 * comments) the dead references were cleaned up.
 */
class ResolveCanticleComment extends Command
{
    protected $signature = 'canticle:resolve-comment {id} {--note=}';

    protected $description = "Mark a card comment resolved on prod, via the dev-sync API";

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

        $response = Http::withToken($apiKey)->post("{$normalizedUrl}/api/dev-sync/comments/{$id}/resolve", [
            'note' => $this->option('note'),
        ]);

        if ($response->failed()) {
            $this->error("Failed to resolve comment #{$id}: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $this->info("Comment #{$id} marked resolved.");

        return self::SUCCESS;
    }
}

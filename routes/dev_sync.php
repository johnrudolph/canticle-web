<?php

use App\Http\Controllers\Api\DevSyncController;
use App\Http\Middleware\EnsureDebugApiKey;
use Illuminate\Support\Facades\Route;

/**
 * Prod -> local half of the card-comment and rulebook-justification
 * workflows. Gated by the same DEBUG_API_KEY bearer token as
 * /api/debug/* — canticle:pull-changes / canticle:resolve-comment /
 * canticle:resolve-rulebook-change (app/Console/Commands) are the
 * local side.
 */
Route::middleware([EnsureDebugApiKey::class, 'throttle:60,1'])->prefix('dev-sync')->group(function () {
    Route::get('comments', [DevSyncController::class, 'index']);
    Route::post('comments/{comment}/resolve', [DevSyncController::class, 'resolve']);

    Route::get('rulebook', [DevSyncController::class, 'rulebook']);
    Route::get('rulebook-revisions', [DevSyncController::class, 'rulebookRevisions']);
    Route::post('rulebook-revisions/{revision}/resolve', [DevSyncController::class, 'resolveRulebookRevision']);
});

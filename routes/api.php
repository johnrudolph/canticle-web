<?php

use App\Http\Controllers\Api\CardsController;
use App\Http\Controllers\Api\DebugController;
use App\Http\Middleware\EnsureDebugApiKey;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureDebugApiKey::class, 'throttle:60,1'])->prefix('debug')->group(function () {
    Route::get('games', [DebugController::class, 'index']);
    Route::get('games/{game}', [DebugController::class, 'show']);
});

// Public, no auth — read-only published card data, consumed by the
// Figma plugin and anything else that wants the current card set.
Route::middleware(['throttle:60,1'])->get('cards', [CardsController::class, 'index']);

require __DIR__.'/dev_sync.php';

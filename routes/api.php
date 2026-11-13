<?php

use App\Http\Controllers\Api\DebugController;
use App\Http\Middleware\EnsureDebugApiKey;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureDebugApiKey::class, 'throttle:60,1'])->prefix('debug')->group(function () {
    Route::get('games', [DebugController::class, 'index']);
    Route::get('games/{game}', [DebugController::class, 'show']);
});

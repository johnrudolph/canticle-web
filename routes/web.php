<?php

use App\Http\Controllers\LobbyController;
use App\Http\Middleware\EnsureGuestIdentity;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureGuestIdentity::class])->group(function () {
    Route::get('/', [LobbyController::class, 'index'])->name('home');
    Route::post('games', [LobbyController::class, 'store'])->name('games.store');
    Route::post('games/join/{code}', [LobbyController::class, 'join'])->name('games.join');
});

require __DIR__.'/games.php';

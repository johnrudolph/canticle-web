<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\GameRoleController;
use App\Http\Middleware\EnsureGuestIdentity;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureGuestIdentity::class])->prefix('games/{game:code}')->name('games.')->group(function () {
    Route::get('/', [GameController::class, 'show'])->name('show');
    Route::get('poll', [GameController::class, 'pollState'])->name('poll');
    Route::post('moves', [GameController::class, 'storeMove'])->name('moves.store');
    Route::post('roles/{roleKey}/claim', [GameRoleController::class, 'claim'])->name('roles.claim');
    Route::post('roles/{roleKey}/release', [GameRoleController::class, 'release'])->name('roles.release');
    Route::post('roles/{roleKey}/ready', [GameRoleController::class, 'ready'])->name('roles.ready');
    Route::post('start', [GameRoleController::class, 'start'])->name('start');
    Route::post('abandon', [GameRoleController::class, 'abandon'])->name('abandon');
});

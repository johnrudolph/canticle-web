<?php

use App\Http\Controllers\LobbyController;
use App\Http\Middleware\EnsureGuestIdentity;
use App\Http\Middleware\ForgetStaleGuestIdentity;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::middleware([EnsureGuestIdentity::class])->group(function () {
    Route::get('/', [LobbyController::class, 'index'])->name('home');
    Route::post('games', [LobbyController::class, 'store'])->name('games.store');
    Route::post('games/join/{code}', [LobbyController::class, 'join'])->name('games.join');
});

// Re-registered without Fortify's default `guest` middleware (see
// FortifyServiceProvider — every visitor here is already a real
// authenticated "guest" User, which that middleware would treat as
// already-logged-in and bounce away from the form entirely).
Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
Route::post('login', [AuthenticatedSessionController::class, 'store'])
    ->middleware(['throttle:login', ForgetStaleGuestIdentity::class])
    ->name('login.store');
Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

require __DIR__.'/games.php';
require __DIR__.'/admin.php';

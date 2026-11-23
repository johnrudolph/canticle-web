<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

/**
 * Registration, password resets, email verification, 2FA, and passkeys are
 * all disabled (config/fortify.php `features` is empty) — every visitor is
 * a silently-created guest account, see EnsureGuestIdentity. The base
 * login/logout routes are real now, though: they're how the one seeded
 * admin (hello@catacombian.com, see DatabaseSeeder) reaches /admin, the
 * card editor.
 *
 * Fortify auto-registers /login behind a `guest` middleware — but every
 * visitor here is ALREADY a real authenticated User (the silently-created
 * guest), so that middleware would reject every single visitor before
 * they ever see the login form, bouncing them back to `/`. ignoreRoutes()
 * + our own routes (routes/web.php) without that gate is the fix — same
 * controllers, just reachable while "logged in" as a guest.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        Fortify::loginView(fn () => Inertia::render('admin/login'));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}

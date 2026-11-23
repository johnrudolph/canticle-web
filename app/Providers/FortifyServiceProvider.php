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
 * card editor. Nothing else in the app ever links to /login.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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

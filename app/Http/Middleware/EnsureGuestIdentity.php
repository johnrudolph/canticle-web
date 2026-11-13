<?php

namespace App\Http\Middleware;

use App\Game\GuestNames;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets anyone play without signing up: if the visitor isn't authenticated
 * at all, silently creates a guest account and logs them in with a
 * long-lived "remember" cookie, so the identity sticks across visits in
 * the same browser. Applied globally (see bootstrap/app.php) — there's no
 * real auth flow anywhere in this app, every request just needs *some*
 * User to own seats and games.
 */
class EnsureGuestIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            $guest = User::create([
                'name' => GuestNames::random(),
                'email' => 'guest-'.Str::uuid().'@guests.local',
                'password' => Hash::make(Str::random(40)),
                'is_guest' => true,
            ]);

            Auth::login($guest, remember: true);
        }

        return $next($request);
    }
}

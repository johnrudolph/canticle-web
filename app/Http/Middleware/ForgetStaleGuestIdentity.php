<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs just before a real login attempt is processed. EnsureGuestIdentity
 * silently signs every unauthenticated visitor in as a guest, with a
 * long-lived "remember" cookie — and the admin login form never sends a
 * `remember` field, so Auth::login() during a real login never touches or
 * clears that cookie. Left alone, once the session itself later expires,
 * SessionGuard falls back to that stale guest remember cookie instead of
 * actually leaving the visitor logged out — which looks like the admin
 * card editor "logging out" after under an hour (really: silently back to
 * a non-admin guest, 403'd by EnsureIsAdmin) rather than a clean bounce to
 * the login page. Logging the guest out here, before the real credentials
 * are even checked, clears that cookie so a fresh login starts clean.
 */
class ForgetStaleGuestIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->is_guest) {
            Auth::logout();
        }

        return $next($request);
    }
}

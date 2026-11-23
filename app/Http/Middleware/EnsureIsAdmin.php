<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every /admin/* route (the card editor). Fails closed: no
 * authenticated user, or an authenticated-but-non-admin user (including
 * every guest EnsureGuestIdentity silently creates elsewhere), gets 403.
 */
class EnsureIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_admin) {
            abort(403);
        }

        return $next($request);
    }
}

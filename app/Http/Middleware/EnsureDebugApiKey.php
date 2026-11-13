<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the read-only debug API (routes/api.php) behind DEBUG_API_KEY.
 * Never falls back to an empty-string comparison: if the key isn't
 * configured, every request is rejected rather than silently open.
 */
class EnsureDebugApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('debug_api.key');
        $provided = $request->bearerToken();

        if (! is_string($configured) || $configured === '' || ! is_string($provided) || ! hash_equals($configured, $provided)) {
            abort(401, 'Invalid or missing debug API key.');
        }

        return $next($request);
    }
}

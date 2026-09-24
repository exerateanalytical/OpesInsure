<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * /api is JSON-only: a client that omits "Accept: application/json" (curl,
 * a browser, a misconfigured SDK) must still get a JSON 401/404/422 instead
 * of a redirect to a non-existent login route (which surfaced as a 500).
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $accept = (string) $request->headers->get('Accept', '');
        // File endpoints (signed PDF downloads) still stream their file; only
        // error rendering is affected.
        if (! str_contains($accept, 'json')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}

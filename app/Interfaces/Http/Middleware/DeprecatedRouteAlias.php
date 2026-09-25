<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a route as a deprecated alias of a canonical route (REQ-DUP-*).
 *
 * The alias keeps working (installed mobile builds still call some of them)
 * but every response carries `Deprecation: true` and a
 * `Link: </api/v1/{canonical}>; rel="successor-version"` header, and every
 * call is logged so we can see when usage reaches zero and delete the alias.
 *
 * Usage: ->middleware(DeprecatedRouteAlias::using('support-tickets/{ticket}/transitions', 'REQ-DUP-001'))
 *
 * tests/Architecture/RouteDuplicationTest.php treats any route carrying this
 * middleware as an alias; every other pair of routes that resolves to the
 * same controller action fails the build.
 */
final class DeprecatedRouteAlias
{
    public static function using(string $canonical, string $requirement): string
    {
        return self::class.':'.$canonical.','.$requirement;
    }

    public function handle(Request $request, Closure $next, string $canonical, string $requirement = ''): Response
    {
        $canonicalPath = '/api/v1/'.ltrim($canonical, '/');

        Log::warning('deprecated_route_alias', [
            'requirement' => $requirement,
            'alias' => $request->route()?->uri(),
            'canonical' => $canonicalPath,
            'method' => $request->method(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'app_version' => $request->header('X-App-Version'),
        ]);

        $response = $next($request);
        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Link', '<'.$canonicalPath.'>; rel="successor-version"');

        return $response;
    }
}

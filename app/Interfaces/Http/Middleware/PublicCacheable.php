<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in for immutable public caching. Every API response is `no-store, private`
 * (SecurityHeaders, EnforceJsonApi) except a response whose controller marked
 * the request as content-addressed (e.g. a letterhead logo requested with its
 * `?v=<content hash>`): that one keeps the controller's Cache-Control.
 */
final class PublicCacheable
{
    public const ATTRIBUTE = 'opes.public_cacheable';

    public const IMMUTABLE = 'public, max-age=31536000, immutable';

    public static function mark(Request $request): void
    {
        $request->attributes->set(self::ATTRIBUTE, true);
    }

    public static function applies(Request $request, Response $response): bool
    {
        return $request->attributes->get(self::ATTRIBUTE) === true && $response->getStatusCode() === 200;
    }
}

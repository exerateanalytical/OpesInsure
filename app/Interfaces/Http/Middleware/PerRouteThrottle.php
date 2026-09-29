<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` for a signed-in user counts per route. Laravel keys the numeric form by the user id alone, so every
 * throttled route shared ONE bucket and the smallest limit won: a customer who saved a claim draft a few times and then
 * uploaded photos (POST /mobile/documents is throttle:10,1) got 429 on the upload, a KYC round followed by a complaint
 * (throttle:5,1) was refused, etc. (launch R2 web journey). Guests keep Laravel's per-IP key (shared across the auth
 * routes on purpose). Named limiters (throttle:api) are unaffected.
 */
final class PerRouteThrottle extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $signature = parent::resolveRequestSignature($request);
        $route = $request->route();

        return $request->user() && $route ? sha1($signature.'|'.implode(',', $route->methods()).'|'.$route->uri()) : $signature;
    }
}

<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` for a signed-in user counts per route. Laravel keys the numeric form by the user id alone, so every
 * throttled route shared ONE bucket and the smallest limit won: a customer who saved a claim draft a few times and then
 * uploaded photos (POST /mobile/documents is throttle:10,1) got 429 on the upload, a KYC round followed by a complaint
 * (throttle:5,1) was refused, etc. (launch R2 web journey). Named limiters (throttle:api) are unaffected.
 *
 * Guests: read requests (GET/HEAD) are also counted per route. Otherwise every anonymous GET with a numeric throttle
 * (institution logos at 120/min, the directory at 300/min, page shells) filled the same per-IP bucket, and a visitor
 * browsing ~20 pages then hit 429 on /verify (30/min) — worse behind shared mobile-carrier NAT (live QA 2026-09-30 #14).
 * Guest writes (POST/PUT/PATCH/DELETE) keep Laravel's shared per-IP key on purpose, so auth/OTP/contact limits stay
 * strict across those routes.
 */
final class PerRouteThrottle extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $signature = parent::resolveRequestSignature($request);
        $route = $request->route();

        if (! $route) {
            return $signature;
        }

        if ($request->user() || $request->isMethodSafe()) {
            return sha1($signature.'|'.implode(',', $route->methods()).'|'.$route->uri());
        }

        return $signature;
    }
}

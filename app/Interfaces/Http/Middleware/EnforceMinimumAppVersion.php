<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side enforcement of config('mobile_runtime.release.minimum_version')
 * — the "beyond just reporting it in bootstrap" half of minimum-version
 * gating (CLAUDE_MERGE_GUIDE.md, Patch 8 "Rollout": "Runtime bootstrap may
 * disable affected capabilities, but it never reverses server financial or
 * insurance state" — i.e. the server, not just the client, has to be able
 * to say no).
 *
 * Deliberately opt-in per request rather than hard-requiring the header:
 * it only rejects when the caller DOES send X-App-Version and that version
 * is below the minimum. Prepended to the global api() middleware stack
 * (see bootstrap/app.php) so it runs ahead of auth/tenant resolution and
 * covers every mobile route with zero per-route wiring — safe to do
 * globally specifically because it is a no-op for every request that omits
 * the header, which covers the staff Filament panel, partner
 * client-credentials integration calls and webhooks today.
 *
 * Honest gap: as of Patch 7/8, the shipped Expo client's api() helper (see
 * overlay/src/api/client.ts) does not yet attach X-App-Version to every
 * request — it only ever sends its version as a query parameter to
 * GET /mobile/runtime/bootstrap. This middleware is real, tested,
 * dormant infrastructure that starts actively blocking outdated traffic
 * the moment a client update adds the header; see the batch report.
 */
final class EnforceMinimumAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientVersion = $request->header('X-App-Version');

        if ($clientVersion && version_compare($clientVersion, (string) config('mobile_runtime.release.minimum_version'), '<')) {
            return response()->json([
                'message' => __('wave12.app_version_unsupported'),
                'code' => 'APP_VERSION_UNSUPPORTED',
                'minimum_version' => config('mobile_runtime.release.minimum_version'),
            ], 426);
        }

        return $next($request);
    }
}

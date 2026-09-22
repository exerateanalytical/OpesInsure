<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Application\Audit\AuditWriter;
use App\Models\IntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,RateLimiter};
use Symfony\Component\HttpFoundation\Response;

/**
 * The sole auth gate for partner routes (they carry no 'auth:api', since
 * that middleware unconditionally requires a resolvable user() and a
 * client-credentials token deliberately has none). Auth::guard('api')
 * ->client() performs Passport's own full bearer-token validation
 * independently — proves the token is valid, unexpired and unrevoked, and
 * resolves the OAuth client. Everything after that is this platform's own
 * authorization: is the matching IntegrationClient active, does it hold
 * the required scope, is the caller's IP allowed, is it under its rate
 * limit. integration_clients is the authorization source of truth
 * regardless of what the token itself claims — a revoked or suspended
 * connection is rejected even with an otherwise-valid token.
 */
final class AuthenticateIntegrationClient
{
    public function handle(Request $request, Closure $next, string $requiredScope): Response
    {
        $oauthClient = Auth::guard('api')->client();

        if ($oauthClient === null) {
            app(AuditWriter::class)->record('integration.request.denied', 'integration_client', null, ['scope' => $requiredScope, 'ip' => $request->ip()], 'no_valid_token');

            return response()->json(['error' => 'unauthenticated', 'message' => 'A valid bearer token is required.'], 401);
        }

        $client = IntegrationClient::where('oauth_client_id', $oauthClient->getKey())->first();

        $denialReason = match (true) {
            $client === null => 'unknown_client',
            $client->status !== 'ACTIVE' => 'connection_not_active',
            $requiredScope !== '*' && ! $client->hasScope($requiredScope) => 'scope_not_granted',
            $client->allowed_ips && ! in_array($request->ip(), $client->allowed_ips, true) => 'ip_not_allowed',
            ! $this->withinRateLimit($client) => 'rate_limited',
            default => null,
        };

        if ($denialReason !== null) {
            app(AuditWriter::class)->record('integration.request.denied', 'integration_client', $client?->id, ['scope' => $requiredScope, 'ip' => $request->ip()], $denialReason);

            return response()->json(['error' => 'access_denied', 'message' => 'Request denied.'], $denialReason === 'rate_limited' ? 429 : 403);
        }

        $client->update(['last_used_at' => now()]);
        app(AuditWriter::class)->record('integration.request.allowed', 'integration_client', $client->id, ['scope' => $requiredScope]);

        $request->attributes->set('integration_client', $client);

        return $next($request);
    }

    private function withinRateLimit(IntegrationClient $client): bool
    {
        return RateLimiter::attempt(
            'integration-client:'.$client->id,
            $client->rate_limit_per_minute,
            fn () => true,
            60,
        );
    }
}

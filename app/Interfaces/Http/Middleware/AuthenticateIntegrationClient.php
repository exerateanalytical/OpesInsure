<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Application\Audit\AuditWriter;
use App\Application\Integrations\Developer\{DeveloperPortalService,OAuthScopeCatalogue};
use App\Models\IntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB,RateLimiter};
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
 *
 * REQ-API-006 (agent B3): the token may also belong to an additional key of the
 * client (integration_client_keys — e.g. a sandbox key, usable from SANDBOX_ENABLED
 * onwards with its own sandbox rate limit); every decision is metered per client /
 * environment / scope; and a call carrying X-OpesInsure-On-Behalf-Of: <tenant id>
 * needs that tenant's live consent covering the required scope (delegated access).
 */
final class AuthenticateIntegrationClient
{
    public const ON_BEHALF_OF_HEADER = 'X-OpesInsure-On-Behalf-Of';

    public function handle(Request $request, Closure $next, string $requiredScope): Response
    {
        $oauthClient = Auth::guard('api')->client();

        if ($oauthClient === null) {
            app(AuditWriter::class)->record('integration.request.denied', 'integration_client', null, ['scope' => $requiredScope, 'ip' => $request->ip()], 'no_valid_token');

            return response()->json(['error' => 'unauthenticated', 'message' => 'A valid bearer token is required.'], 401);
        }

        $portal = app(DeveloperPortalService::class);
        $credential = $portal->resolveCredential((string) $oauthClient->getKey());
        $client = $credential['client'] ?? null;
        $environment = $credential['environment'] ?? 'production';
        $isKey = ($credential['key_id'] ?? null) !== null;
        $allowedStatuses = $isKey && $environment === 'sandbox' ? DeveloperPortalService::SANDBOX_STATUSES : ['ACTIVE'];
        $onBehalfOf = $request->header(self::ON_BEHALF_OF_HEADER);
        $consent = null;

        $denialReason = match (true) {
            $client === null => 'unknown_client',
            ! in_array($client->status, $allowedStatuses, true) => 'connection_not_active',
            $requiredScope !== '*' && ! $client->hasScope($requiredScope) => 'scope_not_granted',
            $client->allowed_ips && ! in_array($request->ip(), $client->allowed_ips, true) => 'ip_not_allowed',
            $onBehalfOf !== null && ($consent = $this->consent($portal, $client, (string) $onBehalfOf, $requiredScope)) === null => 'consent_missing',
            default => null,
        };

        $limit = $client ? ($isKey && $environment === 'sandbox' ? (int) $client->sandbox_rate_limit_per_minute : (int) $client->rate_limit_per_minute) : 0;
        $limiterKey = $client ? 'integration-client:'.$client->id.($isKey ? ':'.$environment : '') : '';
        if ($denialReason === null && ! $this->withinRateLimit($limiterKey, $limit)) {
            $denialReason = 'rate_limited';
        }

        if ($denialReason !== null) {
            app(AuditWriter::class)->record('integration.request.denied', 'integration_client', $client?->id, ['scope' => $requiredScope, 'ip' => $request->ip()], $denialReason);
            if ($client !== null) {
                $portal->meter($client, $environment, $requiredScope, $denialReason === 'rate_limited' ? 'rate_limited' : 'denied');
            }

            $response = response()->json(['error' => 'access_denied', 'message' => 'Request denied.'], $denialReason === 'rate_limited' ? 429 : 403);

            return $denialReason === 'rate_limited' ? $this->withLimitHeaders($response, $limiterKey, $limit) : $response;
        }

        $client->update(['last_used_at' => now()]);
        if ($isKey) {
            DB::table('integration_client_keys')->where('id', $credential['key_id'])->update(['last_used_at' => now()]);
        }
        app(AuditWriter::class)->record('integration.request.allowed', 'integration_client', $client->id, ['scope' => $requiredScope, 'environment' => $environment]);
        $portal->meter($client, $environment, $requiredScope, 'allowed');

        $scopes = $consent !== null ? array_values(array_filter((array) json_decode((string) $consent->scopes, true), fn ($s) => $client->hasScope($s))) : (array) $client->scopes;
        $request->attributes->set('integration_client', $client);
        $request->attributes->set('integration_environment', $environment);
        $request->attributes->set('integration_permissions', OAuthScopeCatalogue::permissionsFor($scopes));
        if ($consent !== null) {
            $request->attributes->set('delegated_tenant_id', (string) $onBehalfOf);
            $request->attributes->set('integration_consent_id', $consent->id);
        }

        return $this->withLimitHeaders($next($request), $limiterKey, $limit);
    }

    private function consent(DeveloperPortalService $portal, IntegrationClient $client, string $tenantId, string $scope): ?object
    {
        return preg_match('/^[0-9a-f-]{36}$/i', $tenantId) ? $portal->activeConsent($client, $tenantId, $scope) : null;
    }

    private function withinRateLimit(string $key, int $limit): bool
    {
        return RateLimiter::attempt($key, max(1, $limit), fn () => true, 60);
    }

    private function withLimitHeaders(Response $response, string $key, int $limit): Response
    {
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($key, max(1, $limit))));

        return $response;
    }
}

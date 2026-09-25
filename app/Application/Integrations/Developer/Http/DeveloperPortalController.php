<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Http;

use App\Application\Integrations\Developer\{DeveloperPortalService,OAuthScopeCatalogue,OidcDiscoveryService};
use App\Domain\Tenancy\TenantContext;
use App\Models\IntegrationClient;
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** REQ-API-006 / REQ-IAM-004 — developer portal API (agent B3). */
final class DeveloperPortalController
{
    public function __construct(private readonly DeveloperPortalService $portal) {}

    public function openidConfiguration(OidcDiscoveryService $oidc): JsonResponse
    {
        return response()->json($oidc->configuration())->header('Cache-Control', 'public, max-age=3600');
    }

    public function jwks(OidcDiscoveryService $oidc): JsonResponse
    {
        return response()->json($oidc->jwks())->header('Cache-Control', 'public, max-age=3600');
    }

    public function openapi(): JsonResponse
    {
        $path = base_path('docs/api/openapi.json');
        abort_unless(is_file($path), 404);

        return response()->json(json_decode((string) file_get_contents($path), true));
    }

    public function scopes(): JsonResponse
    {
        $data = [];
        foreach (OAuthScopeCatalogue::SCOPES as $scope => $def) {
            $data[] = ['scope' => $scope, 'description' => $def['description'], 'permissions' => $def['permissions']];
        }

        return response()->json(['data' => $data]);
    }

    public function clients(): JsonResponse
    {
        $keys = DB::table('integration_client_keys')->orderBy('created_at')->get(['id', 'integration_client_id', 'environment', 'oauth_client_id', 'label', 'status', 'last_used_at', 'revoked_at', 'created_at'])->groupBy('integration_client_id');

        return response()->json(['data' => IntegrationClient::query()->orderBy('name')->get()->map(fn (IntegrationClient $c) => [
            'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'environment' => $c->environment, 'client_id' => $c->client_id,
            'scopes' => $c->scopes, 'permissions' => OAuthScopeCatalogue::permissionsFor((array) $c->scopes),
            'rate_limit_per_minute' => $c->rate_limit_per_minute, 'sandbox_rate_limit_per_minute' => $c->sandbox_rate_limit_per_minute,
            'last_used_at' => $c->last_used_at, 'keys' => array_values(($keys[$c->id] ?? collect())->all()),
        ])->values()]);
    }

    public function issueKey(Request $r, IntegrationClient $client): JsonResponse
    {
        $d = $r->validate(['environment' => 'required|in:sandbox,production', 'label' => 'nullable|string|max:120']);
        $out = $this->portal->issueKey($client, $d['environment'], $d['label'] ?? null, $r->user());

        return response()->json(['data' => ['id' => $out['key']->id, 'environment' => $out['key']->environment, 'client_id' => $out['client_id'], 'client_secret' => $out['client_secret'], 'status' => $out['key']->status]], 201);
    }

    public function revokeKey(Request $r, IntegrationClient $client, string $key): JsonResponse
    {
        return response()->json(['data' => $this->portal->revokeKey($client, $key, $r->user())]);
    }

    public function rateLimits(Request $r, IntegrationClient $client): JsonResponse
    {
        $d = $r->validate(['rate_limit_per_minute' => 'required|integer|min:1|max:1000', 'sandbox_rate_limit_per_minute' => 'required|integer|min:1|max:1000']);
        $c = $this->portal->setRateLimits($client, (int) $d['rate_limit_per_minute'], (int) $d['sandbox_rate_limit_per_minute'], $r->user());

        return response()->json(['data' => ['id' => $c->id, 'rate_limit_per_minute' => $c->rate_limit_per_minute, 'sandbox_rate_limit_per_minute' => $c->sandbox_rate_limit_per_minute]]);
    }

    public function usage(Request $r, IntegrationClient $client): JsonResponse
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        return response()->json(['data' => $this->portal->usage($client, $d['from'] ?? now()->subDays(30)->toDateString(), $d['to'] ?? now()->toDateString())]);
    }

    public function partnerUsage(Request $r): JsonResponse
    {
        /** @var IntegrationClient $client */
        $client = $r->attributes->get('integration_client');

        return response()->json(['data' => [
            'environment' => $r->attributes->get('integration_environment'),
            'permissions' => $r->attributes->get('integration_permissions'),
            'usage' => $this->portal->usage($client, now()->subDays(30)->toDateString(), now()->toDateString()),
        ]]);
    }

    public function consents(TenantContext $tenant): JsonResponse
    {
        return response()->json(['data' => DB::table('integration_client_consents')->where('tenant_id', $tenant->id())->orderByDesc('granted_at')->get()
            ->map(fn ($c) => [...(array) $c, 'scopes' => json_decode((string) $c->scopes, true)])->values()]);
    }

    public function grantConsent(Request $r, TenantContext $tenant): JsonResponse
    {
        $d = $r->validate([
            'integration_client_id' => 'required|uuid|exists:integration_clients,id',
            'scopes' => 'required|array|min:1',
            'scopes.*' => ['string', Rule::in(OAuthScopeCatalogue::scopes())],
            'expires_at' => 'nullable|date|after:now',
        ]);
        $c = $this->portal->grantConsent(IntegrationClient::findOrFail($d['integration_client_id']), $tenant->id(), $d['scopes'], $d['expires_at'] ?? null, $r->user());

        return response()->json(['data' => [...(array) $c, 'scopes' => json_decode((string) $c->scopes, true)]], 201);
    }

    public function revokeConsent(Request $r, TenantContext $tenant, string $consent): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->portal->revokeConsent($consent, $tenant->id(), $d['reason'], $r->user())]);
    }
}

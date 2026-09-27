<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Application\Identity\CarrierScopeResolver;
use App\Application\Identity\Rbac\DataScope;
use App\Application\Identity\RoleCatalogue;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\TenantMembership;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner decision 2026-09-27 (docs/spec/RBAC_MATRIX_BROKER_CARRIER.md §3): insurer roles hold the health read / decide
 * permissions, so the tenant-wide health API must not show them another carrier's rows. For a caller whose membership in
 * this tenant is carrier-relationship scoped (CarrierScopeResolver links it to one carrier):
 *  - a route id ({preauth}, {claim}, {batch}, provider-dispute {id}) outside that carrier is a 404;
 *  - list responses (`data` rows with an `id`) keep only the carrier's rows;
 *  - a settlement batch must name its claim_ids, all of the carrier (a batch never mixes carriers);
 *  - the provider statement (every carrier's claims of a provider) is refused.
 * Everyone else (platform staff, providers, insurer-tenant staff without a link) is untouched.
 */
final class EnsureHealthCarrierScope
{
    private const PARAMS = ['preauth' => 'health_preauthorizations', 'claim' => 'health_provider_claims', 'batch' => 'health_provider_settlement_batches'];

    public function __construct(private readonly CarrierScopeResolver $resolver, private readonly TenantContext $tenant)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $carrier = $this->carrierFor($request->user());
        if ($carrier === null) {
            return $next($request);
        }

        $route = $request->route();
        $uri = (string) $route?->uri();
        $listTable = match (true) {
            str_ends_with($uri, 'health/preauthorizations') => 'health_preauthorizations',
            str_ends_with($uri, 'health/provider-claims') => 'health_provider_claims',
            str_ends_with($uri, 'health/provider-settlements') => 'settlement_create',
            str_contains($uri, 'provider-disputes/{id}') => 'provider_disputes',
            default => null,
        };
        foreach (self::PARAMS as $param => $table) {
            $id = $route?->parameter($param);
            if (is_string($id) && PortalScope::visibleOfCarrier($table, [$id], $carrier) === []) {
                return $this->problem(404, 'NOT_FOUND', 'Not found.');
            }
        }
        if ($listTable === 'provider_disputes' && is_string($id = $route?->parameter('id')) && PortalScope::visibleOfCarrier('provider_disputes', [$id], $carrier) === []) {
            return $this->problem(404, 'NOT_FOUND', 'Not found.');
        }
        if ($route?->parameter('provider') !== null) {
            return $this->problem(403, 'CARRIER_SCOPE', 'The provider statement spans every insurer; use the provider claim list.');
        }
        if ($request->isMethod('POST') && $listTable === 'settlement_create') {
            $ids = array_values(array_filter((array) $request->input('claim_ids', []), 'is_string'));
            if ($ids === [] || count(PortalScope::visibleOfCarrier('health_provider_claims', $ids, $carrier)) !== count(array_unique($ids))) {
                return $this->problem(422, 'CARRIER_SCOPE', 'An insurer settlement batch must list claim_ids, all of your own carrier.');
            }
        }

        $response = $next($request);

        if ($request->isMethod('GET') && in_array($listTable, ['health_preauthorizations', 'health_provider_claims'], true) && $response instanceof JsonResponse) {
            $payload = $response->getData(true);
            if (is_array($payload['data'] ?? null) && array_is_list($payload['data'])) {
                $keep = array_flip(PortalScope::visibleOfCarrier($listTable, array_map(fn ($r) => (string) ($r['id'] ?? ''), $payload['data']), $carrier));
                $payload['data'] = array_values(array_filter($payload['data'], fn ($r) => isset($keep[(string) ($r['id'] ?? '')])));
                $response->setData($payload);
            }
        }

        return $response;
    }

    private function carrierFor(mixed $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }
        $tenantId = rescue(fn () => $this->tenant->id(), null, false);
        if ($tenantId === null) {
            return null;
        }
        $carrierScoped = TenantMembership::where(['tenant_id' => $tenantId, 'user_id' => $user->id, 'status' => 'ACTIVE'])->pluck('role_code')
            ->contains(fn ($r) => RoleCatalogue::defaultScope((string) $r) === DataScope::CARRIER_RELATIONSHIP);

        return $carrierScoped ? $this->resolver->carrierIdFor($user, $tenantId) : null;
    }

    private function problem(int $status, string $code, string $detail): JsonResponse
    {
        return response()->json(['type' => 'about:blank', 'title' => $code, 'status' => $status, 'code' => $code, 'detail' => $detail], $status, ['Content-Type' => 'application/problem+json']);
    }
}

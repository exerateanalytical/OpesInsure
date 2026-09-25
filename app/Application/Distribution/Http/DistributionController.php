<?php

declare(strict_types=1);

namespace App\Application\Distribution\Http;

use App\Application\CarrierOperations\Agreements\CarrierBrokerAgreementService;
use App\Application\Distribution\Execution\ExecutionContext;
use App\Application\Distribution\Execution\ExecutionPlanner;
use App\Application\Distribution\SellabilityService;
use App\Application\Distribution\SellableCatalogue;
use App\Application\Identity\PartyResolver;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * REQ-DST-001 / REQ-DST-002 / REQ-AOM-002 API.
 * Viewer: the caller's own partner (broker/agent) by default; an explicit partner_id must belong to the current
 * tenant (the platform tenant sees all). A caller with no partner sees the tenant's direct (B2C) catalogue.
 */
final class DistributionController
{
    public function __construct(private readonly TenantContext $tenant, private readonly PartyResolver $parties) {}

    public function catalogue(Request $r, SellableCatalogue $catalogue): JsonResponse
    {
        $d = $r->validate($this->viewerRules() + ['line_code' => 'nullable|string|max:32', 'include_blocked' => 'nullable|boolean']);
        $viewer = $this->viewer($r, $d) + ['line_code' => $d['line_code'] ?? null, 'include_blocked' => (bool) ($d['include_blocked'] ?? false)];
        $items = $catalogue->for($viewer);

        return response()->json(['data' => $items, 'meta' => ['viewer' => array_intersect_key($viewer, array_flip(['partner_id', 'tenant_id', 'channel', 'territory'])), 'count' => count($items)]]);
    }

    public function sellability(Request $r, SellabilityService $sellability): JsonResponse
    {
        $d = $r->validate($this->viewerRules() + ['product_id' => 'required|uuid', 'action' => 'nullable|in:'.implode(',', array_keys(CarrierBrokerAgreementService::ACTIONS))]);

        return response()->json(['data' => $sellability->check($d['product_id'], $d['action'] ?? 'quote', $this->viewer($r, $d))]);
    }

    public function executionPlan(Request $r, ExecutionPlanner $planner): JsonResponse
    {
        $d = $r->validate(['carrier_id' => 'required|uuid|exists:carriers,id', 'product_id' => 'nullable|uuid|exists:insurance_products,id',
            'port' => 'nullable|in:'.implode(',', array_keys(ExecutionPlanner::PORTS)), 'subject_type' => 'nullable|required_with:subject_id|string|max:48', 'subject_id' => 'nullable|uuid']);
        if (empty($d['port'])) {
            return response()->json(['data' => $planner->plan($d['carrier_id'], $d['product_id'] ?? null)]);
        }
        $ctx = new ExecutionContext($d['subject_type'] ?? 'preview', $d['subject_id'] ?? null, $d['carrier_id'], $d['product_id'] ?? null);

        return response()->json(['data' => [$d['port'] => $planner->registry($d['port'])->execute($ctx)->toArray()]]);
    }

    private function viewerRules(): array
    {
        return ['partner_id' => 'nullable|uuid', 'channel' => 'nullable|string|max:32', 'territory' => 'nullable|string|max:8', 'on' => 'nullable|date'];
    }

    /** @return array{partner_id:?string, tenant_id:?string, channel:?string, territory:?string, on:?string} */
    private function viewer(Request $r, array $d): array
    {
        $partnerId = $d['partner_id'] ?? null;
        $own = $this->parties->partnerForUser($r->user());
        if ($partnerId !== null && $partnerId !== $own?->id && ! $this->isPlatform()) {
            abort_unless(DB::table('partners')->where(['id' => $partnerId, 'tenant_id' => $this->tenant->id()])->exists(), 404);
        }
        $partnerId ??= $own?->id;

        return ['partner_id' => $partnerId, 'tenant_id' => $partnerId ? null : $this->tenant->id(), 'channel' => $d['channel'] ?? null,
            'territory' => $d['territory'] ?? null, 'on' => isset($d['on']) ? substr((string) $d['on'], 0, 10) : null];
    }

    private function isPlatform(): bool
    {
        return DB::table('tenants')->where('id', $this->tenant->id())->value('type') === 'PLATFORM';
    }
}

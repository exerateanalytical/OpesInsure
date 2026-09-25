<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\ApiFamilies;

use App\Application\Identity\CarrierScopeResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\UnderwritingReferralTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-API-004 — underwriting referrals queue (STA authority-profiles/referrals family). Read-only; resolution stays on
 * POST underwriting/referrals/{referral}/resolve (UnderwritingService::resolveReferral). Tenant- and carrier-scoped
 * exactly like the underwriting workspace queue.
 */
final class UnderwritingReferralQueueController
{
    public function __construct(private readonly CarrierScopeResolver $scope) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => 'sometimes|string|in:OPEN,RESOLVED,CANCELLED', 'mine' => 'sometimes|boolean', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $rows = $this->scoped($r)->with('underwritingCase:id,proposal_id,carrier_id,status,priority')
            ->where('status', $d['status'] ?? 'OPEN')
            ->when(! empty($d['mine']), fn ($q) => $q->where('assigned_to', $r->user()->id))
            ->orderByRaw("CASE severity WHEN 'HIGH' THEN 0 WHEN 'MEDIUM' THEN 1 ELSE 2 END")->orderBy('due_at')
            ->paginate((int) ($d['per_page'] ?? 50));

        return response()->json(['data' => collect($rows->items())->map(fn (UnderwritingReferralTask $t) => $this->row($t))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]]);
    }

    public function show(Request $r, string $referral): JsonResponse
    {
        return response()->json(['data' => $this->row($this->scoped($r)->with('underwritingCase')->findOrFail($referral))]);
    }

    private function row(UnderwritingReferralTask $t): array
    {
        return $t->only(['id', 'underwriting_case_id', 'reason_code', 'status', 'severity', 'assigned_to', 'resolution_notes', 'resolved_by']) + [
            'due_at' => $t->due_at?->toIso8601String(), 'resolved_at' => $t->resolved_at?->toIso8601String(),
            'case' => $t->underwritingCase?->only(['id', 'proposal_id', 'carrier_id', 'status', 'priority']),
        ];
    }

    private function scoped(Request $r)
    {
        $tenant = app(TenantContext::class)->id();
        $carrier = $this->scope->carrierIdFor($r->user(), $tenant);

        return UnderwritingReferralTask::query()->whereHas('underwritingCase', fn ($q) => $q->where('tenant_id', $tenant)
            ->when($carrier !== null, fn ($x) => $x->where('carrier_id', $carrier)));
    }
}

<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Identity\CarrierScopeResolver;
use App\Application\Policies\PolicyIssuanceService;
use App\Domain\Tenancy\TenantContext;
use App\Models\PolicyIssuanceRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile audit E2 — issuance maker-checker for the insurer app. Rows are scoped to the caller's tenant and carrier.
 * Approve / reject stay on POST mobile/partner/carrier/issuance/{id}/approve|reject (approve answers 409
 * AUTHORITY_EXCEEDED / SECOND_APPROVAL_REQUIRED when POLICY_ISSUE authority limits apply).
 */
final class MobileCarrierIssuanceController
{
    public const DECIDE = 'carrier.referrals.decide';

    public function __construct(private PolicyIssuanceService $issuance, private CarrierScopeResolver $scope) {}

    /** GET mobile/carrier/issuance/{id} */
    public function show(string $id, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->scoped($id, $request), $request->user())]);
    }

    /** POST mobile/carrier/issuance/{id}/request-correction {reason} */
    public function requestCorrection(string $id, Request $request): JsonResponse
    {
        $d = $request->validate(['reason' => 'required|string|min:5|max:2000']);
        $r = $this->issuance->requestCorrection($this->scoped($id, $request), $d['reason'], $request->user());

        return response()->json(['data' => $this->present($r, $request->user())]);
    }

    /** POST mobile/carrier/issuance/{id}/verify {notes?} */
    public function verify(string $id, Request $request): JsonResponse
    {
        $d = $request->validate(['notes' => 'sometimes|nullable|string|max:2000']);
        $r = $this->issuance->verify($this->scoped($id, $request), $request->user(), $d['notes'] ?? null);

        return response()->json(['data' => $this->present($r, $request->user())]);
    }

    /** POST mobile/carrier/issuance/{id}/second-approve {carrier_reference?} */
    public function secondApprove(string $id, Request $request): JsonResponse
    {
        $d = $request->validate(['carrier_reference' => 'sometimes|string|max:64']);
        $r = $this->scoped($id, $request);
        $policy = $this->issuance->secondApprove($r, $d, $request->user());

        return response()->json(['data' => $this->present($r->refresh(), $request->user()) + ['policy_id' => $policy->id, 'policy_number' => $policy->policy_number]]);
    }

    /** Detail / queue row shape: stage, approvals[], capabilities[], verified payment and wording (terms) version. */
    public function present(PolicyIssuanceRequest $r, User $viewer): array
    {
        $r->loadMissing(['proposal.party', 'proposal.offer.product', 'payment', 'carrier']);
        $p = $r->proposal;

        return [
            'id' => $r->id, 'reference' => $p?->proposal_number ?? substr($r->id, 0, 8), 'status' => $r->status, 'stage' => $this->issuance->stage($r),
            'carrier_id' => $r->carrier_id, 'carrier_name' => $r->carrier?->trade_name ?? $r->carrier?->legal_name,
            'customer' => $p?->party?->display_name, 'product' => $p?->offer?->product?->name,
            'premium' => ['amount_minor' => (int) ($p?->terms_snapshot['total_minor'] ?? 0), 'currency' => $p?->terms_snapshot['currency'] ?? null],
            'coverage_starts_at' => $r->coverage_starts_at?->toIso8601String(), 'coverage_ends_at' => $r->coverage_ends_at?->toIso8601String(),
            'authority_mode' => $r->authority_snapshot['mode'] ?? null, 'terms_hash' => $r->terms_hash, 'wording_version' => $p?->offer?->product?->version ?? null,
            'payment' => $r->payment ? ['id' => $r->payment->id, 'status' => $r->payment->status, 'verified' => $r->payment->status === 'SUCCEEDED' && $r->payment->reconciled_at !== null,
                'reconciled_at' => $r->payment->reconciled_at ? \Carbon\Carbon::parse($r->payment->reconciled_at)->toIso8601String() : null, 'provider_reference' => $r->payment->provider_reference] : null,
            'correction_reason' => $r->correction_reason, 'rejection_reason' => $r->rejection_reason, 'carrier_reference' => $r->carrier_reference,
            'approvals' => $this->issuance->approvals($r),
            'capabilities' => $this->issuance->capabilities($r, $viewer, $viewer->hasPermission(self::DECIDE)),
            'submitted_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function scoped(string $id, Request $request): PolicyIssuanceRequest
    {
        $tenant = app(TenantContext::class)->id();
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);

        return PolicyIssuanceRequest::where('tenant_id', $tenant)
            ->when($this->scope->carrierIdFor($request->user(), $tenant), fn ($q, $cid) => $q->where('carrier_id', $cid))
            ->whereKey($id)->firstOrFail();
    }
}

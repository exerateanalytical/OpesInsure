<?php

declare(strict_types=1);

namespace App\Application\Fraud\Http;

use App\Application\Fraud\ClaimFraudIndicatorService;
use App\Application\Fraud\SegregationOfDutiesPolicy;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\RiskAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-FRD-001 claim fraud review (WF-089) + REQ-FRD-002 SoD violation report. */
final class FraudControlController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function assess(string $claim, Request $r, ClaimFraudIndicatorService $svc): JsonResponse
    {
        $c = Claim::where('tenant_id', $this->tenant->id())->findOrFail($claim);
        $res = $svc->assess($c, $r->user());

        return response()->json(['data' => [
            'claim_id' => $c->id, 'fraud_flag' => $res['review'] ? 'REVIEW_REQUIRED' : null, 'indicators' => $res['indicators'], 'facts' => $res['facts'],
            'risk_alert_id' => $res['review']?->id, 'case_id' => $res['case_id'],
        ]]);
    }

    public function outcome(string $alert, Request $r, ClaimFraudIndicatorService $svc): JsonResponse
    {
        $d = $r->validate(['outcome' => 'required|in:'.implode(',', array_keys(ClaimFraudIndicatorService::OUTCOMES)), 'rationale' => 'required|string|min:20|max:4000']);
        $a = RiskAlert::where('tenant_id', $this->tenant->id())->where('alert_type', ClaimFraudIndicatorService::ALERT_TYPE)->findOrFail($alert);
        $a = $svc->recordOutcome($a, $d['outcome'], $d['rationale'], $r->user());

        return response()->json(['data' => ['id' => $a->id, 'status' => $a->status, 'decision' => $a->decision, 'claim_id' => $a->subject_id,
            'fraud_flag' => ClaimFraudIndicatorService::OUTCOMES[$a->decision]]]);
    }

    public function sodViolations(SegregationOfDutiesPolicy $sod): JsonResponse
    {
        return response()->json(['data' => $sod->violations($this->tenant->id())]);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Risk\Http;

use App\Application\Compliance\Aml\Risk\AmlRiskRatingService;
use App\Application\Compliance\Aml\Risk\TransactionMonitoringService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-AML-002 — customer AML risk rating + transaction monitoring (agent E9). */
final class AmlRiskController
{
    public function __construct(private readonly AmlRiskRatingService $risk, private readonly TransactionMonitoringService $monitoring) {}

    public function rate(Request $r, string $party): JsonResponse
    {
        $d = $r->validate(['country_code' => 'nullable|string|size:2', 'product_codes' => 'nullable|array|max:50', 'product_codes.*' => 'string|max:64',
            'channel' => 'nullable|string|max:40', 'customer_type' => 'nullable|in:INDIVIDUAL,CORPORATE', 'reason' => 'required|string|max:500']);
        $inputs = array_filter(['country_code' => isset($d['country_code']) ? strtoupper($d['country_code']) : null, 'product_codes' => $d['product_codes'] ?? null,
            'channel' => isset($d['channel']) ? strtoupper($d['channel']) : null, 'customer_type' => $d['customer_type'] ?? null], fn ($v) => $v !== null);

        return response()->json(['data' => $this->risk->rate($this->tenant(), $party, $inputs, $d['reason'], $r->user())], 201);
    }

    public function show(string $party): JsonResponse
    {
        $row = $this->risk->latest($this->tenant(), $party);
        abort_unless($row !== null, 404);

        return response()->json(['data' => $row]);
    }

    public function monitor(Request $r): JsonResponse
    {
        $d = $r->validate(['subject_type' => 'required|in:PAYMENT,POLICY,CLAIM,COMMISSION,REFUND', 'subject_id' => 'required|uuid',
            'party_id' => 'nullable|uuid', 'facts' => 'required|array']);

        return response()->json(['data' => $this->monitoring->evaluate($this->tenant(), $d)]);
    }

    public function rules(): JsonResponse
    {
        $rules = $this->monitoring->activeRules()->map(fn ($x) => $x->only(['id', 'code', 'version', 'risk_points', 'conditions', 'effective_from', 'effective_until']))->values();

        return response()->json(['data' => ['status' => $rules->isEmpty() ? 'INACTIVE_NOT_CONFIGURED' : 'ACTIVE', 'owner_question' => 'OQ-5.2', 'rules' => $rules]]);
    }

    private function tenant(): string
    {
        return (string) app(TenantContext::class)->id();
    }
}

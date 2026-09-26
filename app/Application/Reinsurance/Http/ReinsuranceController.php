<?php

declare(strict_types=1);

namespace App\Application\Reinsurance\Http;

use App\Application\Reinsurance\TreatyBordereauService;
use App\Application\Reinsurance\CessionService;
use App\Application\Reinsurance\TreatyService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Batch 13C — REQ-REI-001 treaties, REQ-REI-002 cessions + bordereaux. */
final class ReinsuranceController
{
    public function __construct(private readonly TenantContext $tenant, private readonly TreatyService $treaties, private readonly CessionService $cessions) {}

    public function reinsurers(): JsonResponse
    {
        return response()->json(['data' => DB::table('reinsurers')->where('tenant_id', $this->tenant->id())->orderBy('code')->get()]);
    }

    public function createReinsurer(Request $r): JsonResponse
    {
        $data = $r->validate(['code' => 'required|string|max:64', 'name' => 'required|string|max:255', 'role' => 'sometimes|string', 'party_id' => 'nullable|uuid|exists:parties,id',
            'country_code' => 'nullable|string|size:2', 'rating' => 'nullable|string|max:16', 'rating_agency' => 'nullable|string|max:64',
            // Gap Closure Pack v1 (07) directory fields; approved-security status is never settable here.
            'regulator' => 'nullable|string|max:128', 'license_reference' => 'nullable|string|max:128', 'ratings' => 'sometimes|array|max:10',
            'ratings.*.agency' => 'required_with:ratings|string|max:64', 'ratings.*.rating' => 'required_with:ratings|string|max:16', 'ratings.*.as_of' => 'nullable|date',
            'contact' => 'sometimes|array', 'website' => 'nullable|url|max:255', 'effective_from' => 'nullable|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'source_url' => 'nullable|url|max:1024']);

        return response()->json(['data' => $this->treaties->createReinsurer($this->tenant->id(), $data)], 201);
    }

    public function reinsurerStatus(Request $r, string $reinsurer): JsonResponse
    {
        $data = $r->validate(['status' => 'required|string', 'reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->treaties->updateReinsurerStatus($this->tenant->id(), $reinsurer, $data['status'], $data['reason'])]);
    }

    public function treatiesIndex(): JsonResponse
    {
        return response()->json(['data' => DB::table('reinsurance_treaties')->where('tenant_id', $this->tenant->id())->orderBy('code')->get()]);
    }

    public function createTreaty(Request $r): JsonResponse
    {
        $data = $r->validate(['code' => 'required|string|max:64', 'name' => 'required|string|max:255', 'treaty_type' => 'required|string', 'reinsurance_type' => 'sometimes|string',
            'currency' => 'required|string|size:3', 'underwriting_year' => 'nullable|integer|min:1990|max:2100',
            'treaty_number' => 'nullable|string|max:64', 'cedant_party_id' => 'nullable|uuid|exists:parties,id', 'territories' => 'sometimes|array', 'territories.*' => 'string|max:64',
            'bordereau_frequency' => 'nullable|string', 'wording_document_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->treaties->createTreaty($this->tenant->id(), $data)], 201);
    }

    public function showTreaty(string $treaty): JsonResponse
    {
        $t = $this->treaties->treaty($this->tenant->id(), $treaty);
        $versions = DB::table('reinsurance_treaty_versions')->where('treaty_id', $t->id)->orderBy('version')->pluck('id')
            ->map(fn ($id) => $this->treaties->version($this->tenant->id(), $id))->all();

        return response()->json(['data' => (array) $t + ['versions' => $versions]]);
    }

    public function addVersion(Request $r, string $treaty): JsonResponse
    {
        $data = $r->validate(['effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from', 'line_codes' => 'sometimes|array', 'line_codes.*' => 'string',
            'retention_minor' => 'nullable|integer|min:0', 'cession_percent' => 'nullable|numeric', 'lines' => 'nullable|integer|min:1', 'max_capacity_minor' => 'nullable|integer|min:0',
            'commission_percent' => 'nullable|numeric', 'brokerage_percent' => 'nullable|numeric', 'tax_percent' => 'nullable|numeric', 'rate_percent' => 'nullable|numeric',
            'attachment_ratio' => 'nullable|numeric|min:0', 'limit_ratio' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:1000',
            'premium_terms' => 'sometimes|array', 'profit_commission' => 'sometimes|array', 'claims_cooperation_threshold_minor' => 'nullable|integer|min:0',
            'cash_call_threshold_minor' => 'nullable|integer|min:0',
            'layers' => 'sometimes|array', 'layers.*.layer' => 'sometimes|integer', 'layers.*.attachment_minor' => 'required_with:layers|integer|min:0',
            'layers.*.limit_minor' => 'required_with:layers|integer|min:1', 'layers.*.rate_percent' => 'required_with:layers|numeric|min:0|max:100', 'layers.*.reinstatements' => 'sometimes|integer|min:0',
            'layers.*.reinstatement_premium_percent' => 'sometimes|numeric|min:0|max:1000',
            'participants' => 'required|array|min:1', 'participants.*.reinsurer_id' => 'required|uuid', 'participants.*.broker_id' => 'nullable|uuid',
            'participants.*.share_percent' => 'required|numeric|gt:0|max:100', 'participants.*.is_lead' => 'sometimes|boolean']);

        return response()->json(['data' => $this->treaties->addVersion($this->tenant->id(), $treaty, $data)], 201);
    }

    public function activateVersion(Request $r, string $version): JsonResponse
    {
        $data = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->treaties->activateVersion($this->tenant->id(), $version, $data['reason'])]);
    }

    public function policyCessions(string $policy): JsonResponse
    {
        return response()->json(['data' => $this->cessions->forPolicy($this->tenant->id(), $policy)]);
    }

    public function previewCession(Request $r, string $policy): JsonResponse
    {
        $data = $r->validate(['sum_insured_minor' => 'nullable|integer|min:0']);

        return response()->json(['data' => $this->cessions->preview($this->tenant->id(), $policy, $data['sum_insured_minor'] ?? null)]);
    }

    public function cede(Request $r, string $policy): JsonResponse
    {
        $data = $r->validate(['sum_insured_minor' => 'nullable|integer|min:0']);
        $result = $this->cessions->cedePolicy($this->tenant->id(), $policy, $data['sum_insured_minor'] ?? null);

        return response()->json(['data' => $result], $result['replayed'] || $result['run'] === null ? 200 : 201);
    }

    public function bordereau(Request $r, string $treaty, TreatyBordereauService $service): JsonResponse
    {
        $data = $r->validate(['type' => 'sometimes|in:PREMIUM,RISK', 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);

        return response()->json(['data' => $service->build($this->tenant->id(), $treaty, $data['type'] ?? 'PREMIUM', $data['from'], $data['to'])]);
    }
}

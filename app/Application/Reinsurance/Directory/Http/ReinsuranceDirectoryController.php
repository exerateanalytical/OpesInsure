<?php

declare(strict_types=1);

namespace App\Application\Reinsurance\Directory\Http;

use App\Application\FinancialDistribution\ReinsuranceReference;
use App\Application\Reinsurance\TreatyService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Gap Closure Pack v1 (07): reinsurer / broker directory with the approved-security gate, and the reference vocabulary. */
final class ReinsuranceDirectoryController
{
    public function __construct(private readonly TenantContext $tenant, private readonly TreatyService $treaties) {}

    /** Directory view: ?role=REINSURER|REINSURANCE_BROKER|RETROCESSIONAIRE, ?security=APPROVED|PENDING. */
    public function directory(Request $r): JsonResponse
    {
        $d = $r->validate(['role' => 'nullable|in:'.implode(',', TreatyService::REINSURER_ROLES), 'security' => 'nullable|in:APPROVED,PENDING']);
        $rows = DB::table('reinsurers')->where('tenant_id', $this->tenant->id())
            ->when($d['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when(($d['security'] ?? null) === 'APPROVED', fn ($q) => $q->whereIn('approved_security_status', ReinsuranceReference::APPROVED_SECURITY))
            ->when(($d['security'] ?? null) === 'PENDING', fn ($q) => $q->whereNotIn('approved_security_status', ReinsuranceReference::APPROVED_SECURITY))
            ->orderBy('code')->get()
            ->map(fn ($x) => ['id' => $x->id, 'code' => $x->code, 'legal_name' => $x->name, 'role' => $x->role, 'jurisdiction' => $x->country_code,
                'regulator' => $x->regulator, 'license_reference' => $x->license_reference, 'ratings' => json_decode((string) $x->ratings, true) ?: [],
                'approved_security_status' => $x->approved_security_status, 'approved_security' => in_array($x->approved_security_status, ReinsuranceReference::APPROVED_SECURITY, true),
                'contact' => json_decode((string) $x->contact, true) ?: (object) [], 'website' => $x->website, 'effective_from' => $x->effective_from,
                'effective_until' => $x->effective_until, 'source_url' => $x->source_url, 'verification_status' => $x->verification_status, 'status' => $x->status,
                'data_source' => $x->data_source])->all();

        return response()->json(['data' => $rows]);
    }

    public function security(Request $r, string $reinsurer): JsonResponse
    {
        $d = $r->validate(['approved_security_status' => 'required|in:'.implode(',', ReinsuranceReference::SECURITY_STATUSES),
            'reason' => 'required|string|min:5|max:500', 'source_url' => 'nullable|url|max:1024']);

        return response()->json(['data' => $this->treaties->approveSecurity($this->tenant->id(), $reinsurer, $d['approved_security_status'], $d['reason'], $d['source_url'] ?? null)]);
    }

    public function reference(): JsonResponse
    {
        return response()->json(['data' => [
            'treaty_forms' => ReinsuranceReference::TREATY_FORMS, 'engine_treaty_types' => ReinsuranceReference::TREATY_TYPES,
            'security_statuses' => ReinsuranceReference::SECURITY_STATUSES, 'approved_security' => ReinsuranceReference::APPROVED_SECURITY,
            'bordereau_frequencies' => ReinsuranceReference::BORDEREAU_FREQUENCIES, 'coinsurance_statuses' => ReinsuranceReference::COINSURANCE_STATUSES,
            'coinsurance_roles' => ReinsuranceReference::COINSURANCE_ROLES, 'reinsurer_roles' => TreatyService::REINSURER_ROLES,
            'source' => 'GAP_CLOSURE_PACK_V1/07_reinsurance_coinsurance.json',
        ]]);
    }
}

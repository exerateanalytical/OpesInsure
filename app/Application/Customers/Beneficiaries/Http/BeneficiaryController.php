<?php

declare(strict_types=1);

namespace App\Application\Customers\Beneficiaries\Http;

use App\Application\Customers\Beneficiaries\BeneficiaryService;
use App\Application\Identity\OwnershipScope;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-CRM-004 — policy beneficiary designations (current set, versioned history, replace). */
final class BeneficiaryController
{
    public function __construct(private readonly BeneficiaryService $beneficiaries) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    /** Tenant-scoped policy; owner-scoped callers (customers) only reach their own policy. */
    private function scopedPolicy(string $policy)
    {
        $p = $this->beneficiaries->policy($policy, $this->tenant());
        $user = request()->user();
        if ($user !== null) {
            app(OwnershipScope::class)->assertOwnParty($user, $p->party_id);
        }

        return $p;
    }

    public function index(string $policy): JsonResponse
    {
        $p = $this->scopedPolicy($policy);

        return response()->json(['data' => $this->beneficiaries->current($p)->map(fn ($b) => self::row($b))->values()]);
    }

    public function history(string $policy): JsonResponse
    {
        $p = $this->scopedPolicy($policy);

        return response()->json(['data' => $this->beneficiaries->history($p)->map(fn ($s) => ['designations' => array_map(fn ($b) => self::row($b), $s['designations'])] + $s)->values()]);
    }

    public function replace(Request $r, string $policy): JsonResponse
    {
        $p = $this->scopedPolicy($policy);
        $d = $r->validate([
            'beneficiaries' => 'present|array|max:20',
            'beneficiaries.*.designation' => ['required', Rule::in(BeneficiaryService::DESIGNATIONS)],
            'beneficiaries.*.party_id' => 'nullable|uuid', 'beneficiaries.*.full_name' => 'nullable|string|max:160',
            'beneficiaries.*.relationship' => 'nullable|string|max:32', 'beneficiaries.*.date_of_birth' => 'nullable|date|before_or_equal:today',
            'beneficiaries.*.allocation_pct' => 'required|numeric', 'beneficiaries.*.revocable' => 'sometimes|boolean',
            'reason' => 'required|string|max:255', 'irrevocable_consent_reference' => 'nullable|string|max:120',
        ]);
        $rows = $this->beneficiaries->replace($p, $d['beneficiaries'], $d['reason'], $d['irrevocable_consent_reference'] ?? null, $r->user());

        return response()->json(['data' => $rows->map(fn ($b) => self::row($b))->values()]);
    }

    private static function row(object $b): array
    {
        return ['id' => $b->id, 'set_version' => (int) $b->set_version, 'designation' => $b->designation, 'party_id' => $b->party_id, 'full_name' => $b->full_name,
            'relationship' => $b->relationship, 'date_of_birth' => $b->date_of_birth, 'allocation_pct' => (float) $b->allocation_pct, 'revocable' => (bool) $b->revocable,
            'status' => $b->status, 'effective_from' => $b->effective_from, 'effective_to' => $b->effective_to];
    }
}

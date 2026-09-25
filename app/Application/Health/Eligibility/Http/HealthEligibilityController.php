<?php

declare(strict_types=1);

namespace App\Application\Health\Eligibility\Http;

use App\Application\Health\Eligibility\EligibilityService;
use App\Application\Health\Eligibility\HealthCardService;
use App\Application\Health\Eligibility\HealthMemberService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-HLT-001 — health members, cards, benefit schedule, eligibility check + provider-side card scan. */
final class HealthEligibilityController
{
    public function __construct(
        private readonly HealthMemberService $members,
        private readonly EligibilityService $eligibility,
        private readonly HealthCardService $cards,
        private readonly TenantContext $tenant,
    ) {}

    public function members(string $policy): JsonResponse
    {
        return response()->json(['data' => $this->members->members($this->tenant->id(), $policy)]);
    }

    public function enrol(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate([
            'relationship' => 'required|string|in:'.implode(',', HealthMemberService::RELATIONSHIPS), 'display_name' => 'nullable|string|max:191',
            'party_id' => 'nullable|uuid', 'principal_member_id' => 'nullable|uuid', 'schedule_item_id' => 'nullable|uuid',
            'date_of_birth' => 'nullable|date', 'effective_from' => 'nullable|date', 'effective_to' => 'nullable|date',
        ]);

        return response()->json(['data' => $this->members->enrol($this->tenant->id(), $policy, $d, $r->user()?->id)], 201);
    }

    public function end(Request $r, string $member): JsonResponse
    {
        $d = $r->validate(['effective_to' => 'required|date', 'reason' => 'required|string|max:255']);

        return response()->json(['data' => $this->members->end($this->tenant->id(), $member, $d['effective_to'], $d['reason'])]);
    }

    public function issueCard(Request $r, string $member): JsonResponse
    {
        return response()->json(['data' => $this->cards->issue($this->tenant->id(), $member, $r->user()?->id)], 201);
    }

    public function linkNetwork(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate(['provider_network_id' => 'required|uuid']);

        return response()->json(['data' => $this->members->linkNetwork($this->tenant->id(), $policy, $d['provider_network_id'], $r->user()?->id)], 201);
    }

    public function addBenefitRule(Request $r): JsonResponse
    {
        $d = $r->validate([
            'policy_id' => 'nullable|uuid', 'medical_service_code' => 'nullable|string|max:64', 'service_category_code' => 'nullable|string|max:64',
            'coverage_code' => 'required|string|max:64', 'benefit_code' => 'required|string|max:64', 'waiting_period_days' => 'nullable|integer|min:0|max:3650',
        ]);

        return response()->json(['data' => $this->members->addBenefitRule($this->tenant->id(), $d, $r->user()?->id)], 201);
    }

    public function check(Request $r): JsonResponse
    {
        $d = $r->validate(['member_ref' => 'required|string|max:64', 'provider_id' => 'nullable|uuid', 'service_code' => 'required|string|max:64', 'service_date' => 'nullable|date']);

        return response()->json(['data' => $this->eligibility->check($this->tenant->id(), $d['member_ref'], $d['provider_id'] ?? null, $d['service_code'], $d['service_date'] ?? null, 'API', $r->user()?->id)]);
    }

    public function scan(Request $r): JsonResponse
    {
        $d = $r->validate(['qr' => 'required|string|max:1024', 'provider_id' => 'required|uuid', 'service_code' => 'required|string|max:64', 'service_date' => 'nullable|date']);

        return response()->json(['data' => $this->cards->scan($this->tenant->id(), $d['qr'], $d['provider_id'], $d['service_code'], $d['service_date'] ?? null, $r->user()?->id)]);
    }

    public function history(string $member): JsonResponse
    {
        return response()->json(['data' => $this->eligibility->history($this->tenant->id(), $member)]);
    }
}

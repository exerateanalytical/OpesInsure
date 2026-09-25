<?php

declare(strict_types=1);

namespace App\Application\Health\Eligibility;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Interfaces\Http\Errors\ApiProblemException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-HLT-001 — health members (policyholder = PRINCIPAL, DEPENDANTs, and GROUP_MEMBERs sourced from the
 * Batch 8-6 group master schedule), the policy ↔ provider-network link and the benefit schedule lookup.
 */
final class HealthMemberService
{
    public const RELATIONSHIPS = ['PRINCIPAL', 'SPOUSE', 'CHILD', 'DEPENDANT', 'GROUP_MEMBER'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public function enrol(string $tenantId, string $policyId, array $d, ?string $actorId): object
    {
        $policy = $this->policy($tenantId, $policyId);
        $rel = strtoupper((string) $d['relationship']);
        if (! in_array($rel, self::RELATIONSHIPS, true)) {
            throw new ApiProblemException('RELATIONSHIP_INVALID', 422, 'Unknown member relationship.');
        }
        $principal = null;
        $item = null;
        $partyId = $d['party_id'] ?? null;
        $name = $d['display_name'] ?? null;
        $from = $d['effective_from'] ?? null;
        $to = $d['effective_to'] ?? null;

        if ($rel === 'PRINCIPAL') {
            $partyId ??= $policy->party_id;
            if (DB::table('health_members')->where('policy_id', $policyId)->where('relationship', 'PRINCIPAL')->where('status', '<>', 'ENDED')->exists()) {
                throw new ApiProblemException('PRINCIPAL_EXISTS', 409, 'The policy already has an active principal member.');
            }
            $name ??= DB::table('parties')->where('id', $partyId)->value('display_name');
        } elseif ($rel === 'GROUP_MEMBER') {
            $item = DB::table('policy_schedule_items')->where('tenant_id', $tenantId)->where('policy_id', $policyId)
                ->where('id', $d['schedule_item_id'] ?? null)->where('item_type', 'GROUP_MEMBER')->first()
                ?? throw new ApiProblemException('SCHEDULE_ITEM_INVALID', 422, 'A group member needs a GROUP_MEMBER schedule item of this policy.');
            if (DB::table('health_members')->where('schedule_item_id', $item->id)->where('status', '<>', 'ENDED')->exists()) {
                throw new ApiProblemException('MEMBER_EXISTS', 409, 'This schedule item is already enrolled.');
            }
            $partyId ??= $item->party_id;
            $name ??= $item->display_name;
            $from ??= CarbonImmutable::parse($item->effective_from)->toDateString();
            $to ??= $item->effective_until ? CarbonImmutable::parse($item->effective_until)->toDateString() : null;
        } else {
            $principal = DB::table('health_members')->where('tenant_id', $tenantId)->where('policy_id', $policyId)->where('id', $d['principal_member_id'] ?? null)
                ->whereIn('relationship', ['PRINCIPAL', 'GROUP_MEMBER'])->first()
                ?? throw new ApiProblemException('PRINCIPAL_REQUIRED', 422, 'A dependant must be linked to a principal or group member of the same policy.');
        }
        if (! $name) {
            throw new ApiProblemException('NAME_REQUIRED', 422, 'display_name is required.');
        }
        if ($partyId && ! DB::table('parties')->where('id', $partyId)->exists()) {
            throw new ApiProblemException('PARTY_NOT_FOUND', 422, 'Party not found.');
        }
        $from = CarbonImmutable::parse($from ?? $policy->coverage_starts_at)->toDateString();
        $to = $to ? CarbonImmutable::parse($to)->toDateString() : null;
        if ($to !== null && $to <= $from) {
            throw new ApiProblemException('DATES_INVALID', 422, 'effective_to must be after effective_from.');
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $tenantId, $policyId, $rel, $principal, $partyId, $item, $name, $d, $from, $to, $actorId) {
            DB::table('health_members')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'policy_id' => $policyId,
                'member_number' => $this->uniqueNumber($tenantId, 'member_number', 'HM'), 'card_number' => $this->uniqueNumber($tenantId, 'card_number', 'HC'),
                'relationship' => $rel, 'principal_member_id' => $principal?->id, 'party_id' => $partyId, 'schedule_item_id' => $item?->id,
                'display_name' => $name, 'date_of_birth' => $d['date_of_birth'] ?? null, 'effective_from' => $from, 'effective_to' => $to,
                'status' => 'ACTIVE', 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('health_member.enrolled', 'policy', $policyId, ['member_id' => $id, 'relationship' => $rel, 'effective_from' => $from]);
            $this->outbox->record('health_member.enrolled', 'health_member', $id, ['tenant_id' => $tenantId, 'policy_id' => $policyId, 'member_id' => $id, 'relationship' => $rel]);
        });

        return $this->member($tenantId, $id);
    }

    public function end(string $tenantId, string $memberId, string $effectiveTo, string $reason): object
    {
        $m = $this->member($tenantId, $memberId);
        $to = CarbonImmutable::parse($effectiveTo)->toDateString();
        if ($m->status === 'ENDED' || $to <= $m->effective_from) {
            throw new ApiProblemException('MEMBER_END_INVALID', 409, 'Member already ended or end date not after the start date.');
        }
        DB::transaction(function () use ($m, $to, $reason, $tenantId) {
            DB::table('health_members')->where('id', $m->id)->update(['effective_to' => $to, 'status' => 'ENDED', 'end_reason' => $reason, 'updated_at' => now()]);
            DB::table('health_member_cards')->where('health_member_id', $m->id)->where('status', 'ACTIVE')->update(['status' => 'REVOKED', 'revoked_at' => now(), 'updated_at' => now()]);
            $this->audit->record('health_member.ended', 'policy', $m->policy_id, ['member_id' => $m->id, 'effective_to' => $to], $reason);
            $this->outbox->record('health_member.ended', 'health_member', $m->id, ['tenant_id' => $tenantId, 'member_id' => $m->id, 'effective_to' => $to]);
        });

        return $this->member($tenantId, $memberId);
    }

    public function members(string $tenantId, string $policyId): array
    {
        $this->policy($tenantId, $policyId);

        return DB::table('health_members')->where('policy_id', $policyId)->orderBy('member_number')->get()->all();
    }

    public function member(string $tenantId, string $id): object
    {
        return DB::table('health_members')->where('tenant_id', $tenantId)->where('id', $id)->first()
            ?? throw new ApiProblemException('MEMBER_NOT_FOUND', 404, 'Health member not found.');
    }

    /** Member by member number or card number (the "member reference"). */
    public function findByReference(string $tenantId, string $ref): ?object
    {
        $ref = strtoupper(trim($ref));

        return DB::table('health_members')->where('tenant_id', $tenantId)->where(fn ($q) => $q->where('member_number', $ref)->orWhere('card_number', $ref))->first();
    }

    public function linkNetwork(string $tenantId, string $policyId, string $networkId, ?string $actorId): object
    {
        $this->policy($tenantId, $policyId);
        $net = DB::table('provider_networks')->where('tenant_id', $tenantId)->where('id', $networkId)->first()
            ?? throw new ApiProblemException('NETWORK_NOT_FOUND', 404, 'Network not found.');
        if ($net->category !== 'HEALTH') {
            throw new ApiProblemException('NETWORK_CATEGORY_MISMATCH', 422, 'Only HEALTH networks can be linked to a health policy.');
        }
        if (! DB::table('health_policy_networks')->where('policy_id', $policyId)->where('provider_network_id', $networkId)->exists()) {
            DB::table('health_policy_networks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'policy_id' => $policyId, 'provider_network_id' => $networkId,
                'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('health_policy.network_linked', 'policy', $policyId, ['provider_network_id' => $networkId]);
        }

        return DB::table('health_policy_networks')->where('policy_id', $policyId)->where('provider_network_id', $networkId)->first();
    }

    public function addBenefitRule(string $tenantId, array $d, ?string $actorId): object
    {
        if (empty($d['medical_service_code']) && empty($d['service_category_code'])) {
            throw new ApiProblemException('RULE_TARGET_REQUIRED', 422, 'A benefit rule needs a medical_service_code or a service_category_code.');
        }
        if (! empty($d['policy_id'])) {
            $this->policy($tenantId, $d['policy_id']);
        }
        $id = (string) Str::uuid();
        DB::table('health_benefit_rules')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'policy_id' => $d['policy_id'] ?? null,
            'medical_service_code' => isset($d['medical_service_code']) ? strtoupper($d['medical_service_code']) : null,
            'service_category_code' => isset($d['service_category_code']) ? strtoupper($d['service_category_code']) : null,
            'coverage_code' => $d['coverage_code'], 'benefit_code' => strtoupper($d['benefit_code']),
            'waiting_period_days' => $d['waiting_period_days'] ?? null, 'status' => 'ACTIVE', 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('health_benefit_rule.added', 'health_benefit_rule', $id, ['coverage_code' => $d['coverage_code'], 'policy_id' => $d['policy_id'] ?? null]);

        return DB::table('health_benefit_rules')->where('id', $id)->first();
    }

    /** Benefit schedule lookup: policy-specific before tenant default; exact service before category. */
    public function benefitRule(string $tenantId, string $policyId, string $serviceCode, ?string $categoryCode): ?object
    {
        return DB::table('health_benefit_rules')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where('policy_id', $policyId)->orWhereNull('policy_id'))
            ->where(fn ($q) => $q->where('medical_service_code', $serviceCode)->when($categoryCode, fn ($x) => $x->orWhere(fn ($y) => $y->whereNull('medical_service_code')->where('service_category_code', $categoryCode))))
            ->orderByRaw('CASE WHEN policy_id IS NULL THEN 1 ELSE 0 END')->orderByRaw('CASE WHEN medical_service_code IS NULL THEN 1 ELSE 0 END')->orderByDesc('created_at')
            ->first();
    }

    private function policy(string $tenantId, string $policyId): object
    {
        return DB::table('policies')->where('tenant_id', $tenantId)->where('id', $policyId)->first()
            ?? throw new ApiProblemException('POLICY_NOT_FOUND', 404, 'Policy not found.');
    }

    private function uniqueNumber(string $tenantId, string $column, string $prefix): string
    {
        do {
            $n = $prefix.'-'.strtoupper(Str::random(10));
        } while (DB::table('health_members')->where('tenant_id', $tenantId)->where($column, $n)->exists());

        return $n;
    }
}

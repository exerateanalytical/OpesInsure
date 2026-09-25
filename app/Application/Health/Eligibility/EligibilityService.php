<?php

declare(strict_types=1);

namespace App\Application\Health\Eligibility;

use App\Application\Policies\Chronology\PolicyChronologyWriter;
use App\Application\Providers\ProviderNetworkService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-HLT-001 — THE health eligibility check (Wave A contract: preauth, provider claims and the benefit
 * accumulator call check() guarded with class_exists).
 *
 * Evaluated at the service date against: the member's dated enrolment (and, for group members, the Batch 8-6
 * schedule item), the policy status (not issued / lapsed / dated suspension), the policy version in force at that
 * date (PolicyChronologyWriter::asOf) and its coverages, the benefit schedule (health_benefit_rules), waiting
 * periods counted from the member's effective_from, the benefit balance (BenefitAccumulator when present, else
 * the coverage's AGGREGATE policy_limit) and provider network membership (Batch 13A).
 *
 * Outcome precedence: NOT_ELIGIBLE > WAITING_PERIOD > BENEFIT_EXHAUSTED > PROVIDER_NOT_IN_NETWORK >
 * REVIEW_REQUIRED > ELIGIBLE. Every reason found is returned. Every check is logged in the append-only
 * health_eligibility_checks table.
 */
final class EligibilityService
{
    public const OUTCOMES = ['NOT_ELIGIBLE', 'WAITING_PERIOD', 'BENEFIT_EXHAUSTED', 'PROVIDER_NOT_IN_NETWORK', 'REVIEW_REQUIRED', 'ELIGIBLE'];

    private const NOT_ISSUED = ['PENDING_PAYMENT', 'PAID_PENDING_ISSUANCE'];

    public function __construct(
        private readonly HealthMemberService $members,
        private readonly PolicyChronologyWriter $chronology,
        private readonly ProviderNetworkService $networks,
    ) {}

    /**
     * @return array{check_id: string, outcome: string, eligible: bool, reasons: list<array{code: string, outcome: string, message: string}>, service_date: string,
     *   member: ?array, policy_id: ?string, policy_version_id: ?string, coverage: ?array, benefit_code: ?string, service: ?array, waiting_period_ends_on: ?string}
     */
    public function check(string $tenantId, string $memberRef, ?string $providerId, string $serviceCode, \DateTimeInterface|string|null $at = null, string $channel = 'API', ?string $actorId = null, ?string $policyId = null): array
    {
        $day = CarbonImmutable::parse($at ?? now())->startOfDay();
        $date = $day->toDateString();
        $serviceCode = strtoupper(trim($serviceCode));
        $reasons = [];
        $add = function (string $outcome, string $code, string $message) use (&$reasons) {
            $reasons[] = ['code' => $code, 'outcome' => $outcome, 'message' => $message];
        };
        $ctx = ['member' => null, 'health_member_id' => null, 'member_basis' => null, 'policy_id' => null, 'policy_version_id' => null, 'coverage' => null, 'benefit_code' => null, 'service' => null, 'waiting_period_ends_on' => null];

        $member = $this->memberFor($tenantId, $memberRef, $policyId);
        $policyLevel = null;
        if (! $member) {
            // A policy that does not use the member registry (no health_members enrolled) is checked at policy level
            // when the caller names it; a policy with enrolled members only admits its enrolled members.
            $policyLevel = $policyId !== null && ! DB::table('health_members')->where('policy_id', $policyId)->exists()
                ? DB::table('policies')->where('tenant_id', $tenantId)->where('id', $policyId)->first() : null;
            if (! $policyLevel) {
                $add('NOT_ELIGIBLE', 'MEMBER_NOT_FOUND', 'No health member matches this reference.');

                return $this->finish($tenantId, $memberRef, null, $providerId, $serviceCode, $date, $reasons, $ctx, $channel, $actorId);
            }
            $ctx['member_basis'] = 'POLICY';
            $ctx['policy_id'] = $policyLevel->id;
        } else {
            $ctx['member'] = ['id' => $member->id, 'member_number' => $member->member_number, 'card_number' => $member->card_number, 'relationship' => $member->relationship, 'display_name' => $member->display_name];
            $ctx['health_member_id'] = $member->id;
            $ctx['member_basis'] = 'MEMBER';
            $ctx['policy_id'] = $member->policy_id;
            if ($policyId !== null && $member->policy_id !== $policyId) {
                $add('NOT_ELIGIBLE', 'MEMBER_POLICY_MISMATCH', 'The member is not enrolled on the named policy.');
            }
        }

        // ---- member enrolment at the service date
        $mFrom = CarbonImmutable::parse($member->effective_from ?? $policyLevel->coverage_starts_at ?? $date)->toDateString();
        $mTo = $member?->effective_to ? CarbonImmutable::parse($member->effective_to)->toDateString() : null;
        if ($member && ($date < $mFrom || ($mTo !== null && $date >= $mTo))) {
            $add('NOT_ELIGIBLE', 'MEMBER_NOT_COVERED_ON_DATE', "Member is covered from {$mFrom}".($mTo ? " until {$mTo}" : '').'.');
        } elseif ($member?->status === 'SUSPENDED') {
            $add('NOT_ELIGIBLE', 'MEMBER_SUSPENDED', 'Member is suspended.');
        }
        if ($member?->schedule_item_id) {
            $item = DB::table('policy_schedule_items')->where('id', $member->schedule_item_id)->first();
            $iFrom = CarbonImmutable::parse($item->effective_from)->toDateString();
            $iTo = $item->effective_until ? CarbonImmutable::parse($item->effective_until)->toDateString() : null;
            if ($date < $iFrom || ($iTo !== null && $date >= $iTo)) {
                $add('NOT_ELIGIBLE', 'GROUP_MEMBER_NOT_ON_SCHEDULE', 'Member is not on the group schedule at the service date.');
            }
        }

        // ---- policy status
        $policy = $policyLevel ?? DB::table('policies')->where('id', $member->policy_id)->first();
        if (in_array($policy->status, self::NOT_ISSUED, true)) {
            $add('NOT_ELIGIBLE', 'POLICY_NOT_ISSUED', "Policy is {$policy->status}.");
        } elseif ($policy->status === 'LAPSED') {
            $add('NOT_ELIGIBLE', 'POLICY_LAPSED', 'Policy has lapsed.');
        } elseif ($policy->status === 'CANCELLED' && ! DB::table('policy_versions')->where('policy_id', $policy->id)->where('kind', 'CANCELLATION')->exists()) {
            // Cancelled without a dated cancellation version: the effective date is unknown, so no service date is covered.
            $add('NOT_ELIGIBLE', 'POLICY_CANCELLED', 'Policy is cancelled.');
        }
        $suspended = DB::table('policy_suspensions')->where('policy_id', $policy->id)->where('suspended_at', '<=', $day->endOfDay())
            ->where(fn ($q) => $q->whereIn('status', ['SUSPENDED', 'REINSTATEMENT_REQUESTED'])
                ->orWhere(fn ($x) => $x->whereRaw('COALESCE(reinstated_at, ended_at) > ?', [$day->endOfDay()])))
            ->exists();
        if ($suspended) {
            $add('NOT_ELIGIBLE', 'POLICY_SUSPENDED', 'Policy is suspended at the service date.');
        }
        if ($policy->coverage_starts_at && ($date < CarbonImmutable::parse($policy->coverage_starts_at)->toDateString()
            || ($policy->coverage_ends_at && $date > CarbonImmutable::parse($policy->coverage_ends_at)->toDateString()))) {
            $add('NOT_ELIGIBLE', 'OUTSIDE_POLICY_PERIOD', 'Service date is outside the policy period.');
        }

        // ---- policy version in force at the service date
        $version = $this->chronology->asOf($policy->id, $day->endOfDay());
        if (! $version) {
            $add('REVIEW_REQUIRED', 'NO_POLICY_VERSION', 'No policy version is recorded for the service date.');
        } else {
            $ctx['policy_version_id'] = $version->id;
            if (in_array($version->kind, ['CANCELLATION', 'SUSPENSION'], true)) {
                $add('NOT_ELIGIBLE', 'POLICY_'.($version->kind === 'CANCELLATION' ? 'CANCELLED' : 'SUSPENDED'), "Policy version in force is a {$version->kind}.");
            }
        }

        // ---- service & benefit schedule
        $service = DB::table('medical_services')->where('code', $serviceCode)->first();
        $category = $service?->category_code;
        $ctx['service'] = ['code' => $serviceCode, 'category_code' => $category];
        if (! $service) {
            $add('REVIEW_REQUIRED', 'SERVICE_UNKNOWN', "Medical service {$serviceCode} is not in the catalogue.");
        }
        $rule = $this->members->benefitRule($tenantId, $policy->id, $serviceCode, $category);
        if (! $rule) {
            $add('REVIEW_REQUIRED', 'BENEFIT_NOT_MAPPED', 'No benefit schedule entry covers this service.');
        } else {
            $ctx['benefit_code'] = $rule->benefit_code;
            $coverage = $version ? DB::table('policy_coverages')->where('policy_version_id', $version->id)->where('coverage_code', $rule->coverage_code)->first() : null;
            if ($version && (! $coverage || $day->endOfDay()->lt(CarbonImmutable::parse($coverage->starts_at)) || $day->gt(CarbonImmutable::parse($coverage->ends_at)))) {
                $add('NOT_ELIGIBLE', 'COVERAGE_NOT_IN_FORCE', "Coverage {$rule->coverage_code} is not in force at the service date.");
            }
            if ($coverage) {
                $ctx['coverage'] = ['code' => $coverage->coverage_code, 'limit_minor' => $coverage->limit_minor === null ? null : (int) $coverage->limit_minor,
                    'deductible_minor' => $coverage->deductible_minor === null ? null : (int) $coverage->deductible_minor, 'currency' => $coverage->currency];
            }
            if ($rule->waiting_period_days !== null) {
                $ends = CarbonImmutable::parse($mFrom)->addDays((int) $rule->waiting_period_days)->toDateString();
                if ($date < $ends) {
                    $ctx['waiting_period_ends_on'] = $ends;
                    $add('WAITING_PERIOD', 'WAITING_PERIOD', "Waiting period for {$rule->benefit_code} runs until {$ends}.");
                }
            }
            if ($this->exhausted($tenantId, $member ? $member->id : ['member_ref' => $memberRef, 'policy_id' => $policy->id], $rule, $coverage, $day)) {
                $add('BENEFIT_EXHAUSTED', 'BENEFIT_EXHAUSTED', "Benefit {$rule->benefit_code} is exhausted for the period.");
            }
        }

        // ---- provider network
        if ($providerId !== null) {
            $networks = DB::table('health_policy_networks')->where('policy_id', $policy->id)->pluck('provider_network_id');
            if ($networks->isEmpty()) {
                $add('REVIEW_REQUIRED', 'NETWORK_NOT_CONFIGURED', 'No provider network is linked to this policy.');
            } elseif (! $networks->contains(fn ($n) => $this->networks->isInNetwork($tenantId, $n, $providerId, $date))) {
                $add('PROVIDER_NOT_IN_NETWORK', 'PROVIDER_NOT_IN_NETWORK', 'Provider is not an active member of the policy networks at the service date.');
            }
        }

        return $this->finish($tenantId, $memberRef, $member, $providerId, $serviceCode, $date, $reasons, $ctx, $channel, $actorId, $rule?->coverage_code);
    }

    /** @param string|array $member E5 member argument: the health_members id, or a policy-level member ref + policy */
    private function exhausted(string $tenantId, string|array $member, object $rule, ?object $coverage, CarbonImmutable $day): bool
    {
        $acc = \App\Application\Health\Benefits\BenefitAccumulator::class;
        if (class_exists($acc)) {
            $svc = app($acc);
            if (method_exists($svc, 'remaining')) {
                try {
                    $left = $svc->remaining($tenantId, $member, $rule->benefit_code, $day);
                    if (is_array($left)) {
                        $left = $left['remaining_minor'] ?? null;
                    }

                    return $left !== null && (int) $left <= 0;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
        if (! $coverage) {
            return false;
        }
        $agg = DB::table('policy_limits')->where('policy_coverage_id', $coverage->id)->where('limit_type', 'AGGREGATE')->first();

        return $agg !== null && (int) $agg->consumed_minor + (int) $agg->reserved_minor >= (int) $agg->amount_minor;
    }

    /**
     * Resolve a member reference: member / card number, a health_members id, or (with the policy) the party id of a
     * member of that policy — the references preauthorization and provider claims carry.
     */
    public function memberFor(string $tenantId, string $ref, ?string $policyId = null): ?object
    {
        $m = $this->members->findByReference($tenantId, $ref);
        if ($m || ! \Illuminate\Support\Str::isUuid($ref)) {
            return $m;
        }

        return DB::table('health_members')->where('tenant_id', $tenantId)->where('id', $ref)->first()
            ?? ($policyId === null ? null : DB::table('health_members')->where('tenant_id', $tenantId)->where('policy_id', $policyId)->where('party_id', $ref)
                ->where('status', '<>', 'ENDED')->orderByRaw("CASE WHEN relationship = 'PRINCIPAL' THEN 0 ELSE 1 END")->first());
    }

    private function finish(string $tenantId, string $memberRef, ?object $member, ?string $providerId, string $serviceCode, string $date, array $reasons, array $ctx, string $channel, ?string $actorId, ?string $coverageCode = null): array
    {
        $outcome = 'ELIGIBLE';
        foreach (self::OUTCOMES as $o) {
            if (collect($reasons)->contains('outcome', $o)) {
                $outcome = $o;
                break;
            }
        }
        $id = (string) Str::uuid();
        DB::table('health_eligibility_checks')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'health_member_id' => $member?->id, 'member_ref_hash' => hash('sha256', strtoupper(trim($memberRef))),
            'policy_id' => $ctx['policy_id'], 'policy_version_id' => $ctx['policy_version_id'], 'provider_profile_id' => $providerId,
            'service_code' => $serviceCode, 'service_date' => $date, 'coverage_code' => $coverageCode, 'benefit_code' => $ctx['benefit_code'],
            'outcome' => $outcome, 'reasons' => json_encode($reasons), 'channel' => $channel, 'actor_id' => $actorId, 'checked_at' => now(),
        ]);

        return ['check_id' => $id, 'outcome' => $outcome, 'eligible' => $outcome === 'ELIGIBLE', 'reasons' => $reasons, 'service_date' => $date] + $ctx;
    }

    /** Eligibility check log for a member (most recent first). */
    public function history(string $tenantId, string $memberId): array
    {
        $this->members->member($tenantId, $memberId);

        return DB::table('health_eligibility_checks')->where('tenant_id', $tenantId)->where('health_member_id', $memberId)->orderByDesc('checked_at')->get()
            ->map(fn ($r) => (array) $r + ['reasons_decoded' => json_decode($r->reasons, true)])->all();
    }
}

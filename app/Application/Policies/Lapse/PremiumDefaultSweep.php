<?php

declare(strict_types=1);

namespace App\Application\Policies\Lapse;

use App\Application\Events\OutboxWriter;
use App\Application\Rules\PremiumCover\PremiumCoverEvaluator;
use App\Models\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * REQ-POL-008 in-force side / REQ-POL-010 (WF-083) — scheduled as `policies:premium-cover-sweep`.
 *
 * Every unsettled instalment past its due date is evaluated with PremiumCoverEvaluator (owner decision #17:
 * the configurable rule engine decides, never a hard-coded Boolean). Facts: premium.status (OVERDUE /
 * PARTIALLY_PAID), premium.days_overdue, premium.outstanding_minor, instalment.sequence.
 *
 *   COVER_ACTIVE / UNDETERMINED / MORE_INFORMATION_REQUIRED → OVERDUE (recorded, no action is assumed)
 *   GRACE, today ≤ due + grace_days                        → GRACE (grace_ends_on set)
 *   GRACE window elapsed, COVER_SUSPENDED or NO_COVER       → DEFAULTED + policy suspended (PolicySuspender)
 *   DEFAULTED for ≥ rule.lapse_after_days                   → LAPSED (NULL lapse_after_days never lapses)
 *
 * Only instalments of ACTIVE / EXPIRING / SUSPENDED policies are swept. Idempotent: events are written only
 * when an instalment changes status; the suspender ignores policies already suspended.
 */
final class PremiumDefaultSweep
{
    public const UNSETTLED = ['DUE', 'OVERDUE', 'GRACE', 'DEFAULTED'];

    public const DEFAULT_OUTCOMES = ['COVER_SUSPENDED', 'NO_COVER'];

    public function __construct(
        private readonly PremiumCoverEvaluator $evaluator,
        private readonly PolicySuspender $suspender,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @return array{evaluated: int, grace: int, defaulted: int, suspended: int, lapsed: int} */
    public function run(?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $stats = ['evaluated' => 0, 'grace' => 0, 'defaulted' => 0, 'suspended' => 0, 'lapsed' => 0];

        DB::table('policy_premium_instalments as i')->join('policies as p', 'p.id', '=', 'i.policy_id')
            ->whereIn('i.status', self::UNSETTLED)->whereDate('i.due_date', '<', $today->toDateString())
            ->whereIn('p.status', ['ACTIVE', 'EXPIRING', 'SUSPENDED'])
            ->orderBy('i.due_date')->select('i.id')->pluck('i.id')
            ->each(function (string $id) use ($today, &$stats): void {
                $stats['evaluated']++;
                $result = DB::transaction(fn () => $this->evaluateOne($id, $today));
                if ($result !== null) {
                    $stats[$result]++;
                    if ($result === 'defaulted') {
                        $policyId = (string) DB::table('policy_premium_instalments')->where('id', $id)->value('policy_id');
                        if ($this->suspender->suspend($policyId, 'PREMIUM_DEFAULT', ['instalment_id' => $id])) {
                            $stats['suspended']++;
                        }
                    }
                }
            });

        return $stats;
    }

    /** @return 'grace'|'defaulted'|'lapsed'|null the status change made, if any */
    private function evaluateOne(string $id, CarbonImmutable $today): ?string
    {
        $i = DB::table('policy_premium_instalments')->where('id', $id)->lockForUpdate()->first();
        if (! $i || ! in_array($i->status, self::UNSETTLED, true)) {
            return null;
        }

        if ($i->status === 'DEFAULTED') {
            return $this->maybeLapse($i, $today);
        }

        $policy = Policy::with('proposal.offer.product', 'proposal.offer.quote')->findOrFail($i->policy_id);
        $offer = $policy->proposal?->offer;
        $daysOverdue = (int) CarbonImmutable::parse($i->due_date)->startOfDay()->diffInDays($today);
        $outstanding = (int) $i->amount_minor - (int) $i->paid_minor;
        $cover = $this->evaluator->evaluate([
            'product_id' => $offer?->product_id, 'carrier_id' => $policy->carrier_id,
            'class_code' => $offer?->product?->getAttribute('class_code') ?? $offer?->quote?->line_code,
            'jurisdiction' => $policy->terms_snapshot['jurisdiction'] ?? null,
            'premium_status' => (int) $i->paid_minor > 0 ? 'PARTIALLY_PAID' : 'OVERDUE',
            'effective_date' => $today->toDateString(),
            'facts' => [
                'premium' => ['days_overdue' => $daysOverdue, 'outstanding_minor' => $outstanding, 'amount_minor' => (int) $i->amount_minor],
                'instalment' => ['sequence' => (int) $i->sequence, 'due_date' => (string) $i->due_date],
                'policy' => ['id' => $policy->id, 'status' => $policy->status],
            ],
        ]);

        $snapshot = ['outcome' => $cover['outcome'], 'rule' => $cover['rule'], 'missing_facts' => $cover['missing_facts'], 'days_overdue' => $daysOverdue];
        $update = ['last_outcome' => $cover['outcome'], 'last_evaluation' => json_encode($snapshot), 'last_evaluated_at' => now(), 'updated_at' => now(),
            'premium_cover_rule_id' => $cover['rule']['id'] ?? null];

        $graceEnds = null;
        if ($cover['outcome'] === 'GRACE' && $cover['rule']['grace_days'] !== null) {
            $graceEnds = CarbonImmutable::parse($i->due_date)->addDays((int) $cover['rule']['grace_days']);
        }
        $inGrace = $graceEnds !== null && $today->lessThanOrEqualTo($graceEnds);
        $defaulted = in_array($cover['outcome'], self::DEFAULT_OUTCOMES, true) || ($graceEnds !== null && ! $inGrace);

        $payload = ['policy_id' => $i->policy_id, 'instalment_id' => $i->id, 'sequence' => (int) $i->sequence,
            'outstanding_minor' => $outstanding, 'currency' => $i->currency, 'days_overdue' => $daysOverdue,
            'outcome' => $cover['outcome'], 'rule_code' => $cover['rule']['code'] ?? null];

        if ($defaulted) {
            DB::table('policy_premium_instalments')->where('id', $i->id)->update($update + ['status' => 'DEFAULTED', 'defaulted_at' => now(), 'grace_ends_on' => $graceEnds?->toDateString() ?? $i->grace_ends_on]);
            $this->outbox->record('policy.premium.defaulted', 'policy', $i->policy_id, $payload);

            return 'defaulted';
        }
        if ($inGrace) {
            DB::table('policy_premium_instalments')->where('id', $i->id)->update($update + ['status' => 'GRACE', 'grace_ends_on' => $graceEnds->toDateString()]);
            if ($i->status !== 'GRACE') {
                $this->outbox->record('policy.premium.grace_started', 'policy', $i->policy_id, $payload + ['grace_ends_on' => $graceEnds->toDateString()]);

                return 'grace';
            }

            return null;
        }
        DB::table('policy_premium_instalments')->where('id', $i->id)->update($update + ['status' => 'OVERDUE']);

        return null;
    }

    private function maybeLapse(object $i, CarbonImmutable $today): ?string
    {
        $days = $i->premium_cover_rule_id ? DB::table('premium_cover_rules')->where('id', $i->premium_cover_rule_id)->value('lapse_after_days') : null;
        if ($days === null || ! $i->defaulted_at || CarbonImmutable::parse($i->defaulted_at)->startOfDay()->addDays((int) $days)->greaterThan($today)) {
            return null;
        }
        DB::table('policy_premium_instalments')->where('id', $i->id)->update(['status' => 'LAPSED', 'lapsed_at' => now(), 'updated_at' => now()]);
        $this->outbox->record('policy.premium.lapsed', 'policy', $i->policy_id, [
            'policy_id' => $i->policy_id, 'instalment_id' => $i->id, 'lapse_after_days' => (int) $days,
            'outstanding_minor' => (int) $i->amount_minor - (int) $i->paid_minor, 'currency' => $i->currency,
        ]);

        return 'lapsed';
    }
}

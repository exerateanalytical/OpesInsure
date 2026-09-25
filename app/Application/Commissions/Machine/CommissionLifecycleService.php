<?php

declare(strict_types=1);

namespace App\Application\Commissions\Machine;

use App\Application\Attribution\PolicyAttributionResolver;
use App\Application\Audit\AuditWriter;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\FinancialDistribution\CommissionService;
use App\Models\CommissionAccrual;
use App\Models\Policy;
use App\Models\PolicyTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-COM-001 (WF-064..067) — the commission lifecycle on CommissionMachine.
 *
 * Policy hooks (each wraps the existing CommissionService accrual paths; never blocks the policy operation that calls it):
 *   onPolicyIssued       — SALE → ACCRUED for the producing intermediary (PolicyAttributionResolver) under the most specific
 *                          APPROVED rule (CommissionRuleLocator); earns straight away when the bind payment already settled the premium.
 *   onPremiumSettled     — ACCRUED → EARNED once every premium obligation of the policy is SETTLED (posts commission.earned).
 *   onEndorsementApproved — additional premium: a separate ENDORSEMENT accrual; return premium: pro-rata clawback.
 *   onPolicyCancelled    — pro-rata clawback (unexpired share of the term from the cancellation effective date); unearned
 *                          commission cancelled from inception is REVERSED; commission already paid out becomes a RECEIVABLE
 *                          COMMISSION obligation on the partner (recovery).
 * Manual steps: approve, makePayable (PAYABLE obligation), adjust (→ ADJUSTED, maker-checker re-approval), dispute / resolveDispute,
 * reverse, settlePaid (after a payout has paid it). advance() is the scheduled sweep (commissions:advance).
 */
final class CommissionLifecycleService
{
    public function __construct(
        private readonly CommissionService $commissions,
        private readonly CommissionTransitions $machine,
        private readonly CommissionRuleLocator $rules,
        private readonly PolicyAttributionResolver $attribution,
        private readonly ObligationService $obligations,
        private readonly AuditWriter $audit,
    ) {}

    // ---- policy hooks -------------------------------------------------------------------------------------------

    public function onPolicyIssued(Policy $policy): ?CommissionAccrual
    {
        $partnerId = $this->attribution->forPolicy($policy)['partner_id'] ?? null;
        if (! $partnerId || (int) $policy->premium_minor <= 0) {
            return null;
        }
        $rule = $this->rules->forPolicy($policy, $partnerId, Carbon::parse($policy->issued_at ?? now()));
        if (! $rule) {
            return null;
        }
        $a = $this->commissions->accrue($policy, $rule, $partnerId, 'policy-issued:'.$policy->id);
        $this->onPremiumSettled($policy->id);

        return $a->refresh();
    }

    /** @return int accruals earned */
    public function onPremiumSettled(string $policyId): int
    {
        $n = 0;
        foreach (CommissionAccrual::where('policy_id', $policyId)->where('status', 'PENDING')->pluck('id') as $id) {
            $n += DB::transaction(function () use ($id): int {
                $a = CommissionAccrual::lockForUpdate()->findOrFail($id);
                if ($a->status !== 'PENDING' || ! CommissionTransitions::premiumSettled($a)) {
                    return 0;
                }
                $this->earn($a);

                return 1;
            });
        }

        return $n;
    }

    public function onEndorsementApproved(Policy $policy, PolicyTransaction $t, ?User $actor = null): void
    {
        $delta = (int) $t->premium_delta_minor;
        if ($delta > 0) {
            $base = CommissionAccrual::where('policy_id', $policy->id)->where('source_type', 'POLICY')->orderBy('created_at')->first();
            $rule = $base?->rule_version_id ? \App\Models\CommissionRuleVersion::find($base->rule_version_id) : null;
            if ($base && $rule && $rule->status === 'APPROVED') {
                $this->commissions->accrue($policy, $rule, $base->partner_id, 'policy-endorsement:'.$t->id, $delta, 'ENDORSEMENT', 'ENDORSEMENT', $t->id);
            }

            return;
        }
        if ($delta < 0) {
            $before = (int) $policy->premium_minor - $delta;
            if ($before > 0) {
                $this->clawBackShare($policy->id, -$delta, $before, 'ENDORSEMENT', 'endorsement:'.$t->id, $actor);
            }
        }
    }

    public function onPolicyCancelled(Policy $policy, PolicyTransaction $t, ?User $actor = null): void
    {
        $start = CarbonImmutable::parse($policy->coverage_starts_at ?? $policy->issued_at ?? $policy->created_at);
        $end = $policy->coverage_ends_at ? CarbonImmutable::parse($policy->coverage_ends_at) : $start->addYear();
        $effective = CarbonImmutable::parse($t->effective_at ?? now());
        $term = max(1, (int) $start->diffInSeconds($end));
        $unexpired = (int) min($term, max(0, (int) $effective->max($start)->diffInSeconds($end, false)));
        $this->clawBackShare($policy->id, $unexpired, $term, 'CANCELLATION', 'cancellation:'.$t->id, $actor);
    }

    /** Claw back numerator/denominator of every live accrual of the policy (idempotent per $reference). */
    private function clawBackShare(string $policyId, int $num, int $den, string $reason, string $reference, ?User $actor): void
    {
        if ($num <= 0 || $den <= 0) {
            return;
        }
        foreach (CommissionAccrual::where('policy_id', $policyId)->whereIn('status', CommissionMachine::LIVE)->pluck('id') as $id) {
            DB::transaction(function () use ($id, $num, $den, $reason, $reference, $actor): void {
                $a = CommissionAccrual::lockForUpdate()->findOrFail($id);
                if (DB::table('commission_movements')->where('commission_accrual_id', $a->id)->where('metadata->reference', $reference)->exists()) {
                    return;
                }
                $net = (int) $a->amount_minor - (int) $a->clawed_back_minor;
                $share = $num >= $den ? $net : intdiv($net * $num, $den);
                if ($share <= 0) {
                    return;
                }
                $marker = fn () => DB::table('commission_movements')->insert([
                    'id' => (string) Str::uuid(), 'commission_accrual_id' => $a->id, 'type' => 'CLAWBACK_REF', 'amount_minor' => 0, 'currency' => $a->currency,
                    'reason_code' => $reason, 'actor_id' => $actor?->id, 'occurred_at' => now(), 'metadata' => json_encode(['reference' => $reference, 'share_minor' => $share]),
                ]);
                // Full share of commission never earned: the accrual is reversed rather than clawed back.
                if ($share >= $net && (int) $a->paid_minor === 0 && in_array($a->status, ['CALCULATED', 'PENDING'], true)) {
                    $marker();
                    $this->reverseLocked($a, $reason, $actor);

                    return;
                }
                $available = max(0, $net - (int) $a->paid_minor);
                $claw = min($share, $available);
                $marker();
                if ($claw > 0) {
                    $this->commissions->clawback($a, $claw, $reason, $actor);
                }
                if ($share > $claw) {
                    // Already paid out: recover it from the partner.
                    $this->obligations->create([
                        'tenant_id' => $a->tenant_id, 'kind' => 'RECEIVABLE', 'type' => 'COMMISSION', 'currency' => $a->currency, 'amount_minor' => $share - $claw,
                        'debtor_type' => 'partner', 'debtor_id' => $a->partner_id, 'policy_id' => $a->policy_id,
                        'source_type' => CommissionTransitions::OBLIGATION_SOURCE, 'source_id' => $a->id, 'source_reference' => 'RECOVERY:'.$reference,
                        'due_at' => now(), 'description' => 'Commission clawback recovery ('.$reason.')',
                    ], $actor?->id);
                }
            });
        }
    }

    // ---- manual / scheduled steps -------------------------------------------------------------------------------

    public function earn(CommissionAccrual $a, ?User $actor = null): CommissionAccrual
    {
        return DB::transaction(function () use ($a, $actor): CommissionAccrual {
            $a = CommissionAccrual::lockForUpdate()->findOrFail($a->id);
            $this->machine->apply($a, 'earn', $actor, 'PREMIUM_SETTLED', ['earned_at' => now()]);
            $this->machine->post($a->tenant_id, 'commission.earned', $a->id, (int) $a->amount_minor - (int) $a->clawed_back_minor, $a->currency);

            return $a->refresh();
        });
    }

    public function approve(CommissionAccrual $a, User $actor, ?string $note = null): CommissionAccrual
    {
        return $this->step($a, 'approve', $actor, $note, fn () => ['approved_at' => now(), 'approved_by' => $actor->id]);
    }

    public function makePayable(CommissionAccrual $a, ?User $actor = null): CommissionAccrual
    {
        return DB::transaction(function () use ($a, $actor): CommissionAccrual {
            $a = CommissionAccrual::lockForUpdate()->findOrFail($a->id);
            $this->machine->apply($a, 'make_payable', $actor, 'APPROVED_AND_VESTED', [
                'vested_minor' => (int) $a->amount_minor - (int) $a->clawed_back_minor, 'payable_at' => now(),
            ]);
            $this->machine->syncPayable($a, $actor?->id);

            return $a->refresh();
        });
    }

    /** Set a new commission amount (maker); the accrual must be re-approved by someone else. */
    public function adjust(CommissionAccrual $a, int $newAmountMinor, string $reason, User $actor): CommissionAccrual
    {
        return DB::transaction(function () use ($a, $newAmountMinor, $reason, $actor): CommissionAccrual {
            $a = CommissionAccrual::lockForUpdate()->findOrFail($a->id);
            if ($newAmountMinor < (int) $a->clawed_back_minor + (int) $a->paid_minor) {
                throw ValidationException::withMessages(['amount_minor' => 'The adjusted amount cannot be below what is already clawed back or paid.']);
            }
            $delta = $newAmountMinor - (int) $a->amount_minor;
            $this->machine->apply($a, 'adjust', $actor, $reason, ['amount_minor' => $newAmountMinor, 'adjusted_by' => $actor->id], null, ['delta_minor' => $delta]);
            $this->movement($a, 'ADJUSTMENT', $delta, $reason, $actor, $delta > 0 ? 'commission.accrued' : 'commission.clawed_back');

            return $a->refresh();
        });
    }

    public function dispute(CommissionAccrual $a, string $reason, User $actor): CommissionAccrual
    {
        return $this->step($a, 'dispute', $actor, $reason, fn () => ['disputed_at' => now(), 'dispute_reason' => $reason]);
    }

    /** Resolution goes through ADJUSTED, so the (possibly corrected) figure is re-approved; a payable obligation is withdrawn meanwhile. */
    public function resolveDispute(CommissionAccrual $a, ?int $newAmountMinor, string $note, User $actor): CommissionAccrual
    {
        return DB::transaction(function () use ($a, $newAmountMinor, $note, $actor): CommissionAccrual {
            $a = CommissionAccrual::lockForUpdate()->findOrFail($a->id);
            $delta = $newAmountMinor === null ? 0 : $newAmountMinor - (int) $a->amount_minor;
            if ($newAmountMinor !== null && $newAmountMinor < (int) $a->clawed_back_minor + (int) $a->paid_minor) {
                throw ValidationException::withMessages(['amount_minor' => 'The adjusted amount cannot be below what is already clawed back or paid.']);
            }
            $this->machine->apply($a, 'resolve_dispute', $actor, $note, ['adjusted_by' => $actor->id] + ($delta !== 0 ? ['amount_minor' => $newAmountMinor] : []), null, ['delta_minor' => $delta]);
            if ($delta !== 0) {
                $this->movement($a, 'ADJUSTMENT', $delta, 'DISPUTE_RESOLVED', $actor, $delta > 0 ? 'commission.accrued' : 'commission.clawed_back');
            }
            if ($a->financial_obligation_id && ($o = DB::table('financial_obligations')->where('id', $a->financial_obligation_id)->first()) && $o->status === 'OPEN') {
                $this->obligations->cancel($o->id, 'Commission dispute resolved; awaiting re-approval.', $actor->id);
                $a->update(['financial_obligation_id' => null]);
            }

            return $a->refresh();
        });
    }

    public function reverse(CommissionAccrual $a, string $reason, ?User $actor): CommissionAccrual
    {
        return DB::transaction(function () use ($a, $reason, $actor): CommissionAccrual {
            $a = CommissionAccrual::lockForUpdate()->findOrFail($a->id);

            return $this->reverseLocked($a, $reason, $actor);
        });
    }

    /** After a payout paid (part of) the accrual (PayoutService raises paid_minor): settle the payable obligation; PAYABLE → PAID when nothing is owed. */
    public function settlePaid(CommissionAccrual $a, string $reference, ?User $actor = null): CommissionAccrual
    {
        return DB::transaction(function () use ($a, $reference, $actor): CommissionAccrual {
            $a = CommissionAccrual::lockForUpdate()->findOrFail($a->id);
            if ($a->financial_obligation_id) {
                $o = DB::table('financial_obligations')->where('id', $a->financial_obligation_id)->first();
                $settle = (int) $o->outstanding_minor - CommissionTransitions::owed($a);
                if ($settle > 0) {
                    $this->obligations->settle($o->id, $settle, $reference, $actor?->id);
                }
            }
            if (CommissionTransitions::owed($a) === 0 && in_array($a->status, ['VESTED', 'AVAILABLE'], true)) {
                $this->machine->apply($a, 'pay', $actor, 'PAYOUT_COMPLETED', ['paid_at' => now()], null, ['reference' => $reference]);
            }

            return $a->refresh();
        });
    }

    /** @return array{earned:int, payable:int} scheduled sweep: settled premiums earn, approved + vested commission becomes payable. */
    public function advance(): array
    {
        $earned = 0;
        foreach (CommissionAccrual::where('status', 'PENDING')->distinct()->pluck('policy_id') as $policyId) {
            $earned += $this->onPremiumSettled($policyId);
        }
        $payable = 0;
        foreach (CommissionAccrual::where('status', 'APPROVED')->where(fn ($q) => $q->whereNull('vests_at')->orWhere('vests_at', '<=', now()))->pluck('id') as $id) {
            $this->makePayable(CommissionAccrual::findOrFail($id));
            $payable++;
        }

        return ['earned' => $earned, 'payable' => $payable];
    }

    // ---- internals ----------------------------------------------------------------------------------------------

    private function step(CommissionAccrual $a, string $event, User $actor, ?string $reason, \Closure $updates): CommissionAccrual
    {
        return DB::transaction(function () use ($a, $event, $actor, $reason, $updates): CommissionAccrual {
            $a = CommissionAccrual::lockForUpdate()->findOrFail($a->id);
            $this->machine->apply($a, $event, $actor, $reason, $updates());
            $this->audit->record('commission.'.$event, CommissionMachine::SUBJECT, $a->id, ['status' => $a->status], $reason);

            return $a->refresh();
        });
    }

    private function reverseLocked(CommissionAccrual $a, string $reason, ?User $actor): CommissionAccrual
    {
        $net = (int) $a->amount_minor - (int) $a->clawed_back_minor;
        $this->machine->apply($a, 'reverse', $actor, $reason, ['clawed_back_minor' => (int) $a->amount_minor]);
        if ($net > 0) {
            $this->movement($a, 'REVERSAL', -$net, $reason, $actor, 'commission.clawed_back');
        }

        return $a->refresh();
    }

    private function movement(CommissionAccrual $a, string $type, int $amount, string $reason, ?User $actor, string $postEvent): void
    {
        $id = (string) Str::uuid();
        DB::table('commission_movements')->insert([
            'id' => $id, 'commission_accrual_id' => $a->id, 'type' => $type, 'amount_minor' => $amount, 'currency' => $a->currency,
            'reason_code' => Str::limit($reason, 60, ''), 'actor_id' => $actor?->id, 'occurred_at' => now(), 'metadata' => '{}',
        ]);
        if ($journal = $this->machine->post($a->tenant_id, $postEvent, $id, abs($amount), $a->currency)) {
            DB::table('commission_movements')->where('id', $id)->update(['journal_id' => $journal]);
        }
    }
}

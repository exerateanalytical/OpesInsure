<?php

declare(strict_types=1);

namespace App\Application\Commissions\Machine;

use App\Application\Finance\Obligations\ObligationService;
use App\Application\Ledger\FinancialPostingService;
use App\Domain\Shared\StateMachine\GuardResult;
use App\Domain\Shared\StateMachine\StateMachineEngine;
use App\Domain\Shared\StateMachine\TransitionContext;
use App\Domain\Shared\StateMachine\TransitionDefinition;
use App\Domain\Shared\StateMachine\TransitionDenied;
use App\Models\CommissionAccrual;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-COM-001 low-level plumbing shared by CommissionService (accrue / vest / clawback) and CommissionLifecycleService:
 *   apply()   — one CommissionMachine transition through the shared engine (history in workflow_transition_history + outbox
 *               domain event) and the status write, inside the caller's transaction; the caller holds the row lock.
 *   post()    — FinancialPostingService::post for commission.accrued / commission.earned / commission.clawed_back (GL mapping is
 *               agent 10-6's posting profiles). A tenant without a posting profile for the event is skipped, not blocked.
 *   syncPayable() — keeps the PAYABLE COMMISSION financial obligation equal to what is still owed to the partner.
 */
final class CommissionTransitions
{
    public const OBLIGATION_SOURCE = 'commission_accrual';

    public function __construct(
        private readonly StateMachineEngine $engine,
        private readonly FinancialPostingService $posting,
        private readonly ObligationService $obligations,
    ) {
        $engine->registerGuard('commission.premium_settled', fn (TransitionDefinition $t, TransitionContext $c) => self::premiumSettled($c->subject)
            ? GuardResult::pass() : GuardResult::fail('The premium this commission is based on is not settled yet.'));
        $engine->registerGuard('commission.vesting_elapsed', fn (TransitionDefinition $t, TransitionContext $c) => $c->subject->vests_at === null || ! $c->subject->vests_at->isFuture()
            ? GuardResult::pass() : GuardResult::fail('The commission holdback / vesting period has not elapsed.'));
        $engine->registerGuard('commission.maker_checker', fn (TransitionDefinition $t, TransitionContext $c) => $c->subject->adjusted_by === null || $c->actorId() !== $c->subject->adjusted_by
            ? GuardResult::pass() : GuardResult::fail('The user who adjusted a commission cannot approve it.'));
    }

    /** Apply $event to a locked accrual. $from overrides the current state (a row created in this transaction starts at SALE). */
    public function apply(CommissionAccrual $a, string $event, ?User $actor = null, ?string $reason = null, array $updates = [], ?string $from = null, array $payload = []): CommissionAccrual
    {
        $current = $from ?? $a->status;
        try {
            $result = $this->engine->apply(CommissionMachine::definition(), $event, new TransitionContext(
                CommissionMachine::SUBJECT, $a->id, $current, $actor, $actor ? 'staff' : 'system',
                $payload + ['blueprint_from' => CommissionMachine::blueprintState($current), 'amount_minor' => (int) $a->amount_minor, 'currency' => $a->currency],
                $reason, $a,
            ));
        } catch (TransitionDenied $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }
        if ($result->to !== $a->status || $updates !== []) {
            $a->update(['status' => $result->to] + $updates);
        }

        return $a;
    }

    /** Post a commission business event; idempotent per (event, reference). Returns the journal id, or null when not posted. */
    public function post(?string $tenantId, string $event, string $referenceId, int $amountMinor, string $currency): ?string
    {
        if ($amountMinor <= 0) {
            return null;
        }
        // JournalLine is declared inside Journal.php (no PSR-4 file of its own): load it before LedgerService needs it.
        class_exists(\App\Domain\Ledger\Journal::class);
        try {
            // Savepoint: a failed posting must not poison the caller's transaction.
            return DB::transaction(fn () => $this->posting->post($tenantId, $event, $referenceId, $amountMinor, $currency, 'commission:'.$referenceId));
        } catch (ValidationException $e) {
            if (array_key_exists('posting_profile', $e->errors())) {
                return null;
            }
            throw $e;
        }
    }

    /** Still owed to the partner on a payable accrual. */
    public static function owed(CommissionAccrual $a): int
    {
        return max(0, (int) $a->amount_minor - (int) $a->clawed_back_minor - (int) $a->paid_minor);
    }

    /** Create (or re-size) the PAYABLE COMMISSION obligation of a payable accrual. */
    public function syncPayable(CommissionAccrual $a, ?string $actorId = null): ?object
    {
        $owed = self::owed($a);
        $current = $a->financial_obligation_id ? DB::table('financial_obligations')->where('id', $a->financial_obligation_id)->first() : null;
        if ($current && in_array($current->status, ObligationService::OPEN_STATUSES, true)) {
            if ((int) $current->outstanding_minor === $owed) {
                return $current;
            }
            if ($current->status === 'PARTIALLY_SETTLED' && (int) $current->outstanding_minor < $owed) {
                return $current;
            }
            $this->obligations->cancel($current->id, 'Commission re-sized (clawback / adjustment).', $actorId);
        }
        if ($owed <= 0) {
            $a->update(['financial_obligation_id' => null]);

            return null;
        }
        $seq = DB::table('financial_obligations')->where(['source_type' => self::OBLIGATION_SOURCE, 'source_id' => $a->id])->count();
        $o = $this->obligations->create([
            'tenant_id' => $a->tenant_id, 'kind' => 'PAYABLE', 'type' => 'COMMISSION', 'currency' => $a->currency, 'amount_minor' => $owed,
            'creditor_type' => 'partner', 'creditor_id' => $a->partner_id, 'policy_id' => $a->policy_id,
            'source_type' => self::OBLIGATION_SOURCE, 'source_id' => $a->id, 'source_reference' => $seq === 0 ? null : 'RESIZE:'.$seq,
            'due_at' => now(), 'description' => 'Commission payable', 'metadata' => ['rule_version_id' => $a->rule_version_id],
        ], $actorId);
        $a->update(['financial_obligation_id' => $o->id]);

        return $o;
    }

    /**
     * Earned = the premium is settled: every non-cancelled PREMIUM / INSTALMENT obligation of the policy is SETTLED; a legacy policy
     * without obligations counts as settled once its bind payment SUCCEEDED.
     */
    public static function premiumSettled(CommissionAccrual $a): bool
    {
        $obligations = DB::table('financial_obligations')->where('policy_id', $a->policy_id)->where('kind', 'RECEIVABLE')
            ->whereIn('type', ['PREMIUM', 'INSTALMENT'])->whereNotIn('status', ['CANCELLED'])->pluck('status');
        if ($obligations->isNotEmpty()) {
            return $obligations->every(fn ($s) => $s === 'SETTLED');
        }
        $paymentId = DB::table('policies')->where('id', $a->policy_id)->value('payment_intent_id');

        return $paymentId !== null && DB::table('payment_intents')->where('id', $paymentId)->value('status') === 'SUCCEEDED';
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Commissions\Statements;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Events\OutboxWriter;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\FinancialDistribution\DistributionEventWriter;
use App\Application\FinancialDistribution\PartnerStatementService;
use App\Models\CommissionAccrual;
use App\Models\PartnerPayoutRequest;
use App\Models\PartnerStatement;
use App\Models\PartnerStatementItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-COM-003 (WRS WF-066/067; FRP VI) — commission statement workflow on top of the Wave 6
 * partner_statements / partner_payout_requests lifecycle (no second statement or payout table):
 *
 *   generate / generatePeriod — one statement per partner per period+currency from commission_accruals
 *                               (delegates to PartnerStatementService::prepare; idempotent per partner/period).
 *   proposeAdjustment (maker) / approveAdjustment, rejectAdjustment (checker <> maker)
 *                             — ADJUSTMENT lines on a DRAFT or DISPUTED statement; only approved lines move the balance.
 *   dispute                   — APPROVED/PUBLISHED → DISPUTED, opens a COMMISSION_DISPUTE case, cancels the unpaid payable.
 *   resolveDispute            — DISPUTED → DRAFT (revision+1, re-approval by a checker opens a fresh payable), closes the case.
 * Approval, publication and payout stay in PartnerStatementService / PayoutService (payable + commission.paid via CommissionPayableLink).
 */
final class CommissionStatementService
{
    public const CASE_TYPE = 'COMMISSION_DISPUTE';

    public const ADJUSTABLE = ['DRAFT', 'DISPUTED'];

    public const DISPUTABLE = ['APPROVED', 'PUBLISHED'];

    public function __construct(
        private readonly PartnerStatementService $statements,
        private readonly ObligationService $obligations,
        private readonly CaseService $cases,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly DistributionEventWriter $events,
    ) {}

    public function generate(string $tenantId, string $partnerId, string $start, string $end, string $currency, User $actor, int $openingMinor = 0): PartnerStatement
    {
        return $this->statements->prepare([
            'tenant_id' => $tenantId, 'partner_id' => $partnerId, 'period_start' => $start, 'period_end' => $end, 'currency' => $currency,
            'opening_balance_minor' => $openingMinor, 'idempotency_key' => 'cst:'.$partnerId.':'.$start.':'.$end.':'.$currency,
        ], $actor);
    }

    /** @return list<PartnerStatement> one statement per partner with accruals in the period. */
    public function generatePeriod(string $tenantId, string $start, string $end, string $currency, User $actor): array
    {
        $partners = CommissionAccrual::where('tenant_id', $tenantId)->where('currency', $currency)
            ->whereBetween('created_at', [$start, $end.' 23:59:59'])->distinct()->orderBy('partner_id')->pluck('partner_id');

        return $partners->map(fn (string $p) => $this->existing($tenantId, $p, $start, $end, $currency) ?? $this->generate($tenantId, $p, $start, $end, $currency, $actor))->all();
    }

    public function proposeAdjustment(PartnerStatement $s, int $amountMinor, string $reason, User $maker): PartnerStatementItem
    {
        if ($amountMinor === 0) {
            $this->fail('amount_minor', 'ADJUSTMENT_ZERO', 'An adjustment must move the balance.');
        }

        return DB::transaction(function () use ($s, $amountMinor, $reason, $maker) {
            $s = PartnerStatement::lockForUpdate()->findOrFail($s->id);
            if (! in_array($s->status, self::ADJUSTABLE, true)) {
                $this->fail('statement', 'STATEMENT_NOT_ADJUSTABLE', "Adjustments are allowed on DRAFT or DISPUTED statements, not {$s->status}.");
            }
            $item = PartnerStatementItem::create([
                'partner_statement_id' => $s->id, 'entry_type' => 'ADJUSTMENT', 'reference_type' => 'commission_adjustment', 'reference_id' => (string) Str::uuid(),
                'amount_minor' => $amountMinor, 'currency' => $s->currency, 'occurred_at' => now(), 'metadata' => [],
                'adjustment_status' => 'PROPOSED', 'reason' => $reason, 'proposed_by' => $maker->id,
            ]);
            $this->audit->record('commission.statement.adjustment_proposed', 'partner_statement', $s->id, ['item_id' => $item->id, 'amount_minor' => $amountMinor], $reason);
            $this->outbox->record('commission.statement.adjustment_proposed', 'partner_statement', $s->id, ['statement_id' => $s->id, 'item_id' => $item->id, 'amount_minor' => $amountMinor, 'currency' => $s->currency]);

            return $item;
        });
    }

    public function approveAdjustment(PartnerStatementItem $item, User $checker): PartnerStatementItem
    {
        return $this->decide($item, $checker, 'APPROVED');
    }

    public function rejectAdjustment(PartnerStatementItem $item, User $checker, string $reason): PartnerStatementItem
    {
        return $this->decide($item, $checker, 'REJECTED', $reason);
    }

    public function dispute(PartnerStatement $s, string $reason, User $actor): PartnerStatement
    {
        return DB::transaction(function () use ($s, $reason, $actor) {
            $s = PartnerStatement::lockForUpdate()->findOrFail($s->id);
            if (! in_array($s->status, self::DISPUTABLE, true)) {
                $this->fail('statement', 'STATEMENT_NOT_DISPUTABLE', "Only APPROVED or PUBLISHED statements can be disputed, not {$s->status}.");
            }
            if (PartnerPayoutRequest::where('partner_statement_id', $s->id)->whereIn('status', ['REQUESTED', 'APPROVED', 'PROCESSING', 'PAID'])->exists()) {
                $this->fail('statement', 'STATEMENT_HAS_PAYOUTS', 'A statement with live or paid payouts cannot be disputed; reverse or fail them first.');
            }
            if ($s->obligation_id) {
                $this->obligations->cancel($s->obligation_id, 'COMMISSION_STATEMENT_DISPUTED', $actor->id);
            }
            $case = $this->cases->open($s->tenant_id, self::CASE_TYPE, [
                'title' => "Commission statement dispute {$s->statement_number}", 'priority' => 'NORMAL',
                'subject_type' => 'PARTNER_STATEMENT', 'subject_id' => $s->id, 'source_type' => 'partner_statement', 'source_id' => $s->id,
                'idempotency_key' => 'commission-dispute:'.$s->id.':r'.$s->revision,
            ], $actor);
            $from = $s->status;
            $s->update(['status' => 'DISPUTED', 'dispute_case_id' => $case->id, 'dispute_reason' => $reason, 'disputed_at' => now(), 'obligation_id' => null]);
            $this->events->write($s->tenant_id, 'PARTNER_STATEMENT', $s->id, $from, 'DISPUTED', 'DISPUTED', $actor);
            $this->audit->record('commission.statement.disputed', 'partner_statement', $s->id, ['case_id' => $case->id], $reason);
            $this->outbox->record('commission.statement.disputed', 'partner_statement', $s->id, ['statement_id' => $s->id, 'case_id' => $case->id]);

            return $s->refresh();
        });
    }

    public function resolveDispute(PartnerStatement $s, string $resolution, User $actor): PartnerStatement
    {
        return DB::transaction(function () use ($s, $resolution, $actor) {
            $s = PartnerStatement::lockForUpdate()->findOrFail($s->id);
            if ($s->status !== 'DISPUTED') {
                $this->fail('statement', 'STATEMENT_NOT_DISPUTED', 'Only a DISPUTED statement can be resolved.');
            }
            if (PartnerStatementItem::where('partner_statement_id', $s->id)->where('adjustment_status', 'PROPOSED')->exists()) {
                $this->fail('statement', 'ADJUSTMENTS_PENDING', 'Decide the pending adjustments before resolving the dispute.');
            }
            $s->update(['status' => 'DRAFT', 'revision' => (int) $s->revision + 1, 'approved_by' => null, 'approved_at' => null, 'published_at' => null]);
            $this->closeCase($s->dispute_case_id, $actor, $resolution);
            $this->events->write($s->tenant_id, 'PARTNER_STATEMENT', $s->id, 'DISPUTED', 'DRAFT', 'DISPUTE_RESOLVED', $actor);
            $this->audit->record('commission.statement.dispute_resolved', 'partner_statement', $s->id, ['case_id' => $s->dispute_case_id, 'revision' => $s->revision], $resolution);
            $this->outbox->record('commission.statement.dispute_resolved', 'partner_statement', $s->id, ['statement_id' => $s->id, 'case_id' => $s->dispute_case_id, 'revision' => (int) $s->revision]);

            return $s->refresh();
        });
    }

    private function decide(PartnerStatementItem $item, User $checker, string $to, ?string $reason = null): PartnerStatementItem
    {
        return DB::transaction(function () use ($item, $checker, $to, $reason) {
            $s = PartnerStatement::lockForUpdate()->findOrFail($item->partner_statement_id);
            $item = PartnerStatementItem::lockForUpdate()->findOrFail($item->id);
            if ($item->entry_type !== 'ADJUSTMENT' || $item->adjustment_status !== 'PROPOSED') {
                $this->fail('adjustment', 'ADJUSTMENT_NOT_PROPOSED', 'Only a PROPOSED adjustment can be decided.');
            }
            if ($item->proposed_by === $checker->id) {
                $this->fail('adjustment', 'MAKER_CHECKER', 'The maker of an adjustment cannot decide it.');
            }
            if (! in_array($s->status, self::ADJUSTABLE, true)) {
                $this->fail('statement', 'STATEMENT_NOT_ADJUSTABLE', "Adjustments are decided on DRAFT or DISPUTED statements, not {$s->status}.");
            }
            if ($to === 'APPROVED') {
                $closing = (int) $s->closing_balance_minor + (int) $item->amount_minor;
                if ($closing < 0) {
                    $this->fail('amount_minor', 'ADJUSTMENT_NEGATIVE_BALANCE', 'The adjustment would make the statement balance negative.');
                }
                $s->update(['adjustments_minor' => (int) $s->adjustments_minor + (int) $item->amount_minor, 'closing_balance_minor' => $closing]);
            }
            $item->update(['adjustment_status' => $to, 'decided_by' => $checker->id, 'decided_at' => now()] + ($reason !== null ? ['metadata' => ['rejection_reason' => $reason]] : []));
            $event = 'commission.statement.adjustment_'.strtolower($to);
            $this->audit->record($event, 'partner_statement', $s->id, ['item_id' => $item->id, 'amount_minor' => (int) $item->amount_minor], $reason);
            $this->outbox->record($to === 'APPROVED' ? 'commission.statement.adjustment_approved' : 'commission.statement.adjustment_rejected', 'partner_statement', $s->id,
                ['statement_id' => $s->id, 'item_id' => $item->id, 'amount_minor' => (int) $item->amount_minor, 'closing_balance_minor' => (int) $s->closing_balance_minor]);

            return $item->refresh();
        });
    }

    private function closeCase(?string $caseId, User $actor, string $outcome): void
    {
        $case = $caseId ? WorkCase::withoutGlobalScopes()->find($caseId) : null;
        if (! $case || $case->closed_at !== null) {
            return;
        }
        if ($case->status === 'OPEN') {
            $case = $this->cases->transition($case, 'start', $actor);
        }
        if ($case->status === 'IN_PROGRESS') {
            $case = $this->cases->transition($case, 'resolve', $actor, $outcome);
        }
        if ($case->status === 'RESOLVED') {
            $this->cases->transition($case, 'close', $actor, null, ['outcome' => $outcome]);
        }
    }

    private function existing(string $tenantId, string $partnerId, string $start, string $end, string $currency): ?PartnerStatement
    {
        return PartnerStatement::where(['tenant_id' => $tenantId, 'partner_id' => $partnerId, 'currency' => $currency])
            ->whereDate('period_start', $start)->whereDate('period_end', $end)->first();
    }

    private function fail(string $field, string $code, string $message): never
    {
        throw ValidationException::withMessages([$field => "{$code}: {$message}"]);
    }
}

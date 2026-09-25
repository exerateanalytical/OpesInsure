<?php

declare(strict_types=1);

namespace App\Application\Commissions\Statements;

use App\Application\Events\OutboxWriter;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\Ledger\FinancialPostingService;
use App\Application\Ledger\LedgerService;
use App\Models\PartnerPayoutRequest;
use App\Models\PartnerStatement;

/**
 * REQ-COM-003 — links the Wave 6 partner statement / payout lifecycle to the money chain:
 *   openPayable      — on statement approval: one PAYABLE / COMMISSION obligation (Batch 9-1) for the closing balance,
 *                      source (partner_statement, id, 'r'.revision) so a re-approved revision gets its own obligation.
 *   settlePayout     — on payout PAID: ObligationService::settle + FinancialPostingService::post('commission.paid').
 *   reversePayout    — on payout REVERSED: ObligationService::unsettle + LedgerService::reverse of that journal.
 * Payouts whose statement has no obligation (approved before Batch 10-3) are left untouched.
 */
final class CommissionPayableLink
{
    public const PAID_EVENT = 'commission.paid';

    public function __construct(
        private readonly ObligationService $obligations,
        private readonly FinancialPostingService $posting,
        private readonly LedgerService $ledger,
        private readonly OutboxWriter $outbox,
    ) {}

    public function openPayable(PartnerStatement $s, ?string $actorId): ?string
    {
        if ((int) $s->closing_balance_minor <= 0) {
            return null;
        }
        $o = $this->obligations->create([
            'tenant_id' => $s->tenant_id, 'kind' => 'PAYABLE', 'type' => 'COMMISSION',
            'creditor_type' => 'partner', 'creditor_id' => $s->partner_id,
            'source_type' => 'partner_statement', 'source_id' => $s->id, 'source_reference' => 'r'.(int) $s->revision,
            'currency' => $s->currency, 'amount_minor' => (int) $s->closing_balance_minor, 'due_at' => now(),
            'description' => "Commission statement {$s->statement_number}",
        ], $actorId);
        $s->forceFill(['obligation_id' => $o->id])->save();
        $this->outbox->record('commission.payable.opened', 'partner_statement', $s->id, [
            'statement_id' => $s->id, 'obligation_id' => $o->id, 'amount_minor' => (int) $o->amount_minor, 'currency' => $o->currency,
        ]);

        return $o->id;
    }

    public function settlePayout(PartnerPayoutRequest $p, ?string $actorId): void
    {
        $s = $p->partner_statement_id ? PartnerStatement::find($p->partner_statement_id) : null;
        if (! $s?->obligation_id) {
            return;
        }
        $this->obligations->settle($s->obligation_id, (int) $p->amount_minor, 'payout:'.$p->id, $actorId);
        $journal = $this->posting->post($p->tenant_id, self::PAID_EVENT, $p->id, (int) $p->amount_minor, $p->currency, 'payout:'.$p->id);
        $p->forceFill(['journal_id' => $journal])->save();
    }

    public function reversePayout(PartnerPayoutRequest $p, ?string $actorId): void
    {
        $s = $p->partner_statement_id ? PartnerStatement::find($p->partner_statement_id) : null;
        if (! $s?->obligation_id) {
            return;
        }
        $this->obligations->unsettle($s->obligation_id, (int) $p->amount_minor, 'payout-reversal:'.$p->id, $actorId);
        if ($p->journal_id) {
            $this->ledger->reverse($p->journal_id, 'payout-reversal:'.$p->id);
        }
    }
}

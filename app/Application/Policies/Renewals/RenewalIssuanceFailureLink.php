<?php

declare(strict_types=1);

namespace App\Application\Policies\Renewals;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Policies\IssuanceQueue\IssuanceException;
use App\Models\RenewalCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * REQ-REN-001 / WF-087 — paid renewal whose issuance failed.
 *
 * The failure itself lands in the Batch 7D issuance_exceptions queue (IssuanceQueueService, one queue for every paid
 * proposal). This link runs on each queue event: when the proposal's quote is a renewal quote, the exception is
 * tagged with the renewal case and the case moves QUOTED → ISSUANCE_FAILED (the expiring policy is at risk of a
 * cover gap). When the queue row is resolved the case goes back to QUOTED (issuance requested / done) or, when
 * the premium is refunded, to DECLINED.
 */
final class RenewalIssuanceFailureLink
{
    public function __construct(private RenewalMachine $machine, private AuditWriter $audit, private OutboxWriter $outbox) {}

    public function sync(IssuanceException $ex, string $action, ?User $actor = null): void
    {
        $quoteId = DB::table('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')
            ->where('proposals.id', $ex->proposal_id)->value('quote_offers.quote_id');
        $case = $quoteId ? RenewalCase::where('renewal_quote_id', $quoteId)->lockForUpdate()->first() : null;
        if (! $case) {
            return;
        }
        if ($ex->renewal_case_id !== $case->id) {
            $ex->forceFill(['renewal_case_id' => $case->id])->save();
        }

        if (in_array($action, ['RECORDED', 'FAILED_AGAIN'], true) && $case->status === 'QUOTED') {
            $expires = $case->policy?->coverage_ends_at;
            $meta = ['issuance_exception_id' => $ex->id, 'kind' => $ex->kind, 'reason_code' => $ex->reason_code,
                'days_to_expiry' => $expires ? (int) now()->startOfDay()->diffInDays($expires->copy()->startOfDay(), false) : null];
            $this->machine->move($case, 'ISSUANCE_FAILED', 'ISSUANCE_FAILED', $actor, $meta, ['issuance_exception_id' => $ex->id]);
            $this->audit->record('renewal.issuance_failed', 'renewal_case', $case->id, $meta);
            $this->outbox->record('renewal.issuance_failed', 'renewal_case', $case->id, ['renewal_case_id' => $case->id, 'policy_id' => $case->policy_id] + $meta);

            return;
        }

        if ($action === 'RESOLVED' && $case->status === 'ISSUANCE_FAILED' && $case->issuance_exception_id === $ex->id) {
            $refund = $ex->resolution === 'REFUND_REQUESTED';
            $meta = ['issuance_exception_id' => $ex->id, 'resolution' => $ex->resolution];
            $this->machine->move($case, $refund ? 'DECLINED' : 'QUOTED', 'ISSUANCE_RECOVERED', $actor, $meta, $refund ? ['closed_reason' => 'PREMIUM_REFUNDED'] : []);
            $this->audit->record('renewal.issuance_recovered', 'renewal_case', $case->id, $meta);
            $this->outbox->record('renewal.issuance_recovered', 'renewal_case', $case->id, ['renewal_case_id' => $case->id, 'status' => $case->status] + $meta);
        }
    }
}

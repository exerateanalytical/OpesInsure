<?php

declare(strict_types=1);

namespace App\Application\Policies\IssuanceQueue;

use App\Application\Audit\AuditWriter;
use App\Application\Notifications\CustomerNotifier;
use App\Application\Policies\PaymentIssuanceTrigger;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-POL-004 (WF-028/029/084) — the failed / paid-not-issued issuance queue.
 *
 * A reconciled payment whose automatic issuance request could not be opened is RECORDED here (never swallowed):
 *   ISSUANCE_REQUEST_FAILED — PolicyIssuanceService refused or errored (message kept),
 *   ISSUANCE_BLOCKED        — a pre-issuance control stopped it (documents not accepted — owner decision 31;
 *                             premium-to-cover rule says NO_COVER / COVER_SUSPENDED — decision 17; no territory …),
 *   PAID_NOT_ISSUED         — found by scan(): paid, reconciled, still no policy past the SLA.
 * One open row per proposal (partial unique index); a repeat failure bumps attempts. Ops retry / escalate / resolve.
 * The customer is told once that the payment is safe and issuance is delayed.
 */
final class IssuanceQueueService
{
    public const KINDS = ['ISSUANCE_REQUEST_FAILED', 'ISSUANCE_BLOCKED', 'PAID_NOT_ISSUED'];

    public const RESOLUTIONS = ['REFUND_REQUESTED', 'RESOLVED_MANUALLY'];

    public function __construct(private readonly AuditWriter $audit, private readonly CustomerNotifier $notifier) {}

    /** @param list<string> $blockers */
    public function record(PaymentIntentRecord $payment, Proposal $proposal, string $kind, string $reason, array $blockers = [], ?string $error = null,
        ?string $territory = null, ?array $premiumCover = null, ?string $requestId = null): IssuanceException
    {
        $ex = DB::transaction(function () use ($payment, $proposal, $kind, $reason, $blockers, $error, $territory, $premiumCover, $requestId): IssuanceException {
            $ex = IssuanceException::where('proposal_id', $proposal->id)->where('status', '<>', 'RESOLVED')->lockForUpdate()->first();
            $fields = ['kind' => $kind, 'reason_code' => mb_substr($reason, 0, 64), 'blockers' => array_values($blockers), 'error_message' => $error ? mb_substr($error, 0, 2000) : null,
                'territory' => $territory, 'premium_cover' => $premiumCover, 'policy_issuance_request_id' => $requestId, 'payment_intent_id' => $payment->id, 'last_attempt_at' => now()];
            if ($ex) {
                $ex->update($fields + ['attempts' => $ex->attempts + 1]);
                $this->event($ex, 'FAILED_AGAIN', $ex->status, $ex->status, null, ['reason_code' => $reason]);

                return $ex;
            }
            $ex = IssuanceException::create($fields + ['tenant_id' => $proposal->tenant_id, 'proposal_id' => $proposal->id, 'status' => 'OPEN', 'attempts' => 1]);
            $this->event($ex, 'RECORDED', null, 'OPEN', null, ['kind' => $kind, 'reason_code' => $reason]);
            $this->audit->record('policy.issuance.exception_recorded', 'issuance_exception', $ex->id, ['proposal_id' => $proposal->id, 'kind' => $kind, 'reason_code' => $reason]);

            return $ex;
        });

        if ($ex->customer_notified_at === null) {
            $product = $proposal->offer?->product?->name ?? 'your cover';
            $this->notifier->toParty($proposal->party_id, $proposal->tenant_id, 'PAYMENT', 'Payment received — policy issuance delayed',
                "We received your payment for {$product} and it is safe. Issuing your policy is taking longer than expected; our team is handling it and will keep you informed.",
                'WARNING', "/payments/{$payment->id}");
            $ex->update(['customer_notified_at' => now()]);
        }

        return $ex->refresh();
    }

    /** Closes the open row for a proposal once the automatic path succeeded (retry, replay or catch-up). */
    public function closeFor(string $proposalId, string $resolution, ?string $requestId, ?User $actor): void
    {
        $ex = IssuanceException::where('proposal_id', $proposalId)->where('status', '<>', 'RESOLVED')->first();
        if (! $ex) {
            return;
        }
        $from = $ex->status;
        $ex->update(['status' => 'RESOLVED', 'resolution' => $resolution, 'policy_issuance_request_id' => $requestId ?? $ex->policy_issuance_request_id,
            'resolved_by' => $actor?->id, 'resolved_at' => now()]);
        $this->event($ex, 'RESOLVED', $from, 'RESOLVED', $actor, ['resolution' => $resolution]);
        $this->audit->record('policy.issuance.exception_resolved', 'issuance_exception', $ex->id, ['resolution' => $resolution]);
    }

    public function retry(IssuanceException $ex, User $actor): IssuanceException
    {
        $this->assertOpen($ex);
        $this->event($ex, 'RETRY', $ex->status, $ex->status, $actor);
        $this->audit->record('policy.issuance.exception_retried', 'issuance_exception', $ex->id, ['attempts' => $ex->attempts]);
        app(PaymentIssuanceTrigger::class)->attempt(PaymentIntentRecord::findOrFail($ex->payment_intent_id), $actor);

        return $ex->refresh();
    }

    public function escalate(IssuanceException $ex, User $actor, string $reason, ?string $toUserId = null): IssuanceException
    {
        $this->assertOpen($ex);
        $from = $ex->status;
        $ex->update(['status' => 'ESCALATED', 'escalated_to' => $toUserId, 'escalated_at' => now()]);
        $this->event($ex, 'ESCALATED', $from, 'ESCALATED', $actor, ['reason' => $reason, 'escalated_to' => $toUserId]);
        $this->audit->record('policy.issuance.exception_escalated', 'issuance_exception', $ex->id, ['escalated_to' => $toUserId], $reason);

        return $ex->refresh();
    }

    public function resolve(IssuanceException $ex, User $actor, string $resolution, string $notes): IssuanceException
    {
        $this->assertOpen($ex);
        if ($resolution === 'RESOLVED_MANUALLY' && ! Policy::where('proposal_id', $ex->proposal_id)->exists()
            && ! PolicyIssuanceRequest::where('proposal_id', $ex->proposal_id)->whereNotIn('status', ['REJECTED'])->exists()) {
            throw ValidationException::withMessages(['resolution' => 'A paid proposal can be closed manually only once an issuance request or policy exists; otherwise request a refund.']);
        }
        $from = $ex->status;
        $ex->update(['status' => 'RESOLVED', 'resolution' => $resolution, 'resolution_notes' => $notes, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
        $this->event($ex, 'RESOLVED', $from, 'RESOLVED', $actor, ['resolution' => $resolution]);
        $this->audit->record('policy.issuance.exception_resolved', 'issuance_exception', $ex->id, ['resolution' => $resolution], $notes);

        return $ex->refresh();
    }

    /**
     * Paid-not-issued sweep for a tenant: SUCCEEDED + reconciled payments older than $graceMinutes whose proposal has
     * no policy and either no issuance request, or a request still in carrier review after $reviewHours.
     *
     * @return list<IssuanceException>
     */
    public function scan(string $tenantId, int $graceMinutes = 30, int $reviewHours = 48): array
    {
        $found = [];
        $payments = PaymentIntentRecord::where('tenant_id', $tenantId)->where('status', 'SUCCEEDED')->whereNotNull('reconciled_at')->whereNotNull('proposal_id')
            ->where('reconciled_at', '<=', now()->subMinutes($graceMinutes))
            ->whereNotExists(fn ($q) => $q->from('policies')->whereColumn('policies.proposal_id', 'payment_intents.proposal_id'))
            ->whereNotExists(fn ($q) => $q->from('issuance_exceptions')->whereColumn('issuance_exceptions.proposal_id', 'payment_intents.proposal_id')->where('issuance_exceptions.status', '<>', 'RESOLVED'))
            ->orderBy('reconciled_at')->limit(500)->get();

        foreach ($payments as $payment) {
            $proposal = Proposal::with('offer.product')->find($payment->proposal_id);
            if (! $proposal || in_array($proposal->status, ['CANCELLED', 'WITHDRAWN', 'DECLINED'], true)) {
                continue;
            }
            $request = PolicyIssuanceRequest::where('proposal_id', $proposal->id)->first();
            if ($request && ! (in_array($request->status, ['CARRIER_REVIEW', 'REQUESTED'], true) && $request->created_at->lte(now()->subHours($reviewHours)))
                && $request->status !== 'REJECTED') {
                continue;
            }
            $reason = match (true) {
                $request === null => 'NO_ISSUANCE_REQUEST',
                $request->status === 'REJECTED' => 'ISSUANCE_REJECTED',
                default => 'CARRIER_REVIEW_OVERDUE',
            };
            $found[] = $this->record($payment, $proposal, 'PAID_NOT_ISSUED', $reason, [], $request?->rejection_reason, null, null, $request?->id);
        }

        return $found;
    }

    private function assertOpen(IssuanceException $ex): void
    {
        if ($ex->status === 'RESOLVED') {
            throw ValidationException::withMessages(['status' => 'This issuance exception is already resolved.']);
        }
    }

    private function event(IssuanceException $ex, string $action, ?string $from, string $to, ?User $actor, array $meta = []): void
    {
        DB::table('issuance_exception_events')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'issuance_exception_id' => $ex->id, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'actor_id' => $actor?->id, 'metadata' => json_encode($meta), 'occurred_at' => now()]);
    }
}

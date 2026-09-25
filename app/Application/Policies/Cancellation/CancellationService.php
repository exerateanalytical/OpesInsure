<?php

declare(strict_types=1);

namespace App\Application\Policies\Cancellation;

use App\Application\Audit\AuditWriter;
use App\Application\Authority\AuthorityOutcome;
use App\Application\Authority\AuthorityService;
use App\Application\Notifications\CustomerNotifier;
use App\Application\Policies\CancellationCalculator;
use App\Application\Policies\Chronology\PolicyChronologyWriter;
use App\Application\Policies\PolicyServicingService;
use App\Application\Events\OutboxWriter;
use App\Models\Policy;
use App\Models\PolicyTransaction;
use App\Models\Refund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CAN-001 (WF-044/045) — the cancellation machine:
 *
 *   REQUESTED ──review──▶ UNDER_REVIEW ──approve──▶ APPROVED
 *        └──────────reject────────┴──────────────▶ REJECTED
 *
 * The CANCELLATION policy_transaction (PolicyServicingService) stays the single owner of the refund figure,
 * the CANCELLATION_PENDING / CANCELLED policy status, the refund obligation (FinancialCaseService::requestRefund)
 * and the cancellation document pack. This service adds: initiator + notice (insurer-initiated cancellations
 * need a notice period before the effective date), an explicit review step, the CANCEL authority check, the
 * CANCELLATION policy version (chronology) and revocation of every current issued document of the policy.
 * Maker-checker: the requester can neither review nor decide.
 */
final class CancellationService
{
    public const INITIATORS = ['INSURED', 'INSURER', 'INTERMEDIARY'];

    public const DEFAULT_INSURER_NOTICE_DAYS = 10;

    public function __construct(
        private readonly PolicyServicingService $servicing,
        private readonly CancellationCalculator $calculator,
        private readonly PolicyChronologyWriter $chronology,
        private readonly CancellationDocumentRevoker $revoker,
        private readonly AuthorityService $authority,
        private readonly CustomerNotifier $notifier,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** Refund preview without side effects. */
    public function quote(Policy $policy, string $effectiveAt, string $initiatedBy): array
    {
        return $this->calculator->calculate($policy, CarbonImmutable::parse($effectiveAt), $initiatedBy);
    }

    /** @param array{effective_at: string, reason_code: string, initiated_by: string, notes?: ?string} $data */
    public function request(Policy $policy, array $data, User $actor): PolicyCancellation
    {
        $initiator = $data['initiated_by'] ?? null;
        if (! in_array($initiator, self::INITIATORS, true)) {
            throw ValidationException::withMessages(['initiated_by' => 'initiated_by must be one of '.implode(', ', self::INITIATORS).'.']);
        }
        $effective = CarbonImmutable::parse($data['effective_at']);
        if ($effective->lessThan(now()->startOfDay())) {
            // Backdated cancellation needs BACKDATE authority — not granted through this path.
            throw ValidationException::withMessages(['effective_at' => 'A cancellation cannot be backdated.']);
        }
        $noticeDays = $initiator === 'INSURER' ? $this->insurerNoticeDays() : 0;
        if ($noticeDays > 0 && $effective->lessThan(now()->addDays($noticeDays)->startOfDay())) {
            throw ValidationException::withMessages(['effective_at' => "An insurer-initiated cancellation needs {$noticeDays} days' notice."]);
        }

        return DB::transaction(function () use ($policy, $data, $actor, $initiator, $effective, $noticeDays): PolicyCancellation {
            $transaction = $this->servicing->request($policy, [
                'type' => 'CANCELLATION', 'effective_at' => $data['effective_at'], 'reason_code' => $data['reason_code'],
                'notes' => $data['notes'] ?? null, 'initiated_by' => $initiator, 'requested_changes' => [],
            ], $actor);
            $calc = $this->calculator->calculate($policy->refresh(), $effective, $initiator);

            $case = PolicyCancellation::create([
                'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id, 'policy_transaction_id' => $transaction->id,
                'status' => 'REQUESTED', 'initiated_by' => $initiator, 'reason_code' => $data['reason_code'],
                'effective_at' => $effective, 'refund_basis' => $calc['basis'], 'refund_minor' => $transaction->refund_minor,
                'currency' => $policy->currency, 'notice_days' => $noticeDays, 'notice_served_at' => now(),
                'requested_by' => $actor->id,
            ]);

            // The notice (or acknowledgement for an insured request) goes to the policyholder now.
            $this->notifier->toParty($policy->party_id, $policy->tenant_id, 'POLICY',
                $initiator === 'INSURER' ? 'Notice of cancellation' : 'Cancellation request received',
                'Policy '.$policy->policy_number.' is due to be cancelled with effect from '.$effective->toDateString()
                .'. Estimated refund: '.$transaction->refund_minor.' '.$policy->currency.' (minor units).',
                $initiator === 'INSURER' ? 'WARNING' : 'INFO', null, $initiator === 'INSURER');

            $this->audit->record('policy.cancellation.requested', 'policy_cancellation', $case->id, [
                'policy_id' => $policy->id, 'initiated_by' => $initiator, 'refund_minor' => $case->refund_minor, 'notice_days' => $noticeDays,
            ], $case->reason_code);
            $this->outbox->record('policy.cancellation.requested', 'policy', $policy->id, [
                'policy_id' => $policy->id, 'cancellation_id' => $case->id, 'initiated_by' => $initiator,
                'effective_at' => $effective->toIso8601String(), 'refund_minor' => $case->refund_minor,
            ]);

            return $case;
        });
    }

    public function review(PolicyCancellation $case, User $actor, ?string $note = null): PolicyCancellation
    {
        return DB::transaction(function () use ($case, $actor, $note): PolicyCancellation {
            $case = PolicyCancellation::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($case, ['REQUESTED']);
            $this->assertChecker($case, $actor);
            $case->update(['status' => 'UNDER_REVIEW', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->audit->record('policy.cancellation.reviewed', 'policy_cancellation', $case->id, ['policy_id' => $case->policy_id], $note);
            $this->outbox->record('policy.cancellation.reviewed', 'policy', $case->policy_id, ['policy_id' => $case->policy_id, 'cancellation_id' => $case->id]);

            return $case->refresh();
        });
    }

    public function approve(PolicyCancellation $case, User $actor, ?string $note = null): PolicyCancellation
    {
        $case = $case->refresh();
        $this->assertStatus($case, ['UNDER_REVIEW']);
        $this->assertChecker($case, $actor);

        // CANCEL authority (owner decision 12): a DENIED decision is recorded outside the rolled-back transaction.
        $authority = $this->authorityCheck($case, $actor);
        if ($authority->outcome === AuthorityOutcome::DENIED) {
            $this->authority->record($authority);
            throw ValidationException::withMessages(['authority' => 'Refund exceeds your CANCEL authority limit ('.$authority->maxAmountMinor.').']);
        }

        return DB::transaction(function () use ($case, $actor, $note, $authority): PolicyCancellation {
            $case = PolicyCancellation::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($case, ['UNDER_REVIEW']);
            $this->authority->record($authority);

            // Documents current before approval are revoked; the cancellation pack generated by approve() stays valid.
            $documentIds = $this->revoker->currentDocumentIds($case->policy_id);
            $transaction = PolicyTransaction::findOrFail($case->policy_transaction_id);
            $policy = $this->servicing->approve($transaction, $actor);

            $versionId = $this->chronology->record($policy, 'CANCELLATION', $case->effective_at, [
                'source_type' => 'policy_transaction', 'source_id' => $transaction->id, 'actor_id' => $actor->id,
            ]);
            $revoked = $this->revoker->revoke($policy, $documentIds, $case->requested_by, $actor, 'Policy cancelled ('.$case->reason_code.')');
            $refundId = Refund::where('tenant_id', $policy->tenant_id)->where('idempotency_key', 'policy-cancellation-'.$transaction->id)->value('id');

            $case->update([
                'status' => 'APPROVED', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note,
                'authority_check_id' => $authority->checkId, 'policy_version_id' => $versionId,
                'refund_id' => $refundId, 'documents_revoked' => $revoked,
            ]);

            $this->notifier->toParty($policy->party_id, $policy->tenant_id, 'POLICY', 'Policy cancelled',
                'Policy '.$policy->policy_number.' is cancelled with effect from '.$case->effective_at->toDateString()
                .($case->refund_minor > 0 ? '. A refund of '.$case->refund_minor.' '.$case->currency.' (minor units) has been requested.' : '.'),
                'WARNING', null, true);
            $this->audit->record('policy.cancelled', 'policy_cancellation', $case->id, [
                'policy_id' => $policy->id, 'refund_id' => $refundId, 'documents_revoked' => $revoked, 'policy_version_id' => $versionId,
            ], $case->reason_code);
            $this->outbox->record('policy.cancelled', 'policy', $policy->id, [
                'policy_id' => $policy->id, 'cancellation_id' => $case->id, 'effective_at' => $case->effective_at->toIso8601String(),
                'refund_minor' => $case->refund_minor, 'refund_id' => $refundId, 'documents_revoked' => $revoked,
            ]);

            return $case->refresh();
        });
    }

    public function reject(PolicyCancellation $case, User $actor, string $reason): PolicyCancellation
    {
        return DB::transaction(function () use ($case, $actor, $reason): PolicyCancellation {
            $case = PolicyCancellation::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($case, ['REQUESTED', 'UNDER_REVIEW']);
            $this->assertChecker($case, $actor);
            $this->servicing->reject(PolicyTransaction::findOrFail($case->policy_transaction_id), $reason, $actor);
            $case->update(['status' => 'REJECTED', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $reason]);
            $this->audit->record('policy.cancellation.rejected', 'policy_cancellation', $case->id, ['policy_id' => $case->policy_id], $reason);
            $this->outbox->record('policy.cancellation.rejected', 'policy', $case->policy_id, ['policy_id' => $case->policy_id, 'cancellation_id' => $case->id]);

            return $case->refresh();
        });
    }

    private function authorityCheck(PolicyCancellation $case, User $actor): AuthorityOutcome
    {
        $policy = Policy::findOrFail($case->policy_id);
        $line = $policy->proposal?->offer?->quote?->line_code;
        $day = now()->toDateString();
        $limit = $this->authority->effectiveLimit('USER', $actor->id, $policy->carrier_id, 'CANCEL', $line, $day);
        [$outcome, $reason] = match (true) {
            $limit === null => [AuthorityOutcome::ALLOWED, 'NO_CANCEL_LIMIT_CONFIGURED'],
            $case->refund_minor > (int) $limit->max_amount_minor => [AuthorityOutcome::DENIED, 'CANCEL_AUTHORITY_EXCEEDED'],
            default => [AuthorityOutcome::ALLOWED, 'WITHIN_LIMIT'],
        };
        $checkId = (string) Str::uuid();
        $row = [
            'id' => $checkId, 'tenant_id' => $policy->tenant_id, 'carrier_id' => $policy->carrier_id,
            'holder_type' => 'USER', 'holder_id' => $actor->id, 'authority_type' => 'CANCEL', 'action' => 'CANCEL',
            'subject_type' => 'policy_cancellation', 'subject_id' => $case->id, 'line_code' => $line,
            'amount_minor' => $case->refund_minor, 'currency' => $case->currency, 'outcome' => $outcome, 'reason' => $reason,
            'source' => AuthorityService::LIMIT_SOURCE, 'authority_limit_id' => $limit?->id,
            'delegated_authority_agreement_id' => null, 'intermediary_authorization_id' => null,
            'referral_case_id' => null, 'checked_by' => $actor->id, 'created_at' => now(),
        ];

        return new AuthorityOutcome($outcome, $reason, AuthorityService::LIMIT_SOURCE, $limit?->id,
            $limit ? (int) $limit->max_amount_minor : null, null, null, $checkId, $row);
    }

    private function insurerNoticeDays(): int
    {
        return (int) config('policies.cancellation.insurer_notice_days', self::DEFAULT_INSURER_NOTICE_DAYS);
    }

    private function assertStatus(PolicyCancellation $case, array $allowed): void
    {
        if (! in_array($case->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "Cancellation is {$case->status}; expected ".implode('/', $allowed).'.']);
        }
    }

    private function assertChecker(PolicyCancellation $case, User $actor): void
    {
        if ($case->requested_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => __('wave5.maker_checker')]);
        }
    }
}

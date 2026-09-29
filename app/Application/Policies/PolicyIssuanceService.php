<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Shared\CanonicalJson;
use App\Application\Authority\AuthorityDenied;
use App\Application\Authority\AuthorityOutcome;
use App\Application\Authority\AuthorityService;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\Proposal;
use App\Models\RenewalCase;
use App\Application\Notifications\CustomerNotifier;
use App\Application\Notifications\NotificationCatalog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PolicyIssuanceService
{
    public function __construct(
        private CanonicalJson $json,
        private AuthorityService $authority, // wraps the legacy AuthorityChecker (REQ-DUP-023)
        private AuditWriter $audit,
        private OutboxWriter $outbox,
        private PolicyDocumentService $documents,
        private CustomerNotifier $notifier,
    ) {}

    public function request(Tenant $tenant, Proposal $proposal, PaymentIntentRecord $payment, array $data, User $actor): PolicyIssuanceRequest
    {
        try {
            return $this->requestInTransaction($tenant, $proposal, $payment, $data, $actor);
        } catch (AuthorityDenied $denied) {
            // Blocked attempts stay in the authority registry after the rollback.
            $this->authority->record($denied->outcome);

            throw $denied->error;
        }
    }

    private function requestInTransaction(Tenant $tenant, Proposal $proposal, PaymentIntentRecord $payment, array $data, User $actor): PolicyIssuanceRequest
    {
        return DB::transaction(function () use ($tenant, $proposal, $payment, $data, $actor): PolicyIssuanceRequest {
            $proposal->load('offer.quote');
            $terms = $proposal->terms_snapshot;
            $coverageStarts = new \DateTimeImmutable($data['coverage_starts_at']);
            $coverageEnds = new \DateTimeImmutable($data['coverage_ends_at']);
            if ($coverageEnds <= $coverageStarts) {
                throw ValidationException::withMessages(['coverage_ends_at' => __('wave5.coverage_period_invalid')]);
            }

            $invalidPayment = $proposal->tenant_id !== $tenant->id
                || $payment->tenant_id !== $tenant->id
                || $payment->proposal_id !== $proposal->id
                || $proposal->status !== 'PAYMENT_PENDING'
                || $payment->status !== 'SUCCEEDED'
                || ! $payment->reconciled_at
                // REQ-PAY-006: an instalment plan binds on its first scheduled instalment (same rule as PolicyIssuabilityService).
                || ! in_array($payment->amount_minor, array_filter([(int) $terms['total_minor'], count($proposal->cover_terms['schedule'] ?? []) > 1 ? (int) $proposal->cover_terms['schedule'][0]['amount_minor'] : null]), true)
                || $payment->currency !== $terms['currency'];

            if ($invalidPayment) {
                throw ValidationException::withMessages([
                    'payment_intent_id' => __('wave5.reconciled_payment_required'),
                ]);
            }

            if (Policy::where('proposal_id', $proposal->id)->exists()
                || PolicyIssuanceRequest::where('proposal_id', $proposal->id)->exists()) {
                throw ValidationException::withMessages(['proposal_id' => __('wave5.issuance_exists')]);
            }

            // REQ-KYC-001 gate on bind (tenant mode OFF | WARN | ENFORCE — App\Application\Kyc\KycGate).
            app(\App\Application\Kyc\KycGate::class)->assertMayProceed($tenant->id, $proposal->party_id, 'BIND', 'proposal', $proposal->id);
            // REQ-AML-001 screening hold (tenant mode OFF | WARN | ENFORCE — App\Application\Compliance\Aml\Screening\ComplianceGate).
            app(\App\Application\Compliance\Aml\Screening\ComplianceGate::class)->assertMayProceed($tenant->id, [$proposal->party_id], 'BIND', 'proposal', $proposal->id);
            // Vehicle Power master (motor_policy_issuance): the stamp duty needs a verified fiscal power — REVIEW_REQUIRED otherwise.
            app(\App\Application\Vehicles\Power\VehicleStampDutyService::class)->assertIssuable($proposal->offer->rating_run_id, (string) $proposal->offer->quote->line_code,
                (array) $proposal->offer->quote->risk_facts, $tenant->id);

            $authoritySnapshot = ['mode' => 'CARRIER_REVIEW_REQUIRED'];
            $status = 'CARRIER_REVIEW';
            $agreementId = $data['delegated_authority_agreement_id'] ?? null;

            if ($agreementId) {
                $agreement = DB::table('delegated_authority_agreements as authority')
                    ->join('partners', 'partners.id', '=', 'authority.partner_id')
                    ->where('authority.id', $agreementId)
                    ->where('authority.carrier_id', $proposal->offer->carrier_id)
                    ->where('partners.tenant_id', $tenant->id)
                    ->select('authority.*')
                    ->first();

                if (! $agreement) {
                    throw ValidationException::withMessages([
                        'delegated_authority_agreement_id' => __('wave5.authority_scope_invalid'),
                    ]);
                }

                // REQ-AUTH-001..003: AuthorityService reads authority_limits (legacy AuthorityChecker fallback) and the
                // intermediary authorization; a threshold-exceed opens an AUTHORITY_REFERRAL case (carrier review).
                $outcome = $this->authority->checkDelegatedIssuance(
                    $tenant->id,
                    $agreement,
                    $proposal->offer->quote->line_code,
                    (int) $terms['total_minor'],
                    $data['territory'],
                    $coverageStarts,
                    ['type' => 'proposal', 'id' => $proposal->id, 'title' => 'Delegated issuance '.$agreement->agreement_number],
                    $actor,
                );

                if ($outcome->denied()) {
                    throw new AuthorityDenied($outcome, ValidationException::withMessages([
                        'delegated_authority_agreement_id' => __('wave5.authority_denied', ['reason' => $outcome->reason]),
                    ]));
                }

                $authoritySnapshot = [
                    'mode' => $outcome->referred() ? 'AUTHORITY_REFERRAL' : 'DELEGATED_AUTHORITY',
                    'agreement_number' => $agreement->agreement_number,
                    ...$outcome->snapshot(),
                ];
                $status = $outcome->referred() ? 'CARRIER_REVIEW' : 'REQUESTED';
            }

            $request = PolicyIssuanceRequest::create([
                'tenant_id' => $tenant->id,
                'proposal_id' => $proposal->id,
                'payment_intent_id' => $payment->id,
                'carrier_id' => $proposal->offer->carrier_id,
                'delegated_authority_agreement_id' => $agreementId,
                'status' => $status,
                'authority_snapshot' => $authoritySnapshot,
                'terms_hash' => $this->json->hash($terms),
                'coverage_starts_at' => $data['coverage_starts_at'],
                'coverage_ends_at' => $data['coverage_ends_at'],
                'requested_by' => $actor->id,
            ]);

            $this->event($request, null, $status, 'ISSUANCE_REQUESTED', $actor);
            $this->audit->record('policy.issuance.requested', 'policy_issuance_request', $request->id, [
                'authority_mode' => $authoritySnapshot['mode'],
            ]);
            $this->outbox->record('policy.issuance.requested', 'policy_issuance_request', $request->id, [
                'issuance_request_id' => $request->id,
                'carrier_id' => $request->carrier_id,
            ]);

            return $request;
        });
    }

    /**
     * Authorize & issue. Maker-checker: the requester never approves. When POLICY_ISSUE authority limits are configured
     * for the carrier, the approver's limit must cover the premium: no limit at all → 409 AUTHORITY_EXCEEDED (nothing
     * recorded); a limit below the premium → the approval is recorded as the FIRST approval and 409
     * SECOND_APPROVAL_REQUIRED is raised — a different user with enough authority completes it with secondApprove().
     */
    public function approve(PolicyIssuanceRequest $request, array $data, User $actor): Policy
    {
        $gate = DB::transaction(function () use ($request, $data, $actor): string {
            $request = $this->lockPending($request, $actor);
            if ($request->first_approved_by !== null) {
                return 'SECOND_APPROVAL_REQUIRED';
            }
            $outcome = $this->issueAuthority($request, $actor);
            if ($outcome === null || $outcome->reason === 'WITHIN_AUTHORITY_LIMIT') {
                return 'ISSUE';
            }
            if ($outcome->reason === 'NO_AUTHORITY_LIMIT') {
                return 'AUTHORITY_EXCEEDED';
            }
            $request->update(['first_approved_by' => $actor->id, 'first_approved_at' => now(), 'carrier_reference' => $data['carrier_reference'] ?? $request->carrier_reference]);
            $this->event($request, $request->status, $request->status, 'FIRST_APPROVAL', $actor, ['authority_reason' => $outcome->reason]);
            $this->audit->record('policy.issuance.first_approved', 'policy_issuance_request', $request->id, ['authority_reason' => $outcome->reason]);

            return 'SECOND_APPROVAL_REQUIRED';
        });

        if ($gate !== 'ISSUE') {
            throw $this->problem($gate);
        }

        return $this->issue($request, $data, $actor, false);
    }

    /** Completes a request whose first approver lacked the authority. The second approver differs from requester and first approver. */
    public function secondApprove(PolicyIssuanceRequest $request, array $data, User $actor): Policy
    {
        $gate = DB::transaction(function () use ($request, $actor): string {
            $request = $this->lockPending($request, $actor);
            if ($request->first_approved_by === null) {
                throw ValidationException::withMessages(['status' => __('issuance_maker_checker.no_first_approval')]);
            }
            if ($request->first_approved_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => __('issuance_maker_checker.second_approver_same')]);
            }
            $outcome = $this->issueAuthority($request, $actor);

            return $outcome === null || $outcome->reason === 'WITHIN_AUTHORITY_LIMIT' ? 'ISSUE' : 'AUTHORITY_EXCEEDED';
        });
        if ($gate !== 'ISSUE') {
            throw $this->problem($gate);
        }
        $request->refresh();

        return $this->issue($request, ['carrier_reference' => $data['carrier_reference'] ?? $request->carrier_reference ?? 'INS-'.strtoupper(Str::random(10))] + $data, $actor, true);
    }

    /** A checker sends the request back to the requester; any verification or first approval is void. */
    public function requestCorrection(PolicyIssuanceRequest $request, string $reason, User $actor): PolicyIssuanceRequest
    {
        return DB::transaction(function () use ($request, $reason, $actor): PolicyIssuanceRequest {
            $request = $this->lockPending($request, $actor);
            $request->update(['correction_reason' => $reason, 'correction_requested_by' => $actor->id, 'correction_requested_at' => now(),
                'verified_by' => null, 'verified_at' => null, 'first_approved_by' => null, 'first_approved_at' => null]);
            $this->event($request, $request->status, $request->status, 'CORRECTION_REQUESTED', $actor, ['reason' => $reason]);
            $this->audit->record('policy.issuance.correction_requested', 'policy_issuance_request', $request->id, [], $reason);

            return $request->refresh();
        });
    }

    /** A checker (never the requester) confirms the request is complete and correct; this also clears an answered correction. */
    public function verify(PolicyIssuanceRequest $request, User $actor, ?string $notes = null): PolicyIssuanceRequest
    {
        return DB::transaction(function () use ($request, $actor, $notes): PolicyIssuanceRequest {
            $request = $this->lockPending($request, $actor, true);
            if ($request->verified_at !== null && $request->correction_requested_at === null) {
                throw ValidationException::withMessages(['status' => __('issuance_maker_checker.already_verified')]);
            }
            $request->update(['verified_by' => $actor->id, 'verified_at' => now(), 'correction_reason' => null, 'correction_requested_by' => null, 'correction_requested_at' => null]);
            $this->event($request, $request->status, $request->status, 'VERIFIED', $actor, array_filter(['notes' => $notes]));
            $this->audit->record('policy.issuance.verified', 'policy_issuance_request', $request->id, [], $notes);

            return $request->refresh();
        });
    }

    /** Maker-checker stage of a request (queues and detail screens). */
    public function stage(PolicyIssuanceRequest $r): string
    {
        return match (true) {
            $r->status === 'APPROVED' => 'ISSUED',
            $r->status === 'REJECTED' => 'REJECTED',
            $r->correction_requested_at !== null => 'CORRECTION_REQUESTED',
            $r->first_approved_by !== null => 'AWAITING_SECOND_APPROVAL',
            $r->verified_at !== null => 'VERIFIED',
            default => 'AWAITING_VERIFICATION',
        };
    }

    /** @return list<array{step: string, user_id: string, name: ?string, at: ?string}> */
    public function approvals(PolicyIssuanceRequest $r): array
    {
        $final = $r->status === 'REJECTED' ? 'REJECTED' : ($r->first_approved_by ? 'SECOND_APPROVAL' : 'APPROVED');
        $steps = [['REQUESTED', $r->requested_by, $r->created_at], ['CORRECTION_REQUESTED', $r->correction_requested_by, $r->correction_requested_at],
            ['VERIFIED', $r->verified_by, $r->verified_at], ['FIRST_APPROVAL', $r->first_approved_by, $r->first_approved_at], [$final, $r->approved_by, $r->approved_at]];
        $steps = array_values(array_filter($steps, fn ($s) => $s[1] !== null));
        $names = User::whereIn('id', array_column($steps, 1))->pluck('full_name', 'id');

        return array_map(fn ($s) => ['step' => $s[0], 'user_id' => $s[1], 'name' => $names[$s[1]] ?? null, 'at' => $s[2]?->toIso8601String()], $steps);
    }

    /** @return list<string> what $user may do next on this request ($mayDecide = holds the deciding permission); maker-checker applied. */
    public function capabilities(PolicyIssuanceRequest $r, User $user, bool $mayDecide): array
    {
        if (! $mayDecide || ! in_array($r->status, ['REQUESTED', 'CARRIER_REVIEW'], true) || $r->requested_by === $user->id) {
            return [];
        }
        $stage = $this->stage($r);
        $caps = ['reject'];
        if ($stage !== 'CORRECTION_REQUESTED') {
            $caps[] = 'request_correction';
        }
        if (in_array($stage, ['CORRECTION_REQUESTED', 'AWAITING_VERIFICATION'], true)) {
            $caps[] = 'verify';
        }
        if (in_array($stage, ['VERIFIED', 'AWAITING_VERIFICATION'], true)) {
            $caps[] = 'approve';
        }
        if ($stage === 'AWAITING_SECOND_APPROVAL' && $r->first_approved_by !== $user->id) {
            $caps[] = 'second_approve';
        }

        return $caps;
    }

    private function lockPending(PolicyIssuanceRequest $request, User $actor, bool $allowCorrection = false): PolicyIssuanceRequest
    {
        $request = PolicyIssuanceRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
        if (! in_array($request->status, ['REQUESTED', 'CARRIER_REVIEW'], true)) {
            throw ValidationException::withMessages(['status' => __('wave5.issuance_not_pending')]);
        }
        if ($request->requested_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => __('wave5.maker_checker')]);
        }
        if (! $allowCorrection && $request->correction_requested_at !== null) {
            throw ValidationException::withMessages(['status' => __('issuance_maker_checker.correction_pending')]);
        }

        return $request;
    }

    /** POLICY_ISSUE staff authority for the premium; null when the carrier has no POLICY_ISSUE limits configured (single approval). */
    private function issueAuthority(PolicyIssuanceRequest $request, User $actor): ?AuthorityOutcome
    {
        $configured = DB::table('authority_limits')->where('authority_type', 'POLICY_ISSUE')->where('carrier_id', $request->carrier_id)->where('status', 'ACTIVE')->exists();
        if (! $configured) {
            return null;
        }
        $terms = $request->proposal->terms_snapshot ?? [];

        return $this->authority->checkStaffLimit($request->tenant_id, $request->carrier_id, $actor, 'POLICY_ISSUE', (int) ($terms['total_minor'] ?? 0), (string) ($terms['currency'] ?? 'XAF'),
            ['type' => 'policy_issuance_request', 'id' => $request->id, 'title' => 'Policy issuance'], 'policy.issue', $request->proposal->offer?->quote?->line_code);
    }

    private function problem(string $code): ApiProblemException
    {
        return new ApiProblemException($code, 409, __('issuance_maker_checker.'.strtolower($code)));
    }

    private function issue(PolicyIssuanceRequest $request, array $data, User $actor, bool $second): Policy
    {
        return DB::transaction(function () use ($request, $data, $actor, $second): Policy {
            $request = $this->lockPending($request, $actor);
            if ($second !== ($request->first_approved_by !== null)) {
                throw $this->problem('SECOND_APPROVAL_REQUIRED');
            }

            $proposal = $request->proposal;
            if ($this->json->hash($proposal->terms_snapshot) !== $request->terms_hash) {
                throw ValidationException::withMessages(['terms' => __('wave5.terms_changed')]);
            }
            // REQ-KYC-001 gate on issue (KYC may have expired since the bind request).
            app(\App\Application\Kyc\KycGate::class)->assertMayProceed($request->tenant_id, $proposal->party_id, 'ISSUE', 'policy_issuance_request', $request->id);
            app(\App\Application\Compliance\Aml\Screening\ComplianceGate::class)->assertMayProceed($request->tenant_id, [$proposal->party_id], 'ISSUE', 'policy_issuance_request', $request->id);

            // Renewals: when the proposal's quote came from a renewal case,
            // link the successor to the policy it renews (B14).
            $renewalCase = RenewalCase::where('renewal_quote_id', $proposal->offer?->quote_id)->first();
            if (! isset($data['previous_policy_id']) && $renewalCase) {
                $data['previous_policy_id'] = $renewalCase->policy_id;
            }

            if (isset($data['previous_policy_id']) && ! Policy::where([
                'id' => $data['previous_policy_id'],
                'tenant_id' => $request->tenant_id,
            ])->exists()) {
                throw ValidationException::withMessages(['previous_policy_id' => __('wave5.successor_invalid')]);
            }

            $fromStatus = $request->status;
            $request->update([
                'status' => 'APPROVED',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'carrier_reference' => $data['carrier_reference'],
            ]);

            $policy = Policy::create([
                'tenant_id' => $request->tenant_id,
                'proposal_id' => $request->proposal_id,
                'payment_intent_id' => $request->payment_intent_id,
                'carrier_id' => $request->carrier_id,
                'party_id' => $proposal->party_id,
                // REQ-SET-006: server-side numbering only; any caller-supplied number is ignored.
                'policy_number' => app(\App\Application\Documents\Engine\DocumentNumberAllocator::class)->allocatePolicyNumber($request->tenant_id, $request->carrier_id),
                'status' => 'ACTIVE',
                'coverage_starts_at' => $request->coverage_starts_at,
                'coverage_ends_at' => $request->coverage_ends_at,
                'currency' => $proposal->terms_snapshot['currency'],
                'premium_minor' => $proposal->terms_snapshot['total_minor'],
                'terms_snapshot' => $proposal->terms_snapshot,
                'issued_at' => now(),
                'issuance_reference' => $data['carrier_reference'],
                'issuance_request_id' => $request->id,
                'previous_policy_id' => $data['previous_policy_id'] ?? null,
                'terms_hash' => $request->terms_hash,
                'version' => 1,
            ]);

            DB::table('policy_status_history')->insert([
                'id' => (string) Str::uuid(),
                'policy_id' => $policy->id,
                'from_status' => 'PAID_PENDING_ISSUANCE',
                'to_status' => 'ACTIVE',
                'reason_code' => 'CARRIER_AUTHORIZED',
                'actor_id' => $actor->id,
                'metadata' => json_encode(['issuance_request_id' => $request->id]),
                'occurred_at' => now(),
            ]);

            // REQ-POL-002/003: immutable structured §84 snapshot = policy version 1 (bitemporal chronology).
            app(\App\Application\Policies\Chronology\PolicyChronologyWriter::class)->record(
                $policy, $policy->previous_policy_id ? 'RENEWAL' : 'ISSUANCE', $policy->coverage_starts_at,
                ['source_type' => 'policy_issuance_request', 'source_id' => $request->id, 'actor_id' => $actor->id, 'authority' => $request->authority_snapshot],
            );

            // REQ-OBL-001 / REQ-PAY-006: premium obligations + instalment schedule; the bind payment settles the first (savepoint; never blocks issuance).
            try {
                DB::transaction(fn () => app(\App\Application\Finance\Obligations\PolicyPremiumObligations::class)->generate($policy));
            } catch (\Throwable $e) {
                report($e);
            }
            // REQ-COM-001: SALE → ACCRUED commission for the producing intermediary (savepoint; never blocks issuance).
            try {
                DB::transaction(fn () => app(\App\Application\Commissions\Machine\CommissionLifecycleService::class)->onPolicyIssued($policy));
            } catch (\Throwable $e) {
                report($e);
            }

            $this->event($request, $fromStatus, 'APPROVED', 'CARRIER_AUTHORIZED', $actor);
            $this->audit->record('policy.issued', 'policy', $policy->id, ['policy_number' => $policy->policy_number]);
            $this->outbox->record('policy.issued', 'policy', $policy->id, [
                'policy_id' => $policy->id,
                'carrier_id' => $policy->carrier_id,
            ]);

            // A6/B12: certificate + schedule PDFs with QR at issuance. Never
            // blocks issuance: ensure() self-heals on first wallet view.
            try {
                $this->documents->ensure($policy, $actor);
            } catch (\Throwable $e) {
                report($e);
            }
            // Document engine: the class pack for this lifecycle trigger (savepoint; never blocks issuance).
            app(\App\Application\Documents\Engine\DocumentEngine::class)->fireQuietly($policy->previous_policy_id ? 'RENEWAL_ISSUED' : 'POLICY_ISSUED', $policy, [], $actor);
            // Carrier API connector (Activa): books the contract at the carrier after commit; never blocks issuance.
            app(\App\Application\Integrations\Activa\ActivaHooks::class)->policyIssued($policy);

            if ($renewalCase && $renewalCase->status === 'QUOTED') {
                try {
                    app(RenewalService::class)->complete($renewalCase, $policy);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $this->notifier->toParty($policy->party_id, $policy->tenant_id, 'POLICY',
                ...NotificationCatalog::message('policy_issued', [
                    'product' => $proposal->offer?->product?->name, 'policy' => $policy->policy_number, 'starts' => $policy->coverage_starts_at->toDateString(),
                ]),
                severity: 'SUCCESS', path: "/policy/{$policy->id}");

            return $policy;
        });
    }

    public function reject(PolicyIssuanceRequest $request, string $reason, User $actor): PolicyIssuanceRequest
    {
        return DB::transaction(function () use ($request, $reason, $actor): PolicyIssuanceRequest {
            $request = PolicyIssuanceRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! in_array($request->status, ['REQUESTED', 'CARRIER_REVIEW'], true)) {
                throw ValidationException::withMessages(['status' => __('wave5.issuance_not_pending')]);
            }
            if ($request->requested_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => __('wave5.maker_checker')]);
            }

            $fromStatus = $request->status;
            $request->update([
                'status' => 'REJECTED',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ]);
            $this->event($request, $fromStatus, 'REJECTED', 'CARRIER_REJECTED', $actor);
            $this->audit->record('policy.issuance.rejected', 'policy_issuance_request', $request->id, [], 'CARRIER_REJECTED');
            $this->outbox->record('policy.issuance.rejected', 'policy_issuance_request', $request->id, [
                'issuance_request_id' => $request->id,
                'carrier_id' => $request->carrier_id,
            ]);

            $proposal = $request->proposal;
            $this->notifier->toParty($proposal?->party_id, $request->tenant_id, 'POLICY', ...NotificationCatalog::message('policy_issuance_failed'),
                severity: 'WARNING', path: $proposal ? "/proposals/{$proposal->id}" : null);

            return $request->refresh();
        });
    }

    private function event(PolicyIssuanceRequest $request, ?string $from, string $to, string $reason, ?User $actor, array $meta = []): void
    {
        DB::table('policy_issuance_events')->insert([
            'id' => (string) Str::uuid(),
            'policy_issuance_request_id' => $request->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason_code' => $reason,
            'actor_id' => $actor?->id,
            'metadata' => json_encode((object) $meta),
            'occurred_at' => now(),
        ]);
    }
}

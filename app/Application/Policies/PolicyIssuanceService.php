<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Shared\CanonicalJson;
use App\Application\Authority\AuthorityService;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\Proposal;
use App\Models\RenewalCase;
use App\Application\Notifications\CustomerNotifier;
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
                || $payment->amount_minor !== (int) $terms['total_minor']
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
                    throw ValidationException::withMessages([
                        'delegated_authority_agreement_id' => __('wave5.authority_denied', ['reason' => $outcome->reason]),
                    ]);
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

    public function approve(PolicyIssuanceRequest $request, array $data, User $actor): Policy
    {
        return DB::transaction(function () use ($request, $data, $actor): Policy {
            $request = PolicyIssuanceRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! in_array($request->status, ['REQUESTED', 'CARRIER_REVIEW'], true)) {
                throw ValidationException::withMessages(['status' => __('wave5.issuance_not_pending')]);
            }
            if ($request->requested_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => __('wave5.maker_checker')]);
            }

            $proposal = $request->proposal;
            if ($this->json->hash($proposal->terms_snapshot) !== $request->terms_hash) {
                throw ValidationException::withMessages(['terms' => __('wave5.terms_changed')]);
            }
            // REQ-KYC-001 gate on issue (KYC may have expired since the bind request).
            app(\App\Application\Kyc\KycGate::class)->assertMayProceed($request->tenant_id, $proposal->party_id, 'ISSUE', 'policy_issuance_request', $request->id);

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

            if ($renewalCase && $renewalCase->status === 'QUOTED') {
                try {
                    app(RenewalService::class)->complete($renewalCase, $policy);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $product = $proposal->offer?->product?->name ?? 'Your policy';
            $this->notifier->toParty($policy->party_id, $policy->tenant_id, 'POLICY', "You're covered",
                "{$product} policy {$policy->policy_number} is active from ".$policy->coverage_starts_at->format('d M Y').'. Your certificate is in your wallet.',
                'SUCCESS', "/policy/{$policy->id}");

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
            $this->notifier->toParty($proposal?->party_id, $request->tenant_id, 'POLICY', 'Issuance could not be completed',
                'The insurer could not issue your policy. Our team will contact you about next steps, including a refund if applicable.',
                'WARNING', $proposal ? "/proposals/{$proposal->id}" : null);

            return $request->refresh();
        });
    }

    private function event(PolicyIssuanceRequest $request, ?string $from, string $to, string $reason, ?User $actor): void
    {
        DB::table('policy_issuance_events')->insert([
            'id' => (string) Str::uuid(),
            'policy_issuance_request_id' => $request->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason_code' => $reason,
            'actor_id' => $actor?->id,
            'metadata' => '{}',
            'occurred_at' => now(),
        ]);
    }
}

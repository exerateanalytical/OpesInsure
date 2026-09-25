<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Notifications\CustomerNotifier;
use App\Application\Policies\IssuanceQueue\IssuanceQueueService;
use App\Application\Policies\IssuanceQueue\IssuanceTerritoryResolver;
use App\Application\Rules\PremiumCover\PremiumCoverEvaluator;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\Proposal;
use App\Models\RenewalCase;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A4 / REQ-POL-004: a provider-confirmed, reconciled payment automatically opens the
 * policy issuance request (CARRIER_REVIEW) so it lands in the carrier/admin
 * issuance queue — nobody has to find the proposal in Filament and click
 * "Request issuance". Carrier approval stays authoritative: this never
 * approves anything. The customer is told payment arrived and issuance is
 * in progress (policy status PAID_PENDING_ISSUANCE).
 *
 * Before requesting, the pre-issuance controls run:
 *   - territory is resolved from data (IssuanceTerritoryResolver), never assumed;
 *   - PolicyIssuabilityService (REQ-PRP-004): underwriting approval (STP or human), payment condition, KYC and
 *     owner decision 31 — every ISSUANCE_REQUIRED document accepted (ProposalDocumentRequirements applies
 *     IssuanceDocumentAcceptance: named reviewer or approved automated control);
 *   - owner decision 17: the premium-to-cover rule engine (PremiumCoverEvaluator) for a PAID premium must not
 *     say NO_COVER / COVER_SUSPENDED. UNDETERMINED (no rule) does not block: carrier review decides.
 * Anything that stops issuance — a control, or a PolicyIssuanceService refusal/error — is RECORDED in the
 * failed / paid-not-issued queue (IssuanceQueueService), never swallowed, and the customer is told once.
 *
 * Idempotent: a second call (webhook replay, poll + callback race,
 * reconciliation:run catch-up) finds the existing request and does nothing.
 */
final class PaymentIssuanceTrigger
{
    public const BLOCKING_COVER_OUTCOMES = ['NO_COVER', 'COVER_SUSPENDED'];

    public function __construct(
        private PolicyIssuanceService $issuance,
        private CustomerNotifier $notifier,
        private IssuanceQueueService $queue,
        private IssuanceTerritoryResolver $territories,
        private PolicyIssuabilityService $issuability,
        private PremiumCoverEvaluator $premiumCover,
    ) {}

    /** Webhook / reconciliation entry point: never throws (a confirmed payment must never be undone). */
    public function afterPaymentSucceeded(PaymentIntentRecord $payment): ?PolicyIssuanceRequest
    {
        try {
            return $this->attempt($payment);
        } catch (Throwable $e) {
            report($e);
            try {
                $proposal = $payment->proposal_id ? Proposal::with('offer.product')->find($payment->proposal_id) : null;
                if ($proposal) {
                    $this->queue->record($payment, $proposal, 'ISSUANCE_REQUEST_FAILED', 'UNEXPECTED_ERROR', [], $e->getMessage());
                }
            } catch (Throwable $inner) {
                report($inner);
            }

            return null;
        }
    }

    /**
     * One issuance attempt. Returns the request when one exists or was opened; null when the payment is not
     * eligible or the attempt was queued as an issuance exception. $actor: the ops user retrying, if any.
     */
    public function attempt(PaymentIntentRecord $payment, ?User $actor = null): ?PolicyIssuanceRequest
    {
        $payment->refresh();
        if ($payment->status !== 'SUCCEEDED' || ! $payment->reconciled_at || ! $payment->proposal_id) {
            return null;
        }

        $proposal = Proposal::with('offer.quote', 'offer.product')->find($payment->proposal_id);
        if (! $proposal) {
            return null;
        }

        $existing = PolicyIssuanceRequest::where('proposal_id', $proposal->id)->first();
        if ($existing || Policy::where('proposal_id', $proposal->id)->exists()) {
            $this->queue->closeFor($proposal->id, $existing ? 'ISSUANCE_REQUESTED' : 'POLICY_ISSUED', $existing?->id, $actor);

            return $existing;
        }
        if ($proposal->status !== 'PAYMENT_PENDING') {
            Log::warning('issuance.auto_request.skipped', ['payment_intent_id' => $payment->id, 'proposal_status' => $proposal->status]);
            $this->queue->record($payment, $proposal, 'ISSUANCE_BLOCKED', 'PROPOSAL_STATUS:'.$proposal->status);

            return null;
        }

        $requester = $this->requester($payment, $proposal) ?? $actor;
        if (! $requester) {
            Log::warning('issuance.auto_request.no_actor', ['payment_intent_id' => $payment->id]);
            $this->queue->record($payment, $proposal, 'ISSUANCE_BLOCKED', 'NO_REQUESTER');

            return null;
        }

        $territory = $this->territories->resolve($proposal)['territory'];
        if ($territory === null) {
            $this->queue->record($payment, $proposal, 'ISSUANCE_BLOCKED', 'TERRITORY_UNRESOLVED');

            return null;
        }

        // REQ-PRP-004 POLICY_ISSUABLE gate (underwriting, payment condition, ISSUANCE_REQUIRED documents per
        // owner decision 31, KYC). Blockers are queued with their codes, not thrown away.
        $blockers = $this->issuability->evaluate($proposal)['blockers'];
        if ($blockers !== []) {
            $allDocuments = array_filter($blockers, fn (string $b) => ! str_starts_with($b, 'DOCUMENT_')) === [];
            $this->queue->record($payment, $proposal, 'ISSUANCE_BLOCKED', $allDocuments ? 'DOCUMENTS_NOT_ACCEPTED' : 'NOT_ISSUABLE', $blockers, null, $territory);

            return null;
        }

        [$starts, $ends] = $this->coveragePeriod($proposal);
        $cover = $this->premiumCover->evaluate([
            'product_id' => $proposal->offer?->product_id, 'carrier_id' => $proposal->offer?->carrier_id,
            'class_code' => $proposal->offer?->product?->getAttribute('class_code') ?? $proposal->offer?->quote?->line_code,
            'jurisdiction' => strlen($territory) === 2 ? $territory : null, 'premium_status' => 'PAID', 'effective_date' => $starts->toDateString(),
            'facts' => ['payment' => ['amount_minor' => $payment->amount_minor, 'currency' => $payment->currency]],
        ]);
        $coverSnapshot = ['outcome' => $cover['outcome'], 'cover_active' => $cover['cover_active'], 'rule' => $cover['rule'], 'missing_facts' => $cover['missing_facts']];
        if (in_array($cover['outcome'], self::BLOCKING_COVER_OUTCOMES, true)) {
            $this->queue->record($payment, $proposal, 'ISSUANCE_BLOCKED', 'PREMIUM_COVER_'.$cover['outcome'], [], null, $territory, $coverSnapshot);

            return null;
        }

        try {
            $request = $this->issuance->request(Tenant::findOrFail($proposal->tenant_id), $proposal, $payment, [
                'coverage_starts_at' => $starts->toDateTimeString(),
                'coverage_ends_at' => $ends->toDateTimeString(),
                'territory' => $territory,
            ], $requester);
        } catch (ValidationException $e) {
            $messages = array_values(array_map('strval', array_merge([], ...array_values($e->errors()))));
            $this->queue->record($payment, $proposal, 'ISSUANCE_REQUEST_FAILED', 'ISSUANCE_REQUEST_REFUSED', $messages, $e->getMessage(), $territory, $coverSnapshot);

            return null;
        } catch (Throwable $e) {
            report($e);
            $this->queue->record($payment, $proposal, 'ISSUANCE_REQUEST_FAILED', 'UNEXPECTED_ERROR', [], $e->getMessage(), $territory, $coverSnapshot);

            return null;
        }

        $this->queue->closeFor($proposal->id, 'ISSUANCE_REQUESTED', $request->id, $actor);

        $product = $proposal->offer?->product?->name ?? 'your cover';
        $this->notifier->toParty($proposal->party_id, $proposal->tenant_id, 'PAYMENT', 'Payment received — issuance in progress',
            "We received your payment for {$product}. The insurer is issuing your policy; we'll notify you as soon as you're covered.",
            'SUCCESS', "/payments/{$payment->id}");

        return $request;
    }

    /** The user who initiated the payment; else the customer's own account. */
    private function requester(PaymentIntentRecord $payment, Proposal $proposal): ?User
    {
        if ($payment->requested_by && ($u = User::find($payment->requested_by))) {
            return $u;
        }

        return User::where('party_id', $proposal->party_id)->orderBy('created_at')->first();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function coveragePeriod(Proposal $proposal): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $quote = $proposal->offer?->quote;
        $facts = $quote?->risk_facts ?? [];

        if ($quote?->line_code === 'TRAVEL') {
            try {
                $departure = isset($facts['departure_date']) ? CarbonImmutable::parse($facts['departure_date'])->startOfDay() : null;
                $return = isset($facts['return_date']) ? CarbonImmutable::parse($facts['return_date'])->endOfDay() : null;
                if ($departure && $return && $return->greaterThan($departure) && ! $departure->lessThan($today)) {
                    return [$departure, $return];
                }
            } catch (Throwable) {
                // fall through to the default window
            }

            return [$today, $today->addDays(30)->endOfDay()];
        }

        // A renewal continues where the previous policy ends.
        $previous = $quote ? RenewalCase::where('renewal_quote_id', $quote->id)->first()?->policy : null;
        $starts = $previous && $previous->coverage_ends_at->greaterThan($today) ? CarbonImmutable::parse($previous->coverage_ends_at) : $today;

        return [$starts, $starts->addYear()->subDay()->endOfDay()];
    }
}

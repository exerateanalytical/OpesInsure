<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Notifications\CustomerNotifier;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\Proposal;
use App\Models\RenewalCase;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A4: a provider-confirmed, reconciled payment automatically opens the
 * policy issuance request (CARRIER_REVIEW) so it lands in the carrier/admin
 * issuance queue — nobody has to find the proposal in Filament and click
 * "Request issuance". Carrier approval stays authoritative: this never
 * approves anything. The customer is told payment arrived and issuance is
 * in progress (policy status PAID_PENDING_ISSUANCE).
 *
 * Idempotent: a second call (webhook replay, poll + callback race,
 * reconciliation:run catch-up) finds the existing request and does nothing.
 */
final class PaymentIssuanceTrigger
{
    public function __construct(private PolicyIssuanceService $issuance, private CustomerNotifier $notifier) {}

    public function afterPaymentSucceeded(PaymentIntentRecord $payment): ?PolicyIssuanceRequest
    {
        try {
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
                return $existing;
            }
            if ($proposal->status !== 'PAYMENT_PENDING') {
                Log::warning('issuance.auto_request.skipped', ['payment_intent_id' => $payment->id, 'proposal_status' => $proposal->status]);

                return null;
            }

            $actor = $this->requester($payment, $proposal);
            if (! $actor) {
                Log::warning('issuance.auto_request.no_actor', ['payment_intent_id' => $payment->id]);

                return null;
            }

            [$starts, $ends] = $this->coveragePeriod($proposal);
            $request = $this->issuance->request(Tenant::findOrFail($proposal->tenant_id), $proposal, $payment, [
                'coverage_starts_at' => $starts->toDateTimeString(),
                'coverage_ends_at' => $ends->toDateTimeString(),
                'territory' => 'CM',
            ], $actor);

            $product = $proposal->offer?->product?->name ?? 'your cover';
            $this->notifier->toParty($proposal->party_id, $proposal->tenant_id, 'PAYMENT', 'Payment received — issuance in progress',
                "We received your payment for {$product}. The insurer is issuing your policy; we'll notify you as soon as you're covered.",
                'SUCCESS', "/payments/{$payment->id}");

            return $request;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
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

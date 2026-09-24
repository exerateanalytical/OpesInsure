<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Models\Claim;
use App\Models\PaymentIntentRecord;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\UnderwritingCase;
use App\Models\User;
use App\Models\UserDevice;
use Throwable;

/**
 * A14: customer notifications for lifecycle changes, attached as Eloquent
 * model events (LifecycleServiceProvider) so every write path — mobile API,
 * partner/carrier workspace, Filament desk, services — produces the same
 * notification without each controller having to remember to. Payment
 * success and issuance are notified explicitly by PaymentIssuanceTrigger /
 * PolicyIssuanceService (they carry more context); renewal reminders by
 * policies:notify-expiry.
 *
 * Every handler is fail-safe: a notification problem never breaks the
 * state change that caused it.
 */
final class LifecycleNotificationProducer
{
    private const CLAIM_LABELS = [
        'SUBMITTED' => ['Claim received', 'We received claim %s. We will acknowledge it shortly.', 'INFO'],
        'ACKNOWLEDGED' => ['Claim acknowledged', 'Claim %s has been acknowledged and assigned to a handler.', 'INFO'],
        'EVIDENCE_PENDING' => ['Documents requested for your claim', 'The insurer needs more documents for claim %s. Open the claim to see what to upload.', 'WARNING'],
        'ASSESSMENT' => ['Claim under assessment', 'Claim %s is being assessed.', 'INFO'],
        'CARRIER_REVIEW' => ['Claim with the insurer', 'Claim %s is with the insurer for a decision.', 'INFO'],
        'APPROVED' => ['Claim approved', 'Claim %s has been approved. Settlement is being prepared.', 'SUCCESS'],
        'PARTIALLY_APPROVED' => ['Claim partially approved', 'Claim %s has been partially approved. Open it to review the settlement.', 'WARNING'],
        'DECLINED' => ['Claim declined', 'Claim %s was declined. Open it to see the reason and your appeal options.', 'ERROR'],
        'PAID' => ['Claim paid', 'The settlement for claim %s has been paid.', 'SUCCESS'],
        'DISPUTED' => ['Appeal registered', 'Your appeal on claim %s is registered and will be reviewed.', 'INFO'],
        'CLOSED' => ['Claim closed', 'Claim %s has been closed.', 'INFO'],
        'REOPENED' => ['Claim reopened', 'Claim %s has been reopened.', 'INFO'],
    ];

    public function __construct(private CustomerNotifier $notifier) {}

    public function quoteUpdated(Quote $quote): void
    {
        $this->safely(function () use ($quote) {
            if (! $quote->wasChanged('status')) {
                return;
            }
            if ($quote->status === 'OFFERED') {
                $count = (int) ($quote->comparison_context['offer_count'] ?? $quote->offers()->count());
                $this->notifier->toParty($quote->party_id, $quote->tenant_id, 'QUOTE', 'Your quotes are ready',
                    $count === 1 ? '1 offer is ready to review.' : "{$count} offers from licensed insurers are ready to compare.", 'SUCCESS', "/quotes/{$quote->id}");
            } elseif ($quote->status === 'REFERRED') {
                $this->notifier->toParty($quote->party_id, $quote->tenant_id, 'QUOTE', 'Your quote needs a closer look',
                    'No instant offer matched your details. An underwriter will review your request.', 'INFO', "/quotes/{$quote->id}");
            }
        });
    }

    public function proposalUpdated(Proposal $proposal): void
    {
        $this->safely(function () use ($proposal) {
            if (! $proposal->wasChanged('status')) {
                return;
            }
            $path = "/proposals/{$proposal->id}";
            [$title, $body, $severity] = match ($proposal->status) {
                'SUBMITTED', 'UNDER_REVIEW' => ['Proposal under review', 'Your proposal has been submitted and is being reviewed by the insurer.', 'INFO'],
                'APPROVED', 'PAYMENT_PENDING' => ['Proposal approved', 'Your proposal was approved. Complete payment to activate your cover.', 'SUCCESS'],
                'COUNTEROFFERED' => ['Counter-offer available', 'The insurer has proposed revised terms. Review and accept or decline the counter-offer.', 'WARNING'],
                'DECLINED' => ['Proposal declined', 'The insurer declined your proposal. You can compare other offers.', 'ERROR'],
                default => [null, null, null],
            };
            if ($title) {
                $this->notifier->toParty($proposal->party_id, $proposal->tenant_id, 'PROPOSAL', $title, $body, $severity, $path);
            }
        });
    }

    public function underwritingCaseUpdated(UnderwritingCase $case): void
    {
        $this->safely(function () use ($case) {
            if ($case->wasChanged('status') && $case->status === 'AWAITING_INFORMATION') {
                $proposal = $case->proposal;
                $this->notifier->toParty($proposal?->party_id, $case->tenant_id, 'PROPOSAL', 'More information needed',
                    'The underwriter needs more information about your proposal. Open it to respond.', 'WARNING', $proposal ? "/proposals/{$proposal->id}" : null);
            }
        });
    }

    public function claimSaved(Claim $claim): void
    {
        $this->safely(function () use ($claim) {
            if (! ($claim->wasRecentlyCreated || $claim->wasChanged('status')) || $claim->status === 'DRAFT') {
                return;
            }
            [$title, $body, $severity] = self::CLAIM_LABELS[$claim->status] ?? ['Claim update', 'Claim %s is now '.strtolower(str_replace('_', ' ', $claim->status)).'.', 'INFO'];
            $party = $claim->claimant_party_id ?? $claim->policy?->party_id;
            $this->notifier->toParty($party, $claim->tenant_id, 'CLAIM', $title, sprintf($body, $claim->claim_number), $severity, "/claim/{$claim->id}");
        });
    }

    public function paymentUpdated(PaymentIntentRecord $payment): void
    {
        $this->safely(function () use ($payment) {
            if ($payment->wasChanged('status') && in_array($payment->status, ['FAILED', 'EXPIRED'], true)) {
                $proposal = $payment->proposal;
                $this->notifier->toParty($proposal?->party_id, $payment->tenant_id, 'PAYMENT', 'Payment failed',
                    $payment->status === 'EXPIRED' ? 'Your payment request expired before it was approved. You can try again from the payment screen.' : 'Your payment did not go through. No money was taken; you can retry from the payment screen.',
                    'ERROR', "/payments/{$payment->id}");
            }
        });
    }

    public function deviceCreated(UserDevice $device): void
    {
        $this->safely(function () use ($device) {
            $user = $device->user;
            if (! $user || ! UserDevice::where('user_id', $user->id)->where('id', '!=', $device->id)->exists()) {
                return; // first device ever: that's sign-up, not a new sign-in
            }
            $name = $device->name ?: ($device->platform ?: 'a new device');
            $this->notifier->toUser($user, null, 'SECURITY', 'New sign-in to your account',
                "Your account was signed in on {$name}. If this wasn't you, change your password and sign out of all devices.", 'WARNING', '/security', true);
        });
    }

    public function userUpdated(User $user): void
    {
        $this->safely(function () use ($user) {
            if ($user->wasChanged('password') && $user->getOriginal('password') !== null) {
                $this->notifier->toUser($user, null, 'SECURITY', 'Your password was changed',
                    "Your OpesInsure password was just changed. If this wasn't you, reset it now and sign out of all devices.", 'WARNING', '/security', true);
            }
        });
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }
}

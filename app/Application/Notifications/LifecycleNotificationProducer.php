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
    /** Claim status => [NotificationCatalog code, severity]; copy in resources/lang/{en,fr}/customer_notifications.php. */
    private const CLAIM_MESSAGES = [
        'SUBMITTED' => ['claim_submitted', 'INFO'],
        'ACKNOWLEDGED' => ['claim_acknowledged', 'INFO'],
        'EVIDENCE_PENDING' => ['claim_evidence_pending', 'WARNING'],
        'ASSESSMENT' => ['claim_assessment', 'INFO'],
        'CARRIER_REVIEW' => ['claim_carrier_review', 'INFO'],
        'APPROVED' => ['claim_approved', 'SUCCESS'],
        'PARTIALLY_APPROVED' => ['claim_partially_approved', 'WARNING'],
        'DECLINED' => ['claim_declined', 'ERROR'],
        'PAID' => ['claim_paid', 'SUCCESS'],
        'DISPUTED' => ['claim_disputed', 'INFO'],
        'CLOSED' => ['claim_closed', 'INFO'],
        'REOPENED' => ['claim_reopened', 'INFO'],
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
                $this->notifier->toParty($quote->party_id, $quote->tenant_id, 'QUOTE',
                    ...NotificationCatalog::message($count === 1 ? 'quote_offers_ready_one' : 'quote_offers_ready', ['count' => $count]),
                    severity: 'SUCCESS', path: "/quotes/{$quote->id}");
            } elseif ($quote->status === 'REFERRED') {
                $this->notifier->toParty($quote->party_id, $quote->tenant_id, 'QUOTE', ...NotificationCatalog::message('quote_referred'), path: "/quotes/{$quote->id}");
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
            [$code, $severity] = match ($proposal->status) {
                'SUBMITTED', 'UNDER_REVIEW' => ['proposal_under_review', 'INFO'],
                'APPROVED', 'PAYMENT_PENDING' => ['proposal_approved', 'SUCCESS'],
                'COUNTEROFFERED' => ['proposal_counteroffer', 'WARNING'],
                'DECLINED' => ['proposal_declined', 'ERROR'],
                default => [null, null],
            };
            if ($code) {
                $this->notifier->toParty($proposal->party_id, $proposal->tenant_id, 'PROPOSAL', ...NotificationCatalog::message($code), severity: $severity, path: $path);
            }
        });
    }

    public function underwritingCaseUpdated(UnderwritingCase $case): void
    {
        $this->safely(function () use ($case) {
            if ($case->wasChanged('status') && $case->status === 'AWAITING_INFORMATION') {
                $proposal = $case->proposal;
                $this->notifier->toParty($proposal?->party_id, $case->tenant_id, 'PROPOSAL', ...NotificationCatalog::message('proposal_information_needed'),
                    severity: 'WARNING', path: $proposal ? "/proposals/{$proposal->id}" : null);
            }
        });
    }

    public function claimSaved(Claim $claim): void
    {
        $this->safely(function () use ($claim) {
            if (! ($claim->wasRecentlyCreated || $claim->wasChanged('status')) || $claim->status === 'DRAFT') {
                return;
            }
            [$code, $severity] = self::CLAIM_MESSAGES[$claim->status] ?? ['claim_status_changed', 'INFO'];
            $party = $claim->claimant_party_id ?? $claim->policy?->party_id;
            $this->notifier->toParty($party, $claim->tenant_id, 'CLAIM', ...NotificationCatalog::message($code, ['claim' => $claim->claim_number, 'status' => $claim->status]),
                severity: $severity, path: "/claim/{$claim->id}");
        });
    }

    public function paymentUpdated(PaymentIntentRecord $payment): void
    {
        $this->safely(function () use ($payment) {
            if ($payment->wasChanged('status') && in_array($payment->status, ['FAILED', 'EXPIRED'], true)) {
                $proposal = $payment->proposal;
                $this->notifier->toParty($proposal?->party_id, $payment->tenant_id, 'PAYMENT',
                    ...NotificationCatalog::message($payment->status === 'EXPIRED' ? 'payment_expired' : 'payment_failed'),
                    severity: 'ERROR', path: "/payments/{$payment->id}");
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
            $this->notifier->toUser($user, null, 'SECURITY', ...NotificationCatalog::message('security_new_sign_in', ['device' => $device->name ?: $device->platform]),
                severity: 'WARNING', path: '/security', forceSms: true);
        });
    }

    public function userUpdated(User $user): void
    {
        $this->safely(function () use ($user) {
            if ($user->wasChanged('password') && $user->getOriginal('password') !== null) {
                $this->notifier->toUser($user, null, 'SECURITY', ...NotificationCatalog::message('security_password_changed'),
                    severity: 'WARNING', path: '/security', forceSms: true);
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

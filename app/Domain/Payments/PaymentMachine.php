<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Shared\StateMachine\StateMachineDefinition;
use App\Domain\Shared\StateMachine\TransitionDefinition;

/**
 * REQ-PAY-001 — the payment machine on the shared StateMachineEngine (REQ-WFL-001), same shape as ProposalMachine.
 *
 * Blueprint states: CREATED → INITIATED → PENDING → SUCCESSFUL → RECONCILED, plus FAILED, EXPIRED, CANCELLED, REVERSED, REFUNDED.
 * payment_intents.status keeps the codes the live app already stores (no destructive rename); blueprintState() maps them:
 *   CREATED                          → CREATED
 *   PENDING_CUSTOMER                 → INITIATED   (provider accepted; payer must authorise / complete hosted checkout)
 *   PROCESSING, AWAITING_TRANSFER    → PENDING     (provider working / bank transfer instruction awaiting reconciliation)
 *   SUCCEEDED                        → SUCCESSFUL, or RECONCILED once payment_intents.reconciled_at is set
 *   REFUND_PENDING                   → SUCCESSFUL  (money still held until the refund settles)
 *   REFUNDED                         → REFUNDED
 *   CHARGEBACK_OPEN, CHARGED_BACK, REVERSED → REVERSED
 * RECONCILED is a flag (reconciled_at) on SUCCEEDED rather than a stored status, because reconciliation, refunds and
 * chargebacks all read SUCCEEDED today. Only signed provider callbacks may apply PROVIDER_EVENTS: the client is never authoritative.
 */
final class PaymentMachine
{
    public const NAME = 'payment';

    /** Events a verified provider callback (signed webhook / provider status re-query) may apply. */
    public const PROVIDER_EVENTS = ['process', 'succeed', 'fail', 'expire', 'refund', 'reverse', 'charge_back'];

    public const TERMINAL = ['EXPIRED', 'CANCELLED', 'REFUNDED', 'REVERSED', 'CHARGED_BACK'];

    public const BLUEPRINT = [
        'CREATED' => 'CREATED', 'PENDING_CUSTOMER' => 'INITIATED', 'PROCESSING' => 'PENDING', 'AWAITING_TRANSFER' => 'PENDING',
        'SUCCEEDED' => 'SUCCESSFUL', 'REFUND_PENDING' => 'SUCCESSFUL', 'FAILED' => 'FAILED', 'EXPIRED' => 'EXPIRED',
        'CANCELLED' => 'CANCELLED', 'REFUNDED' => 'REFUNDED', 'REVERSED' => 'REVERSED', 'CHARGEBACK_OPEN' => 'REVERSED', 'CHARGED_BACK' => 'REVERSED',
    ];

    private static ?StateMachineDefinition $machine = null;

    public static function blueprintState(string $status, bool $reconciled = false): string
    {
        return $status === 'SUCCEEDED' && $reconciled ? 'RECONCILED' : (self::BLUEPRINT[$status] ?? $status);
    }

    /** The transition a provider callback would apply to move $from → $to, or null when the machine forbids it. */
    public static function providerTransition(string $from, string $to): ?TransitionDefinition
    {
        if (! self::definition()->hasState($from)) {
            return null;
        }
        foreach (self::definition()->transitionsFrom($from) as $t) {
            if ($t->to === $to && in_array($t->event, self::PROVIDER_EVENTS, true)) {
                return $t;
            }
        }

        return null;
    }

    public static function definition(): StateMachineDefinition
    {
        $inFlight = ['PENDING_CUSTOMER', 'PROCESSING', 'AWAITING_TRANSFER'];

        return self::$machine ??= StateMachineDefinition::fromArray([
            'name' => self::NAME, 'version' => 1, 'subject_type' => 'payment_intent',
            'states' => [
                'CREATED' => ['initial' => true, 'label' => 'Created'],
                'PENDING_CUSTOMER' => ['label' => 'Initiated — awaiting payer'],
                'PROCESSING' => ['label' => 'Pending — provider processing'],
                'AWAITING_TRANSFER' => ['label' => 'Pending — bank transfer awaiting reconciliation'],
                'SUCCEEDED' => ['label' => 'Successful'],
                'FAILED' => ['label' => 'Failed'],
                'REFUND_PENDING' => ['label' => 'Refund pending'],
                'CHARGEBACK_OPEN' => ['label' => 'Chargeback open'],
                'EXPIRED' => ['terminal' => true, 'label' => 'Expired'],
                'CANCELLED' => ['terminal' => true, 'label' => 'Cancelled'],
                'REFUNDED' => ['terminal' => true, 'label' => 'Refunded'],
                'REVERSED' => ['terminal' => true, 'label' => 'Reversed'],
                'CHARGED_BACK' => ['terminal' => true, 'label' => 'Charged back'],
            ],
            'transitions' => [
                ['event' => 'initiate', 'from' => ['CREATED'], 'to' => 'PENDING_CUSTOMER', 'side_effects' => ['provider_adapter', 'outbox:payment.authorization.requested'],
                    'failure_path' => 'FAILED; the attempt records PROVIDER_REQUEST_FAILED'],
                ['event' => 'instruct_transfer', 'from' => ['CREATED'], 'to' => 'AWAITING_TRANSFER', 'side_effects' => ['bank_transfer_reference', 'outbox:payment.authorization.requested']],
                ['event' => 'retry', 'from' => ['FAILED'], 'to' => 'PENDING_CUSTOMER', 'side_effects' => ['new payment_attempt']],
                ['event' => 'process', 'from' => ['PENDING_CUSTOMER'], 'to' => 'PROCESSING', 'actors' => ['provider']],
                ['event' => 'succeed', 'from' => $inFlight, 'to' => 'SUCCEEDED', 'actors' => ['provider'], 'guards' => ['signed_callback', 'amount_and_currency_match'],
                    'side_effects' => ['reconciled_at', 'issuance_trigger', 'outbox:payment.status.changed'], 'failure_path' => 'webhook_inbox FAILED; status unchanged'],
                ['event' => 'fail', 'from' => ['CREATED', 'PENDING_CUSTOMER', 'PROCESSING'], 'to' => 'FAILED', 'actors' => ['provider', 'system']],
                ['event' => 'expire', 'from' => $inFlight, 'to' => 'EXPIRED', 'actors' => ['provider', 'system']],
                ['event' => 'cancel', 'from' => ['CREATED', 'PENDING_CUSTOMER', 'AWAITING_TRANSFER', 'FAILED'], 'to' => 'CANCELLED', 'actors' => ['customer', 'staff']],
                ['event' => 'request_refund', 'from' => ['SUCCEEDED'], 'to' => 'REFUND_PENDING', 'permission' => 'refund.request'],
                ['event' => 'refund', 'from' => ['SUCCEEDED', 'REFUND_PENDING'], 'to' => 'REFUNDED', 'actors' => ['provider']],
                ['event' => 'reverse', 'from' => ['SUCCEEDED'], 'to' => 'REVERSED', 'actors' => ['provider']],
                ['event' => 'open_chargeback', 'from' => ['SUCCEEDED'], 'to' => 'CHARGEBACK_OPEN', 'permission' => 'chargeback.manage'],
                ['event' => 'charge_back', 'from' => ['SUCCEEDED', 'CHARGEBACK_OPEN'], 'to' => 'CHARGED_BACK', 'actors' => ['provider']],
                ['event' => 'win_chargeback', 'from' => ['CHARGEBACK_OPEN'], 'to' => 'SUCCEEDED', 'permission' => 'chargeback.manage'],
            ],
        ]);
    }
}

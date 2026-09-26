<?php

declare(strict_types=1);

namespace App\Domain\Claims;

use App\Domain\Shared\StateMachine\StateMachineDefinition;
use App\Domain\Shared\StateMachine\TransitionDefinition;

/**
 * REQ-CLM-001 / REQ-DUP-006 — the ONE claim machine, on the shared StateMachineEngine (same shape as PaymentMachine).
 * Replaces ClaimStateMachine + ClaimLifecycle at runtime (those two are frozen legacy tables kept only for the
 * REQ-WFL-001 parity tests; this machine is a strict superset of both).
 *
 * claims.status keeps the codes already stored (no destructive rename); blueprintState() maps them:
 *   DRAFT→DRAFT, SUBMITTED→SUBMITTED, ACKNOWLEDGED→REGISTERED, EVIDENCE_PENDING→INFORMATION_REQUIRED,
 *   ASSESSMENT→UNDER_ASSESSMENT, INVESTIGATING→INVESTIGATING, CARRIER_REVIEW→DECISION_PENDING, APPROVED→APPROVED,
 *   PARTIALLY_APPROVED→PARTIALLY_APPROVED, DECLINED→REJECTED, DISPUTED→APPEALED, PAYMENT_PENDING→SETTLEMENT_PENDING,
 *   PAID→SETTLED, CLOSED→CLOSED, REOPENED→REOPENED.
 * storedStatus() accepts either vocabulary, so APIs may post blueprint names.
 */
final class ClaimMachine
{
    public const NAME = 'claim';

    public const SUBJECT = 'claim';

    public const BLUEPRINT = [
        'DRAFT' => 'DRAFT', 'SUBMITTED' => 'SUBMITTED', 'ACKNOWLEDGED' => 'REGISTERED', 'EVIDENCE_PENDING' => 'INFORMATION_REQUIRED',
        'ASSESSMENT' => 'UNDER_ASSESSMENT', 'INVESTIGATING' => 'INVESTIGATING', 'CARRIER_REVIEW' => 'DECISION_PENDING',
        'APPROVED' => 'APPROVED', 'PARTIALLY_APPROVED' => 'PARTIALLY_APPROVED', 'DECLINED' => 'REJECTED', 'DISPUTED' => 'APPEALED',
        'PAYMENT_PENDING' => 'SETTLEMENT_PENDING', 'PAID' => 'SETTLED', 'CLOSED' => 'CLOSED', 'REOPENED' => 'REOPENED',
    ];

    /** Stored statuses from which the claimant may still withdraw (before assessment, decision or payment). */
    public const WITHDRAWABLE = ['SUBMITTED', 'ACKNOWLEDGED', 'EVIDENCE_PENDING'];

    private static ?StateMachineDefinition $machine = null;

    public static function blueprintState(string $status): string
    {
        return self::BLUEPRINT[$status] ?? $status;
    }

    /** Stored code for a stored or blueprint status name (REGISTERED → ACKNOWLEDGED, REJECTED → DECLINED, ...). */
    public static function storedStatus(string $status): string
    {
        return isset(self::BLUEPRINT[$status]) ? $status : (array_search($status, self::BLUEPRINT, true) ?: $status);
    }

    public static function transitionTo(string $from, string $to): ?TransitionDefinition
    {
        $d = self::definition();
        $from = self::storedStatus($from);

        return $d->hasState($from) ? $d->transitionTo($from, self::storedStatus($to)) : null;
    }

    public static function definition(): StateMachineDefinition
    {
        $states = [];
        foreach (self::BLUEPRINT as $code => $blueprint) {
            $states[$code] = ['initial' => $code === 'DRAFT', 'label' => ucwords(strtolower(str_replace('_', ' ', $blueprint)))];
        }

        return self::$machine ??= StateMachineDefinition::fromArray([
            'name' => self::NAME, 'version' => 1, 'subject_type' => self::SUBJECT,
            'states' => $states,
            'transitions' => [
                ['event' => 'submit', 'from' => ['DRAFT'], 'to' => 'SUBMITTED', 'domain_event' => 'claim.fnol.submitted'],
                ['event' => 'register', 'from' => ['SUBMITTED'], 'to' => 'ACKNOWLEDGED'],
                ['event' => 'request_information', 'from' => ['ACKNOWLEDGED', 'ASSESSMENT', 'INVESTIGATING', 'CARRIER_REVIEW'], 'to' => 'EVIDENCE_PENDING'],
                ['event' => 'start_assessment', 'from' => ['ACKNOWLEDGED', 'EVIDENCE_PENDING', 'INVESTIGATING', 'REOPENED'], 'to' => 'ASSESSMENT'],
                ['event' => 'investigate', 'from' => ['ASSESSMENT', 'CARRIER_REVIEW'], 'to' => 'INVESTIGATING'],
                ['event' => 'refer_for_decision', 'from' => ['ASSESSMENT', 'INVESTIGATING', 'DISPUTED'], 'to' => 'CARRIER_REVIEW'],
                ['event' => 'approve', 'from' => ['CARRIER_REVIEW'], 'to' => 'APPROVED', 'domain_event' => 'claim.decision.approved'],
                ['event' => 'partially_approve', 'from' => ['CARRIER_REVIEW'], 'to' => 'PARTIALLY_APPROVED', 'domain_event' => 'claim.decision.approved'],
                ['event' => 'reject', 'from' => ['CARRIER_REVIEW'], 'to' => 'DECLINED', 'domain_event' => 'claim.decision.rejected'],
                ['event' => 'appeal', 'from' => ['DECLINED', 'PARTIALLY_APPROVED'], 'to' => 'DISPUTED'],
                ['event' => 'request_settlement', 'from' => ['APPROVED', 'PARTIALLY_APPROVED'], 'to' => 'PAYMENT_PENDING'],
                ['event' => 'settle', 'from' => ['APPROVED', 'PARTIALLY_APPROVED', 'PAYMENT_PENDING'], 'to' => 'PAID', 'domain_event' => 'claim.payment.paid'],
                ['event' => 'close', 'from' => ['APPROVED', 'DECLINED', 'PAID', 'DISPUTED'], 'to' => 'CLOSED'],
                ['event' => 'reopen', 'from' => ['CLOSED'], 'to' => 'REOPENED'],
                // Claimant withdrawal before any assessment/decision/payment (MobileClaimService::withdraw).
                ['event' => 'withdraw', 'from' => ClaimMachine::WITHDRAWABLE, 'to' => 'CLOSED', 'domain_event' => 'claim.withdrawn'],
            ],
        ]);
    }
}

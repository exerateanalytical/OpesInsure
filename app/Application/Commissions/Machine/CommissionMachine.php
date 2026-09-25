<?php

declare(strict_types=1);

namespace App\Application\Commissions\Machine;

use App\Domain\Shared\StateMachine\StateMachineDefinition;

/**
 * REQ-COM-001 (WF-064..067) — the commission machine on the shared StateMachineEngine (REQ-WFL-001), same shape as PaymentMachine.
 *
 * Blueprint: SALE → CALCULATED → ACCRUED → EARNED → APPROVED → PAYABLE → PAID, plus REVERSED, CLAWED_BACK, ADJUSTED, DISPUTED.
 * commission_accruals.status keeps the codes the live app already stores (no destructive rename); BLUEPRINT maps them:
 *   (no row yet)   → SALE        the policy sale that triggers calculation
 *   CALCULATED     → CALCULATED  figure computed, not yet booked
 *   PENDING        → ACCRUED     booked at issuance (CommissionService::accrue); the legacy code stays
 *   EARNED         → EARNED      the premium it is based on is settled (financial_obligations)
 *   APPROVED       → APPROVED    checked by finance (commission.approve)
 *   VESTED         → PAYABLE     holdback/vesting elapsed; a PAYABLE COMMISSION financial obligation exists. Kept as VESTED
 *                                because PayoutService / statements pay VESTED accruals.
 *   AVAILABLE      → PAYABLE     legacy read-model code (mobile agent portal)
 *   PAID, REVERSED, CLAWED_BACK, ADJUSTED, DISPUTED → same name.
 * A partial clawback is a CLAWBACK movement that leaves the state unchanged; claw_back to CLAWED_BACK is the full one.
 * The legacy direct 'vest' (ACCRUED → PAYABLE once vests_at elapsed) stays for the wave-6 endpoint.
 */
final class CommissionMachine
{
    public const NAME = 'commission';

    public const SUBJECT = 'commission_accrual';

    public const TERMINAL = ['PAID', 'REVERSED', 'CLAWED_BACK'];

    /** Stored statuses that still carry commission that can be clawed back. */
    public const LIVE = ['CALCULATED', 'PENDING', 'EARNED', 'APPROVED', 'VESTED', 'AVAILABLE', 'ADJUSTED', 'DISPUTED'];

    public const BLUEPRINT = [
        'SALE' => 'SALE', 'CALCULATED' => 'CALCULATED', 'PENDING' => 'ACCRUED', 'EARNED' => 'EARNED', 'APPROVED' => 'APPROVED',
        'VESTED' => 'PAYABLE', 'AVAILABLE' => 'PAYABLE', 'PAID' => 'PAID', 'REVERSED' => 'REVERSED', 'CLAWED_BACK' => 'CLAWED_BACK',
        'ADJUSTED' => 'ADJUSTED', 'DISPUTED' => 'DISPUTED',
    ];

    private static ?StateMachineDefinition $machine = null;

    public static function blueprintState(string $status): string
    {
        return self::BLUEPRINT[$status] ?? $status;
    }

    public static function definition(): StateMachineDefinition
    {
        return self::$machine ??= StateMachineDefinition::fromArray([
            'name' => self::NAME, 'version' => 1, 'subject_type' => self::SUBJECT,
            'states' => [
                'SALE' => ['initial' => true, 'label' => 'Sale'],
                'CALCULATED' => ['label' => 'Calculated'],
                'PENDING' => ['label' => 'Accrued'],
                'EARNED' => ['label' => 'Earned — premium settled'],
                'APPROVED' => ['label' => 'Approved'],
                'VESTED' => ['label' => 'Payable'],
                'AVAILABLE' => ['label' => 'Payable (legacy)'],
                'ADJUSTED' => ['label' => 'Adjusted — awaiting re-approval'],
                'DISPUTED' => ['label' => 'Disputed'],
                'PAID' => ['terminal' => true, 'label' => 'Paid'],
                'REVERSED' => ['terminal' => true, 'label' => 'Reversed'],
                'CLAWED_BACK' => ['terminal' => true, 'label' => 'Clawed back'],
            ],
            'transitions' => [
                ['event' => 'calculate', 'from' => ['SALE'], 'to' => 'CALCULATED', 'domain_event' => 'commission.calculated'],
                ['event' => 'accrue', 'from' => ['SALE', 'CALCULATED'], 'to' => 'PENDING', 'side_effects' => ['commission_movements:ACCRUAL', 'post:commission.accrued']],
                ['event' => 'earn', 'from' => ['PENDING'], 'to' => 'EARNED', 'guards' => ['commission.premium_settled'],
                    'side_effects' => ['post:commission.earned'], 'domain_event' => 'commission.earned', 'failure_path' => 'stay ACCRUED until the premium obligations are settled'],
                ['event' => 'approve', 'from' => ['EARNED'], 'to' => 'APPROVED', 'domain_event' => 'commission.approved'],
                ['event' => 'approve', 'from' => ['ADJUSTED'], 'to' => 'APPROVED', 'guards' => ['commission.premium_settled', 'commission.maker_checker'], 'domain_event' => 'commission.approved'],
                ['event' => 'make_payable', 'from' => ['APPROVED'], 'to' => 'VESTED', 'guards' => ['commission.vesting_elapsed'],
                    'side_effects' => ['partner_statement:PAYABLE/COMMISSION'], 'domain_event' => 'commission.payable'],
                ['event' => 'vest', 'from' => ['PENDING'], 'to' => 'VESTED', 'guards' => ['commission.vesting_elapsed'],
                    'side_effects' => ['partner_statement:PAYABLE/COMMISSION'], 'domain_event' => 'commission.payable'],
                ['event' => 'pay', 'from' => ['VESTED', 'AVAILABLE'], 'to' => 'PAID', 'side_effects' => ['partner_statement_obligation:settled_by_payout'], 'domain_event' => 'commission.paid'],
                ['event' => 'adjust', 'from' => ['PENDING', 'EARNED', 'APPROVED'], 'to' => 'ADJUSTED', 'side_effects' => ['commission_movements:ADJUSTMENT'], 'domain_event' => 'commission.adjusted'],
                ['event' => 'dispute', 'from' => ['PENDING', 'EARNED', 'APPROVED', 'VESTED', 'ADJUSTED'], 'to' => 'DISPUTED', 'domain_event' => 'commission.disputed'],
                ['event' => 'resolve_dispute', 'from' => ['DISPUTED'], 'to' => 'ADJUSTED', 'domain_event' => 'commission.dispute_resolved'],
                ['event' => 'reverse', 'from' => ['CALCULATED', 'PENDING', 'EARNED', 'APPROVED', 'ADJUSTED', 'DISPUTED'], 'to' => 'REVERSED',
                    'side_effects' => ['commission_movements:REVERSAL', 'post:commission.clawed_back'], 'domain_event' => 'commission.reversed'],
                ['event' => 'claw_back', 'from' => ['PENDING', 'EARNED', 'APPROVED', 'VESTED', 'AVAILABLE', 'ADJUSTED', 'DISPUTED'], 'to' => 'CLAWED_BACK',
                    'side_effects' => ['commission_movements:CLAWBACK', 'post:commission.clawed_back']],
            ],
        ]);
    }
}

<?php

// Commission, partner statement / payout, carrier settlement and bordereau desktop actions (UI coverage batches 9-10).
return [
    // commission rules
    'ruleCreate' => ['label' => 'New commission rule', 'help' => 'Creates a DRAFT rule version. Another user must approve it before it applies.', 'done' => 'Commission rule created (draft).'],
    'ruleApprove' => ['label' => 'Approve rule', 'help' => 'The creator of the rule cannot approve it. Overlapping approved rules are refused.', 'done' => 'Commission rule approved.'],

    // commission accruals
    'accrualAccrue' => ['label' => 'Accrue commission', 'help' => 'Accrues commission on a policy with an approved rule for a partner.', 'done' => 'Commission accrued.'],
    'accrualEarn' => ['label' => 'Mark earned', 'help' => 'Allowed once the premium obligations of the policy are settled.', 'done' => 'Commission earned.'],
    'accrualVest' => ['label' => 'Vest (legacy)', 'help' => 'Moves an accrued commission directly to payable once the vesting date has passed.', 'done' => 'Commission vested.'],
    'accrualApprove' => ['label' => 'Approve commission', 'help' => 'An adjusted commission must be approved by someone other than the user who adjusted it.', 'done' => 'Commission approved.'],
    'accrualMakePayable' => ['label' => 'Make payable', 'help' => 'Allowed once the vesting period has elapsed.', 'done' => 'Commission made payable.'],
    'accrualAdjust' => ['label' => 'Adjust amount', 'help' => 'Sets a new commission amount; the commission then needs a new approval.', 'done' => 'Commission adjusted; awaiting approval.'],
    'accrualDispute' => ['label' => 'Dispute', 'done' => 'Commission disputed.'],
    'accrualResolveDispute' => ['label' => 'Resolve dispute', 'help' => 'Optionally corrects the amount; the commission then needs a new approval.', 'done' => 'Dispute resolved; awaiting approval.'],
    'accrualClawback' => ['label' => 'Claw back', 'help' => 'Recovers part or all of the commission not yet paid.', 'done' => 'Commission clawed back.'],
    'accrualReverse' => ['label' => 'Reverse', 'done' => 'Commission reversed.'],

    // partner statements
    'statementGenerate' => ['label' => 'Generate statements', 'help' => 'Generates the commission statement of one partner, or of every partner with activity when no partner is chosen.', 'done' => 'Statements generated.'],
    'statementPrepare' => ['label' => 'Prepare statement', 'help' => 'Prepares a DRAFT statement from the partner\'s vested commissions for the period.', 'done' => 'Statement prepared (draft).'],
    'statementApprove' => ['label' => 'Approve statement', 'help' => 'The preparer cannot approve. Pending adjustments must be decided first.', 'done' => 'Statement approved.'],
    'statementPublish' => ['label' => 'Publish statement', 'done' => 'Statement published to the partner.'],
    'statementDispute' => ['label' => 'Dispute statement', 'help' => 'Refused while a payout on this statement is requested, approved, processing or paid.', 'done' => 'Statement disputed.'],
    'statementResolveDispute' => ['label' => 'Resolve dispute', 'help' => 'Returns the statement to DRAFT for a new approval.', 'done' => 'Dispute resolved; statement back to draft.'],
    'adjustmentPropose' => ['label' => 'Propose adjustment', 'help' => 'Another user must approve the adjustment.', 'done' => 'Adjustment proposed.'],
    'adjustmentApprove' => ['label' => 'Approve adjustment', 'help' => 'The user who proposed the adjustment cannot approve it.', 'done' => 'Adjustment approved.'],
    'adjustmentReject' => ['label' => 'Reject adjustment', 'done' => 'Adjustment rejected.'],

    // partner payouts
    'payoutRequest' => ['label' => 'Request payout', 'help' => 'Cannot exceed the statement closing balance less payouts already reserved.', 'done' => 'Payout requested.'],
    'payoutApprove' => ['label' => 'Approve payout', 'help' => 'The requester cannot approve the payout.', 'done' => 'Payout approved.'],
    'payoutProcess' => ['label' => 'Send for payment', 'done' => 'Payout sent to the provider.'],
    'payoutComplete' => ['label' => 'Confirm paid', 'done' => 'Payout confirmed as paid.'],
    'payoutFail' => ['label' => 'Record failure', 'done' => 'Payout failure recorded.'],
    'payoutReverse' => ['label' => 'Reverse payout', 'help' => 'The requester cannot reverse the payout. The commissions it paid become payable again.', 'done' => 'Payout reversed.'],

    // carrier settlements (policy basis)
    'carrierSettlementPrepare' => ['label' => 'Prepare settlement (policies)', 'help' => 'Nets the premiums of active policies issued in the period against their commissions.', 'done' => 'Settlement prepared (draft).'],
    'carrierSettlementApprove' => ['label' => 'Approve settlement', 'help' => 'The preparer cannot approve the settlement.', 'done' => 'Settlement approved.'],
    'carrierSettlementSubmit' => ['label' => 'Submit to bank', 'done' => 'Settlement submitted to the bank.'],
    'carrierSettlementPaid' => ['label' => 'Confirm paid', 'done' => 'Settlement confirmed as paid.'],
    'carrierSettlementFail' => ['label' => 'Record bank failure', 'done' => 'Settlement failure recorded.'],
    'carrierSettlementReverse' => ['label' => 'Reverse settlement', 'done' => 'Settlement reversed.'],

    // carrier settlements (obligations basis)
    'ledgerSettlementDraft' => ['label' => 'New settlement (obligations)', 'help' => 'Creates a DRAFT settlement calculated from the open carrier obligations.', 'done' => 'Settlement created (draft).'],
    'ledgerSettlementCalculate' => ['label' => 'Calculate', 'done' => 'Settlement calculated.'],
    'ledgerSettlementReview' => ['label' => 'Submit for review', 'done' => 'Settlement submitted for review.'],
    'ledgerSettlementCancel' => ['label' => 'Cancel settlement', 'done' => 'Settlement cancelled.'],
    'ledgerSettlementApprove' => ['label' => 'Approve settlement', 'help' => 'The preparer cannot approve the settlement.', 'done' => 'Settlement approved.'],
    'ledgerSettlementReject' => ['label' => 'Send back', 'help' => 'Returns the settlement to DRAFT and cancels its calculated lines.', 'done' => 'Settlement sent back to draft.'],
    'ledgerSettlementProcess' => ['label' => 'Send for payment', 'done' => 'Settlement payment submitted.'],
    'ledgerSettlementFail' => ['label' => 'Record payment failure', 'done' => 'Payment failure recorded; settlement back to approved.'],
    'ledgerSettlementSettle' => ['label' => 'Confirm settled', 'done' => 'Settlement settled.'],
    'ledgerSettlementReconcile' => ['label' => 'Reconcile', 'done' => 'Settlement reconciled.'],

    // bordereaux
    'bordereauPrepare' => ['label' => 'Prepare bordereau', 'done' => 'Bordereau prepared (draft).'],
    'bordereauApprove' => ['label' => 'Approve bordereau', 'help' => 'The preparer cannot approve the bordereau.', 'done' => 'Bordereau approved.'],
    'bordereauSubmit' => ['label' => 'Submit to insurer', 'help' => 'The preparer cannot submit the bordereau.', 'done' => 'Bordereau submitted to the insurer.'],
    'bordereauAcknowledge' => ['label' => 'Record acknowledgement', 'done' => 'Insurer acknowledgement recorded.'],
    'bordereauReject' => ['label' => 'Record rejection', 'done' => 'Insurer rejection recorded.'],

    'fields' => [
        'carrier' => 'Insurer', 'product' => 'Product', 'partner' => 'Partner', 'partner_optional' => 'Partner (optional)', 'policy' => 'Policy', 'rule' => 'Commission rule',
        'basis_points' => 'Commission rate (basis points, 100 = 1%)', 'holdback_basis_points' => 'Holdback (basis points)', 'vesting_days' => 'Vesting period (days)',
        'effective_from' => 'Effective from', 'effective_until' => 'Effective until',
        'amount_minor' => 'Amount (minor units, 100 = 1 FCFA)', 'new_amount_minor' => 'New amount (minor units, 100 = 1 FCFA)',
        'corrected_amount_minor' => 'Corrected amount (minor units, 100 = 1 FCFA, optional)', 'signed_amount_minor' => 'Amount (minor units, 100 = 1 FCFA; negative to deduct)',
        'opening_balance_minor' => 'Opening balance (minor units, 100 = 1 FCFA)',
        'reason' => 'Reason', 'reason_code' => 'Reason code', 'note' => 'Note', 'resolution' => 'Resolution', 'adjustment' => 'Adjustment',
        'period_start' => 'Period start', 'period_end' => 'Period end', 'currency' => 'Currency',
        'destination_type' => 'Destination type', 'destination' => 'Destination (number or account)', 'provider' => 'Payment provider', 'provider_reference' => 'Provider reference',
        'failure_code' => 'Failure code', 'failure_message' => 'Failure message', 'bank_reference' => 'Bank reference', 'reconciliation_reference' => 'Reconciliation reference',
        'approval_notes' => 'Approval notes (at least 20 characters, optional)', 'bordereau_type' => 'Bordereau type', 'carrier_reference' => 'Insurer reference',
    ],

    'codes' => [
        'MOBILE_MONEY' => 'Mobile money', 'BANK' => 'Bank transfer',
        'PREMIUM' => 'Premium', 'CLAIM' => 'Claims', 'ENDORSEMENT' => 'Endorsements', 'CANCELLATION' => 'Cancellations', 'COMMISSION' => 'Commission',
    ],
];

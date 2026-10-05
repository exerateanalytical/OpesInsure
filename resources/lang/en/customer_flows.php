<?php

// Launch 2026-10-02: customer instalments, refunds, settlement discharge and payout (MobileCustomerMoneyController,
// MobileSettlementDischargeController).
return [
    'idempotency_required' => 'An Idempotency-Key header (16 to 80 characters) is required to pay an instalment.',
    'instalment_not_payable' => 'This instalment cannot be paid from the app. Contact your insurer.',
    'instalment_paid' => 'This instalment is already paid. Do not pay again.',
    'instalment_in_progress' => 'A payment for this instalment is already in progress. Approve it on your phone or wait for it to finish before trying again.',
    'no_discharge' => 'There is no discharge waiting for your signature on this claim.',
    'payout_locked' => 'The payout details can no longer be changed: the payment has already been requested.',
    'payout_alert_title' => 'Payout details changed',
    'payout_alert_body' => 'The account that will receive the settlement of claim :claim was changed. If this was not you, contact us immediately.',
];

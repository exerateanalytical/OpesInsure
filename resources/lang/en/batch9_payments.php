<?php

declare(strict_types=1);

return [
    'retry_started' => 'We sent a new payment request to your phone. Please approve it to complete your payment.',
    'retry_available' => 'Your payment did not go through. You can try again (:remaining attempt(s) left); you will not be charged twice.',
    'retry_exhausted' => 'This payment has reached the limit of :max attempts. Please contact your broker or choose another payment method.',
    'payment_not_retryable' => 'Only a failed payment can be retried.',
    'already_paid' => 'This payment has already been completed. You will not be charged again.',
    'payment_in_progress' => 'Your payment is being processed. Please wait for the confirmation before trying again.',
    'obligation_already_covered' => 'This amount is already paid or a payment for it is in progress. You will not be charged twice.',
    'idempotency_key_reused' => 'This idempotency key was already used for another payment.',
    'off_platform_collection' => 'This payment is collected outside the app (:mode). Please pay using the instructions from your broker or insurer.',
];

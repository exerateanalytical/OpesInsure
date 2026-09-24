<?php

return [
    'stale_record' => 'This record was changed by someone else since you opened it. Reload it and review before trying again.',
    'precondition_required' => 'This update requires the If-Match header with the version you last loaded.',
    'authority_exceeded' => 'This action exceeds your approval authority. It must be referred to a user with a higher limit.',
    'payment_ok_issuance_failed' => 'Your payment was received, but the policy could not be issued yet. Do not pay again; we are completing issuance and will notify you.',
    'integration_unavailable' => 'A partner service is temporarily unavailable. Please try again shortly.',
    'duplicate_submission' => 'This request is already being processed.',
    'idempotency_key_invalid' => 'The Idempotency-Key header must be at most 255 characters.',
];

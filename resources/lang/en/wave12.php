<?php

return [
    'otp_invalid' => 'That code is invalid or has expired. Request a new one.',
    'otp_rate_limited' => 'Too many attempts. Try again later.',
    'refresh_invalid' => 'Your session has expired. Sign in again.',
    'payment_not_retryable' => 'Only a failed payment can be retried.',
    'delivery_otp_invalid' => 'That delivery code is invalid.',
    'delivery_address_locked' => 'This delivery can no longer be redirected — it is already with the courier.',
    'quote_expired' => 'This quote has expired. Start a new one to get fresh pricing.',
    'quote_not_resumable' => 'This quote can no longer be resumed.',
    'document_not_ready' => 'This document is not available for download yet.',
    'document_no_party' => 'We could not find a customer profile for your account.',
    'document_unsupported_type' => 'That file type is not supported.',
    'document_invalid_file' => 'The uploaded file could not be read.',
    'document_too_large' => 'That file is larger than the 20 MB limit.',
    'document_signature_mismatch' => 'The file content does not match its declared type.',
    'document_duplicate' => 'An identical document has already been uploaded.',
];

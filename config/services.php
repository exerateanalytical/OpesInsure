<?php
return [
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        // Distinct sender numbers: WhatsApp requires its own approved sender
        // (a "whatsapp:+..." address), separate from the plain SMS long code.
        'sms_from' => env('TWILIO_SMS_FROM'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],

    // clamd (ClamAV daemon) INSTREAM scanning — see
    // App\Application\Documents\Adapters\ClamAvMalwareScanAdapter. Blank
    // host until a real scanner is provisioned; MobileDocumentService's
    // bound MalwareScanAdapter falls back to FailClosedMalwareScanAdapter
    // whenever this is empty (see AppServiceProvider).
    'clamav' => [
        'host' => env('CLAMAV_HOST'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 10),
    ],
];

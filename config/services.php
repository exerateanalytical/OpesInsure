<?php
return [
    // Fallbacks for the admin "Platform settings" page (admin values win when set).
    'support' => [
        'email' => env('SUPPORT_EMAIL'),
        'phone' => env('SUPPORT_PHONE'),
        'whatsapp' => env('SUPPORT_WHATSAPP'),
        'partner_email' => env('PARTNER_EMAIL'),
    ],
    'etech' => [
        'sms_login' => env('ETECH_SMS_LOGIN'),
        'sms_password' => env('ETECH_SMS_PASSWORD'),
        'sms_sender' => env('ETECH_SMS_SENDER'),
        'rest_token' => env('ETECH_REST_TOKEN'),
        'whatsapp_template_name' => env('ETECH_WHATSAPP_TEMPLATE'),
        'whatsapp_template_language' => env('ETECH_WHATSAPP_TEMPLATE_LANGUAGE', 'fr'),
    ],
    'otp' => [
        'channel_priority' => env('OTP_CHANNEL_PRIORITY', 'whatsapp,sms'),
        'provider_priority' => env('OTP_PROVIDER_PRIORITY', 'etech,twilio'),
        // 'queue' (needs a queue worker) or 'after_response' (no worker needed).
        'delivery_mode' => env('OTP_DELIVERY_MODE', 'queue'),
    ],
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

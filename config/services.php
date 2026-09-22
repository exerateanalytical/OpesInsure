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
];

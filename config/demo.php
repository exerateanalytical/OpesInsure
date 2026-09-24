<?php

return [
    /*
     * Master switch for demo access. When false (the default, and what any
     * real production deployment should use) none of the demo affordances
     * below exist: no one-click admin sign-in, no fixed OTP, and the demo
     * account list is not rendered anywhere.
     */
    'enabled' => (bool) env('DEMO_MODE_ENABLED', false),

    /*
     * Fixed OTP issued to demo accounts only, and only while demo mode is on.
     * This exists because the mobile app authenticates by SMS OTP and no SMS
     * provider is configured yet, so a demo tester has no way to receive a
     * real code. It is applied at code-GENERATION time — the verification
     * path still hashes and compares exactly as it does for a real user, so
     * this adds no bypass to the authentication logic itself.
     */
    'otp' => (string) env('DEMO_OTP', '123456'),

    // Per-IP mobile OTP request ceiling per hour while demo mode is on.
    'otp_ip_limit_per_hour' => (int) env('DEMO_OTP_IP_LIMIT', 200),

    /*
     * Shared password for the seeded demo staff accounts on the web panel.
     */
    'password' => (string) env('DEMO_PASSWORD', 'OpesDemo!2026'),
];

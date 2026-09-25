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
     * REQ-SEC-002. demo:seed refuses when APP_ENV=production unless this is
     * explicitly true. Keeps production and demo data apart by default; a
     * production host that is deliberately a demo host must opt in here.
     */
    'allow_in_production' => (bool) env('DEMO_ALLOW_IN_PRODUCTION', false),

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
     * Shared password for every seeded demo account: web panel staff and the
     * mobile personas (POST auth/mobile/password-login). Re-applied to
     * existing demo users on every demo:seed, so a deploy keeps it current.
     */
    'password' => (string) env('DEMO_PASSWORD', env('APP_ENV') === 'local' ? 'Demo@12345' : ''),

    /*
     * Roles whose demo accounts get the fixed demo OTP and the shared demo
     * password re-applied on every seed. Admin/finance/compliance/claims
     * accounts are deliberately excluded: they never get 123456 and their
     * passwords are never reset by demo:seed.
     */
    'persona_roles' => ['CUSTOMER', 'AGENT', 'BROKER_STAFF', 'CARRIER_STAFF'],

    // Initial password for seeded admin accounts (read via config so config:cache works).
    'local_admin_password' => env('LOCAL_ADMIN_PASSWORD'),
];

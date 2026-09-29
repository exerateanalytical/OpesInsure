<?php

/**
 * S6 launch security sweep: every route with a mutating verb (POST/PUT/PATCH/DELETE) must carry a permission gate
 * (permission:/can: middleware) unless it is on this allow-list. Each allow-listed route names WHY it may skip the
 * middleware; the reason codes are defined in REASONS. A new unguarded mutating route fails this test until it gets
 * a permission middleware or a justified allow-list entry. Stale entries (route removed or now gated) also fail, so
 * the list cannot silently rot.
 */

use Illuminate\Support\Facades\Route;

const S6_REASONS = [
    'HORIZON' => 'Laravel Horizon dashboard API; guarded by Horizon::auth (viewHorizon gate, platform admins only).',
    'OAUTH' => 'Laravel Passport protocol endpoints (token grant/refresh, consent screen); authenticated by the OAuth protocol itself.',
    'LIVEWIRE' => 'Livewire transport for the Filament panels; every component action is authorised in the panel (WorkflowAction permissions).',
    'PANEL_LOGOUT' => 'Filament panel logout of the caller\'s own web session.',
    'PUBLIC_FORM' => 'Public website form (contact / account deletion request); anonymous by design, throttled, creates a request only.',
    'WEBHOOK' => 'Provider callback (payments / SMS delivery receipts); authenticated by provider signature or shared secret in the controller.',
    'PUBLIC_API' => 'Public verification or self-registration endpoint; anonymous by design, throttled, returns only public data.',
    'AUTH' => 'Authentication flow (OTP, password login/reset, refresh, logout) of the caller\'s own account.',
    'TELEMETRY' => 'Anonymous mobile crash/issue/telemetry intake; write-only, throttled, no record lookup.',
    'INTEGRATION_CLIENT' => 'Partner API; authenticated by integration.client (client credentials + scopes).',
    'SELF_ACCOUNT' => 'The caller\'s own account, device, settings, MFA or workspace; every row is keyed by auth()->id() / the caller\'s party.',
    'INVITATION_TOKEN' => 'Invitation acceptance; authorised by the single-use invitation token bound to the caller\'s identity.',
    'CUSTOMER_SELF' => 'Mobile customer/agent self-service; the controller resolves the record through the caller\'s own party / book (MobileCustomerContext, PartnerBook) and 404s otherwise.',
    'OWNERSHIP_SCOPED' => 'Shared staff/customer lifecycle route; the controller loads by tenant + OwnershipScope/PartnerBook (own party or own book) and checks the step permission in-line (e.g. quotes.manage, policies.service.approve).',
    'SUGGESTION' => 'Master-data suggestion; creates a pending suggestion for data-steward review, never changes master data.',
    'SIGNER_SELF' => 'E-signature sign/decline; the controller only lets the named signer act on their own signature request.',
    'CONTROLLER_GUARD' => 'Permission or ownership enforced in the controller/service (S6 audited: customers.manage, own party / tenant customer, platform admin, cases.str.view, carrier.authority.* + carrier scope, marketplace policy).',
];

const S6_UNGUARDED_ALLOW_LIST = [
        'DELETE api/v1/me/devices/{device}' => 'SELF_ACCOUNT',
        'DELETE api/v1/mobile/account/devices/{device}' => 'SELF_ACCOUNT',
        'DELETE api/v1/mobile/claims/drafts/{draft}' => 'CUSTOMER_SELF',
        'DELETE api/v1/mobile/quotes/{quote}' => 'CUSTOMER_SELF',
        'DELETE horizon/api/monitoring/{tag}' => 'HORIZON',
        'DELETE oauth/authorize' => 'OAUTH',
        'DELETE oauth/device/authorize' => 'OAUTH',
        'PATCH api/v1/me/settings' => 'SELF_ACCOUNT',
        'PATCH api/v1/mobile/account/customer-profile' => 'SELF_ACCOUNT',
        'PATCH api/v1/mobile/account/profile' => 'SELF_ACCOUNT',
        'PATCH api/v1/mobile/claims/drafts/{draft}' => 'CUSTOMER_SELF',
        'PATCH api/v1/mobile/kyc/profile' => 'CUSTOMER_SELF',
        'PATCH api/v1/quotes/{quote}' => 'OWNERSHIP_SCOPED',
        'POST account/delete' => 'PUBLIC_FORM',
        'POST admin/logout' => 'PANEL_LOGOUT',
        'POST api/v1/aml/str-reports' => 'CONTROLLER_GUARD',
        'POST api/v1/aml/str-reports/{str}/submit' => 'CONTROLLER_GUARD',
        'POST api/v1/auth/mobile/logout' => 'AUTH',
        'POST api/v1/auth/mobile/logout-all' => 'AUTH',
        'POST api/v1/auth/mobile/otp/request' => 'AUTH',
        'POST api/v1/auth/mobile/otp/verify' => 'AUTH',
        'POST api/v1/auth/mobile/password-login' => 'AUTH',
        'POST api/v1/auth/mobile/password/forgot' => 'AUTH',
        'POST api/v1/auth/mobile/password/reset' => 'AUTH',
        'POST api/v1/auth/mobile/refresh' => 'AUTH',
        'POST api/v1/carrier/delegated-authorities/{agreement}/check' => 'CONTROLLER_GUARD',
        'POST api/v1/customers' => 'CONTROLLER_GUARD',
        'POST api/v1/documents' => 'CONTROLLER_GUARD',
        'POST api/v1/documents/{document}/access' => 'CONTROLLER_GUARD',
        'POST api/v1/invitations/accept' => 'INVITATION_TOKEN',
        'POST api/v1/master-data/suggestions' => 'SUGGESTION',
        'POST api/v1/me/email/verification' => 'SELF_ACCOUNT',
        'POST api/v1/me/mfa/totp' => 'SELF_ACCOUNT',
        'POST api/v1/me/mfa/totp/{method}/confirm' => 'SELF_ACCOUNT',
        'POST api/v1/me/phone/verification' => 'SELF_ACCOUNT',
        'POST api/v1/me/phone/verification/confirm' => 'SELF_ACCOUNT',
        'POST api/v1/mobile/account/privacy-requests' => 'SELF_ACCOUNT',
        'POST api/v1/mobile/account/push-tokens' => 'SELF_ACCOUNT',
        'POST api/v1/mobile/assets' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/assets/{asset}/documents' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/assets/{asset}/scan' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/assets/{asset}/scan/{document}/confirm' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/claims' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/claims/drafts' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/claims/drafts/{draft}/submit' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/claims/emergency-assistance' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/claims/{claim}/appeals' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/claims/{claim}/evidence' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/claims/{claim}/inspection/reschedule' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/claims/{claim}/parties' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/claims/{claim}/settlement/decision' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/claims/{claim}/withdraw' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/complaints' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/deliveries/{delivery}/confirm' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/documents' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/documents/{document}/access' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/issue-reports' => 'TELEMETRY',
        'POST api/v1/mobile/kyc/documents' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/kyc/submission' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/master-data/review' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/notifications/read-all' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/notifications/{notification}/read' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/payments/{payment}/refunds' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/payments/{payment}/retry' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/policy-service-requests' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/policy-service-requests/{id}/messages' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/proposals/{proposal}/counteroffer/{answer}' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/quotes/{quote}/resume' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/runtime/crash-reports' => 'TELEMETRY',
        'POST api/v1/mobile/runtime/telemetry' => 'TELEMETRY',
        'POST api/v1/mobile/security/device-attestation/assess' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/security/device-attestation/nonce' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/security/step-up/request' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/security/step-up/verify' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/support/cases' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/support/cases/{case}/attachments' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/support/cases/{case}/escalate' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/support/cases/{case}/messages' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/sync/operations' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/uploads' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/uploads/{upload}/finalize' => 'CUSTOMER_SELF',
        'POST api/v1/mobile/vehicles/master-review' => 'CUSTOMER_SELF',
        'POST api/v1/partner/record-mappings' => 'INTEGRATION_CLIENT',
        'POST api/v1/payments' => 'OWNERSHIP_SCOPED',
        'POST api/v1/payments/{payment}/initiate' => 'OWNERSHIP_SCOPED',
        'POST api/v1/payments/{payment}/retry' => 'OWNERSHIP_SCOPED',
        'POST api/v1/policies/{policy}/renewal-quote' => 'OWNERSHIP_SCOPED',
        'POST api/v1/policies/{policy}/service-requests' => 'OWNERSHIP_SCOPED',
        'POST api/v1/policies/{policy}/transactions' => 'OWNERSHIP_SCOPED',
        'POST api/v1/policies/{policy}/transactions/{transaction}/payment' => 'OWNERSHIP_SCOPED',
        'POST api/v1/policies/{policy}/transactions/{transaction}/payment-intents' => 'OWNERSHIP_SCOPED',
        'POST api/v1/proposals' => 'OWNERSHIP_SCOPED',
        'POST api/v1/proposals/{proposal}/declarations' => 'OWNERSHIP_SCOPED',
        'POST api/v1/proposals/{proposal}/disclosure/submit' => 'OWNERSHIP_SCOPED',
        'POST api/v1/proposals/{proposal}/disclosures/attest' => 'OWNERSHIP_SCOPED',
        'POST api/v1/proposals/{proposal}/documents' => 'OWNERSHIP_SCOPED',
        'POST api/v1/proposals/{proposal}/resubmit' => 'OWNERSHIP_SCOPED',
        'POST api/v1/proposals/{proposal}/submit' => 'OWNERSHIP_SCOPED',
        'POST api/v1/proposals/{proposal}/terms' => 'OWNERSHIP_SCOPED',
        'POST api/v1/proposals/{proposal}/withdraw' => 'OWNERSHIP_SCOPED',
        'POST api/v1/public/accounts' => 'PUBLIC_API',
        'POST api/v1/public/certificates/verify' => 'PUBLIC_API',
        'POST api/v1/public/insurance/verify' => 'PUBLIC_API',
        'POST api/v1/public/verify' => 'PUBLIC_API',
        'POST api/v1/quote-comparisons' => 'OWNERSHIP_SCOPED',
        'POST api/v1/quotes' => 'OWNERSHIP_SCOPED',
        'POST api/v1/quotes/{quote}/cancel' => 'OWNERSHIP_SCOPED',
        'POST api/v1/quotes/{quote}/decline' => 'OWNERSHIP_SCOPED',
        'POST api/v1/quotes/{quote}/generate' => 'OWNERSHIP_SCOPED',
        'POST api/v1/quotes/{quote}/offers/{offer}/accept' => 'OWNERSHIP_SCOPED',
        'POST api/v1/quotes/{quote}/offers/{offer}/premium-overrides' => 'OWNERSHIP_SCOPED',
        'POST api/v1/quotes/{quote}/offers/{offer}/premium-overrides/{override}/apply' => 'OWNERSHIP_SCOPED',
        'POST api/v1/quotes/{quote}/offers/{offer}/premium-overrides/{override}/decision' => 'OWNERSHIP_SCOPED',
        'POST api/v1/quotes/{quote}/send' => 'OWNERSHIP_SCOPED',
        'POST api/v1/risk-assets' => 'OWNERSHIP_SCOPED',
        'POST api/v1/signature-requests/{request}/decline' => 'SIGNER_SELF',
        'POST api/v1/signature-requests/{request}/sign' => 'SIGNER_SELF',
        'POST api/v1/support-tickets' => 'OWNERSHIP_SCOPED',
        'POST api/v1/tenants' => 'CONTROLLER_GUARD',
        'POST api/v1/web-experiences/marketplace/comparisons' => 'OWNERSHIP_SCOPED',
        'POST api/v1/web-experiences/marketplace/publications' => 'CONTROLLER_GUARD',
        'POST api/v1/web-experiences/marketplace/publications/{publication}/approve' => 'CONTROLLER_GUARD',
        'POST api/v1/webhooks/etech/dlr' => 'WEBHOOK',
        'POST api/v1/webhooks/notifications/twilio/callback' => 'WEBHOOK',
        'POST api/v1/webhooks/payments/mtn-momo/callback' => 'WEBHOOK',
        'POST api/v1/webhooks/payments/orange-money/callback' => 'WEBHOOK',
        'POST api/v1/webhooks/payments/{provider}' => 'WEBHOOK',
        'POST broker/logout' => 'PANEL_LOGOUT',
        'POST contact' => 'PUBLIC_FORM',
        'POST developers/logout' => 'PANEL_LOGOUT',
        'POST horizon/api/batches/retry/{id}' => 'HORIZON',
        'POST horizon/api/jobs/retry/{id}' => 'HORIZON',
        'POST horizon/api/monitoring' => 'HORIZON',
        'POST insurer/logout' => 'PANEL_LOGOUT',
        'POST livewire/update' => 'LIVEWIRE',
        'POST livewire/upload-file' => 'LIVEWIRE',
        'POST oauth/authorize' => 'OAUTH',
        'POST oauth/device/authorize' => 'OAUTH',
        'POST oauth/device/code' => 'OAUTH',
        'POST oauth/token' => 'OAUTH',
        'POST oauth/token/refresh' => 'OAUTH',
        'POST provider/logout' => 'PANEL_LOGOUT',
        'PUT api/v1/communication-preferences' => 'CONTROLLER_GUARD',
        'PUT api/v1/me/password' => 'SELF_ACCOUNT',
        'PUT api/v1/mobile/account/consents' => 'SELF_ACCOUNT',
        'PUT api/v1/mobile/account/locale' => 'SELF_ACCOUNT',
        'PUT api/v1/mobile/account/notification-preferences' => 'SELF_ACCOUNT',
        'PUT api/v1/mobile/claims/{claim}/incident' => 'CUSTOMER_SELF',
        'PUT api/v1/mobile/deliveries/{delivery}/address' => 'CUSTOMER_SELF',
        'PUT api/v1/mobile/uploads/{upload}/chunks/{index}' => 'CUSTOMER_SELF',
        'PUT api/v1/proposals/{proposal}/cover-terms' => 'OWNERSHIP_SCOPED',
        'PUT api/v1/proposals/{proposal}/disclosure/answers' => 'OWNERSHIP_SCOPED',
        'PUT api/v1/proposals/{proposal}/disclosures' => 'OWNERSHIP_SCOPED',
        'PUT api/v1/web-experiences/{portal}/workspace' => 'SELF_ACCOUNT',
        'PUT|PATCH api/v1/risk-assets/{risk_asset}' => 'OWNERSHIP_SCOPED',
];

function s6UnguardedMutatingRoutes(): array
{
    $out = [];
    foreach (Route::getRoutes() as $route) {
        $methods = array_values(array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']));
        if ($methods === []) {
            continue;
        }
        $guarded = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && preg_match('/^(permission|can):/', $m));
        if (! $guarded) {
            $out[implode('|', $methods).' '.$route->uri()] = $route->getActionName();
        }
    }
    ksort($out);

    return $out;
}

it('has a permission middleware on every mutating route that is not allow-listed with a justification', function () {
    $missing = array_diff_key(s6UnguardedMutatingRoutes(), S6_UNGUARDED_ALLOW_LIST);

    expect($missing)->toBe([], "Unguarded mutating routes: add a permission middleware or a justified allow-list entry:\n".implode("\n", array_map(fn ($k, $v) => "$k => $v", array_keys($missing), $missing)));
});

it('keeps the allow-list free of stale entries and every entry justified', function () {
    $stale = array_diff_key(S6_UNGUARDED_ALLOW_LIST, s6UnguardedMutatingRoutes());
    expect(array_keys($stale))->toBe([], 'Remove stale allow-list entries (route gone or now gated).');
    expect(array_diff(array_unique(S6_UNGUARDED_ALLOW_LIST), array_keys(S6_REASONS)))->toBe([]);
});

it('never allow-lists a staff back-office mutation under a customer/self reason', function () {
    $staff = collect(S6_UNGUARDED_ALLOW_LIST)->filter(fn ($reason, $key) => in_array($reason, ['CUSTOMER_SELF', 'SELF_ACCOUNT', 'TELEMETRY', 'PUBLIC_API', 'PUBLIC_FORM'], true)
        && preg_match('#api/v1/(admin|platform|carrier|broker|finance|claims/|underwriting|catalogue|tariffs|rules)#', $key));
    expect($staff->keys()->all())->toBe([]);
});

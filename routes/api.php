<?php

use App\Interfaces\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Interfaces\Http\Controllers\Api\V1\Payments\MtnMomoCallbackController;
use App\Interfaces\Http\Controllers\Api\V1\Payments\OrangeMoneyCallbackController;
use App\Interfaces\Http\Controllers\Api\V1\Notifications\TwilioDeliveryReceiptController;
use App\Interfaces\Http\Controllers\Api\V1\Identity\MobileAuthController;
use App\Interfaces\Http\Controllers\Api\V1\Identity\EmailVerificationController;
use App\Interfaces\Http\Controllers\Api\V1\Notifications\EtechDeliveryReceiptController;
use App\Interfaces\Http\Controllers\Api\V1\Settings\SupportContactsController;
use App\Interfaces\Http\Controllers\Api\V1\Payments\MobilePurchaseController;
use App\Interfaces\Http\Controllers\Api\V1\Payments\MobilePaymentController;
use App\Interfaces\Http\Controllers\Api\V1\Policies\MobileWalletController;
use App\Interfaces\Http\Controllers\Api\V1\Logistics\MobileDeliveryController;
use App\Interfaces\Http\Controllers\Api\V1\Quotes\MobileQuoteController;
use App\Interfaces\Http\Controllers\Api\V1\QuoteController;
use App\Interfaces\Http\Controllers\Api\V1\SystemController;
use App\Interfaces\Http\Controllers\Api\V1\Runtime\MobileIssueReportController;
use App\Interfaces\Http\Controllers\Api\V1\Runtime\MobileRuntimeController;
use App\Interfaces\Http\Controllers\Api\V1\Security\MobileStepUpController;
use Illuminate\Support\Facades\Route;
use App\Interfaces\Http\Controllers\Api\V1\Tenancy\TenantController;
use App\Interfaces\Http\Controllers\Api\V1\Identity\AccountController;
use App\Interfaces\Http\Controllers\Api\V1\Customers\CustomerController;
use App\Interfaces\Http\Controllers\Api\V1\Customers\PartyController;
use App\Interfaces\Http\Controllers\Api\V1\Customers\ConsentController;
use App\Interfaces\Http\Controllers\Api\V1\Partners\PartnerController;
use App\Interfaces\Http\Controllers\Api\V1\Attribution\AttributionController;
use App\Interfaces\Http\Controllers\Api\V1\Catalogue\CatalogueController;
use App\Interfaces\Http\Controllers\Api\V1\Rating\TariffController;
use App\Interfaces\Http\Controllers\Api\V1\Risks\RiskAssetController;
use App\Interfaces\Http\Controllers\Api\V1\Quotes\QuoteLifecycleController;
use App\Interfaces\Http\Controllers\Api\V1\Underwriting\ProposalController;
use App\Interfaces\Http\Controllers\Api\V1\Underwriting\UnderwritingConfigurationController;
use App\Interfaces\Http\Controllers\Api\V1\Policies\PolicyController;
use App\Interfaces\Http\Controllers\Api\V1\Policies\RenewalController;
use App\Interfaces\Http\Controllers\Api\V1\Policies\PolicyConfigurationController;
use App\Interfaces\Http\Controllers\Api\V1\Payments\PaymentController;
use App\Interfaces\Http\Controllers\Api\V1\Payments\FinancialControlController;
use App\Interfaces\Http\Controllers\Api\V1\Ledger\LedgerController;
use App\Interfaces\Http\Controllers\Api\V1\Commissions\CommissionController;
use App\Interfaces\Http\Controllers\Api\V1\Reconciliation\ReconciliationController;
use App\Interfaces\Http\Controllers\Api\V1\Settlements\SettlementController;
use App\Interfaces\Http\Controllers\Api\V1\Documents\DocumentController;
use App\Interfaces\Http\Controllers\Api\V1\Documents\MobileDocumentController;
use App\Interfaces\Http\Controllers\Api\V1\Documents\MobileDocumentDownloadController;
use App\Interfaces\Http\Controllers\Api\V1\Certificates\CertificateController;
use App\Interfaces\Http\Controllers\Api\V1\Logistics\FulfilmentController;
use App\Interfaces\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Interfaces\Http\Controllers\Api\V1\Support\SupportController;
use App\Interfaces\Http\Controllers\Api\V1\Fraud\RiskAlertController;
use App\Interfaces\Http\Controllers\Api\V1\Compliance\ComplianceController;
use App\Interfaces\Http\Controllers\Api\V1\Reporting\InsuranceReportController;
use App\Interfaces\Http\Controllers\Api\V1\Configuration\RegulatoryConfigurationController;
use App\Interfaces\Http\Controllers\Api\V1\Integrations\IntegrationController;
use App\Interfaces\Http\Controllers\Api\V1\Partner\PartnerApiController;
use App\Interfaces\Http\Controllers\Api\V1\BrokerOperations\BrokerOperationsController;
use App\Interfaces\Http\Controllers\Api\V1\CarrierOperations\CarrierOperationsController;
use App\Interfaces\Http\Controllers\Api\V1\Identity\InvitationController;
use App\Interfaces\Http\Controllers\Api\V1\Identity\MembershipController;
use App\Interfaces\Http\Controllers\Api\V1\Identity\SecurityController;
use App\Interfaces\Http\Controllers\Api\V1\Tenancy\BranchController;
use App\Interfaces\Http\Controllers\Api\V1\Tenancy\TenantLifecycleController;

Route::prefix('v1')->group(function (): void {
    Route::get('public/capabilities', [SystemController::class, 'capabilities']);
    Route::post('public/accounts', [AccountController::class, 'register']);
    Route::get('public/support-contacts', SupportContactsController::class);
    Route::match(['get', 'post'], 'webhooks/etech/dlr', EtechDeliveryReceiptController::class)->name('notifications.etech.dlr');
    Route::get('email/verify/{user}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('signed')->name('email.verify');
    // Public runtime surface (CLAUDE_MERGE_GUIDE.md, Patch 7): cold-start
    // bootstrap and telemetry both intentionally run before any session
    // exists, so they live alongside public/capabilities rather than inside
    // the auth:api/tenant group — see MobileRuntimeController.
    Route::get('mobile/runtime/bootstrap', [MobileRuntimeController::class, 'bootstrap'])->middleware('throttle:60,1');
    Route::post('mobile/runtime/telemetry', [MobileRuntimeController::class, 'telemetry'])->middleware(['throttle:60,1', 'idempotency:mobile.runtime.telemetry']);
    // "Report a problem" — filed from wherever the user is in the app,
    // including pre-auth screens, so it lives alongside runtime/telemetry
    // rather than behind auth:api. See MobileIssueReportController.
    Route::post('mobile/issue-reports', [MobileIssueReportController::class, 'store'])->middleware('throttle:20,1');
    Route::post('webhooks/payments/{provider}', PaymentWebhookController::class)->middleware('throttle:120,1')->name('payments.webhook');
    Route::post('webhooks/payments/mtn-momo/callback', MtnMomoCallbackController::class)->middleware('throttle:120,1')->name('payments.mtn_momo.callback');
    Route::match(['get', 'post'], 'webhooks/payments/orange-money/callback', OrangeMoneyCallbackController::class)->middleware('throttle:120,1')->name('payments.orange_money.callback');
    Route::post('webhooks/notifications/twilio/callback', TwilioDeliveryReceiptController::class)->middleware('throttle:120,1')->name('notifications.twilio.callback');
    // Demo credential directory for the mobile sign-in screen. The route only
    // exists while demo mode is on, so a real deployment simply 404s here and
    // the app hides its demo affordance rather than showing dead accounts.
    if (config('demo.enabled')) {
        Route::get('public/demo-accounts', function () {
            return response()->json(['data' => [
                'otp' => (string) config('demo.otp'),
                // Shared password for these personas (POST auth/mobile/password-login),
                // served from config so the APK never hardcodes it.
                'password' => filled(config('demo.password')) ? (string) config('demo.password') : null,
                'accounts' => array_map(static fn (array $a) => [
                    'label' => $a['label'],
                    'full_name' => $a['name'],
                    'phone_e164' => $a['phone'],
                    'role_code' => $a['role_code'],
                ], \Database\Seeders\DemoMobileAccountSeeder::ACCOUNTS),
            ]]);
        })->middleware('throttle:30,1');
    }
    // REQ-DUP-015: one PublicVerificationService; public/verify is canonical, the two below are aliases.
    Route::post('public/verify', \App\Interfaces\Http\Controllers\Api\V1\Certificates\PublicVerifyController::class)->middleware('throttle:20,1');
    Route::post('public/certificates/verify', [CertificateController::class, 'verify'])->middleware('throttle:30,1');
    // Reference-only insurance check (no token): discloses validity, insurer and
    // product class only. Tighter limit than certificate verify to deter enumeration.
    Route::post('public/insurance/verify', \App\Interfaces\Http\Controllers\Api\V1\Certificates\PublicInsuranceVerifyController::class)->middleware('throttle:20,1');
    // The signed-URL target MobileDocumentService's LocalSignedUrlAdapter
    // points to. Outside auth:api/tenant/json.api on purpose: a valid,
    // unexpired signature (see the 'signed' middleware) IS the
    // authorization here, exactly like Laravel's own signed
    // email-verification links — see MobileDocumentDownloadController.
    Route::get('mobile/documents/{document}/download', MobileDocumentDownloadController::class)->middleware(['signed', 'throttle:60,1'])->name('mobile.documents.download');
    // Login endpoints are deliberately not rate limited (owner decision 2026-09-24);
    // the per-code 5-wrong-guesses lock in MobileAuthService still applies.
    // Per-phone limits in MobileAuthService stay the same.
    Route::post('auth/mobile/otp/request', [MobileAuthController::class, 'requestOtp']);
    Route::post('auth/mobile/otp/verify', [MobileAuthController::class, 'verifyOtp']);
    Route::post('auth/mobile/password-login', [MobileAuthController::class, 'passwordLogin']);
    Route::post('auth/mobile/password/forgot', [MobileAuthController::class, 'forgotPassword']);
    Route::post('auth/mobile/password/reset', [MobileAuthController::class, 'resetPassword']);
    Route::post('auth/mobile/refresh', [MobileAuthController::class, 'refresh']);
    // Not tenant-scoped: the whole point of session/logout is to work before
    // (session, to discover workspaces) or independently of (logout) any
    // single tenant selection — unlike every route in the group below.
    Route::middleware(['auth:api', 'json.api'])->group(function (): void {
        Route::get('auth/mobile/session', [MobileAuthController::class, 'session']);
        Route::post('auth/mobile/logout', [MobileAuthController::class, 'logout'])->middleware('throttle:20,1');
        Route::post('me/email/verification', [EmailVerificationController::class, 'send'])->middleware('throttle:5,60');
        Route::post('me/phone/verification', [AccountController::class, 'requestPhoneVerification']);
        Route::post('me/phone/verification/confirm', [AccountController::class, 'confirmPhoneVerification']);
    });
    Route::middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
        Route::get('me', [AccountController::class, 'me']);
        Route::put('me/password', [AccountController::class, 'changePassword'])->middleware('throttle:3,10');
        Route::post('me/mfa/totp', [SecurityController::class, 'beginTotp'])->middleware('throttle:3,10');
        Route::post('me/mfa/totp/{method}/confirm', [SecurityController::class, 'confirmTotp'])->middleware('throttle:5,10');
        Route::get('me/devices', [SecurityController::class, 'devices']);
        Route::delete('me/devices/{device}', [SecurityController::class, 'revokeDevice']);
        Route::post('invitations', [InvitationController::class, 'store'])->middleware('permission:identity.invite');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->middleware('permission:identity.invite');
        Route::post('memberships/{membership}/revoke', [MembershipController::class, 'revoke'])->middleware('permission:identity.roles.manage');
        Route::apiResource('branches', BranchController::class)->only(['index','store'])->middleware('permission:tenant.manage');
        Route::get('tenants/{tenant}', [TenantController::class, 'show']);
        Route::patch('tenants/{tenant}', [TenantController::class, 'update'])->middleware('permission:tenant.manage');
        Route::post('tenants/{tenant}/status', TenantLifecycleController::class)->middleware('permission:tenant.status.manage');
        Route::apiResource('parties', PartyController::class)->only(['index','store','show'])->middleware('permission:parties.manage');
        Route::apiResource('customers', CustomerController::class)->only(['index','store','show']);
        Route::post('customers/{customer}/status', [CustomerController::class, 'transition'])->middleware('permission:customers.manage');
        Route::post('consents', [ConsentController::class, 'store'])->middleware('permission:privacy.consent.manage');
        Route::post('consents/{consent}/withdraw', [ConsentController::class, 'withdraw'])->middleware('permission:privacy.consent.manage');
        Route::get('partners', [PartnerController::class, 'index'])->middleware('permission:partners.read');
        Route::get('partners/{partner}', [PartnerController::class, 'show'])->middleware('permission:partners.read');
        Route::post('partners', [PartnerController::class, 'store'])->middleware('permission:partners.manage');
        Route::post('partners/{partner}/status', [PartnerController::class, 'changeStatus'])->middleware('permission:partners.manage');
        Route::post('partners/{partner}/licences', [PartnerController::class, 'addLicence'])->middleware('permission:partner.licence.manage');
        Route::post('partners/{partner}/licences/{licence}/decision', [PartnerController::class, 'verifyLicence'])->middleware('permission:partner.licence.verify');
        Route::post('attributions', [AttributionController::class, 'store'])->middleware('permission:attribution.create');
        Route::get('attributions/{attribution}', [AttributionController::class, 'show'])->middleware('permission:attribution.read');
        Route::post('attributions/{attribution}/disputes', [AttributionController::class, 'dispute'])->middleware('permission:attribution.dispute');
        Route::post('attribution-disputes/{dispute}/resolution', [AttributionController::class, 'resolve'])->middleware('permission:attribution.resolve');
        Route::get('catalogue/lines', [CatalogueController::class, 'lines']);
        Route::post('catalogue/lines', [CatalogueController::class, 'storeLine'])->middleware('permission:catalogue.manage');
        Route::post('catalogue/lines/{line}/coverages', [CatalogueController::class, 'storeCoverage'])->middleware('permission:catalogue.manage');
        Route::post('catalogue/lines/{line}/exclusions', [CatalogueController::class, 'storeExclusion'])->middleware('permission:catalogue.manage');
        Route::get('catalogue/products', [CatalogueController::class, 'products']);
        Route::get('catalogue/products/{product}', [CatalogueController::class, 'showProduct']);
        Route::post('catalogue/products', [CatalogueController::class, 'storeProduct'])->middleware('permission:catalogue.manage');
        Route::post('catalogue/products/{product}/submit', [CatalogueController::class, 'submitProduct'])->middleware('permission:catalogue.manage');
        Route::post('catalogue/products/{product}/publish', [CatalogueController::class, 'publishProduct'])->middleware('permission:catalogue.publish');
        Route::post('tariffs', [TariffController::class, 'store'])->middleware('permission:tariff.manage');
        Route::post('tariffs/{tariff}/submit', [TariffController::class, 'submit'])->middleware('permission:tariff.manage');
        Route::post('tariffs/{tariff}/approve', [TariffController::class, 'approve'])->middleware('permission:tariff.approve');
        Route::apiResource('risk-assets', RiskAssetController::class)->only(['index','store','show','update']);
        Route::post('quotes', [QuoteController::class, 'store'])->middleware('throttle:60,1');
        Route::get('quotes/{quote}', [QuoteLifecycleController::class, 'show']);
        Route::post('quotes/{quote}/rate', [QuoteLifecycleController::class, 'rate'])->middleware(['permission:quotes.rate','throttle:30,1']);
        Route::post('quotes/{quote}/offers/{offer}/accept', [QuoteLifecycleController::class, 'accept']);
        Route::post('underwriting/disclosure-schemas', [UnderwritingConfigurationController::class, 'createDisclosure'])->middleware('permission:underwriting.configure');
        Route::post('underwriting/disclosure-schemas/{schema}/approve', [UnderwritingConfigurationController::class, 'approveDisclosure'])->middleware('permission:underwriting.configure.approve');
        Route::post('underwriting/document-requirements', [UnderwritingConfigurationController::class, 'createRequirement'])->middleware('permission:underwriting.configure');
        Route::post('underwriting/document-requirements/{requirement}/approve', [UnderwritingConfigurationController::class, 'approveRequirement'])->middleware('permission:underwriting.configure.approve');
        Route::post('proposals', [ProposalController::class, 'store']);
        Route::get('proposals/{proposal}', [ProposalController::class, 'show']);
        Route::put('proposals/{proposal}/disclosures', [ProposalController::class, 'answer']);
        Route::post('proposals/{proposal}/disclosures/attest', [ProposalController::class, 'attest']);
        Route::post('proposals/{proposal}/documents', [ProposalController::class, 'attachDocument']);
        Route::post('proposals/{proposal}/documents/{document}/review', [ProposalController::class, 'reviewDocument'])->middleware('permission:documents.review');
        Route::post('proposals/{proposal}/submit', [ProposalController::class, 'submit']);
        Route::post('underwriting/cases/{case}/assign', [ProposalController::class, 'assign'])->middleware('permission:underwriting.assign');
        Route::post('underwriting/referrals/{referral}/resolve', [ProposalController::class, 'resolveReferral'])->middleware('permission:underwriting.decide');
        Route::post('underwriting/cases/{case}/decision', [ProposalController::class, 'decide'])->middleware('permission:underwriting.decide');
        // Batch 7B — REQ-UW-001…005 underwriter workspace (queue, case file, system evaluate, review steps, WF-019 info request).
        Route::get('underwriting/cases', [\App\Application\Underwriting\Http\UnderwritingWorkspaceController::class, 'index'])->middleware('permission:underwriting.decide');
        Route::get('underwriting/cases/{case}', [\App\Application\Underwriting\Http\UnderwritingWorkspaceController::class, 'show'])->middleware('permission:underwriting.decide');
        Route::post('underwriting/cases/{case}/evaluate', [\App\Application\Underwriting\Http\UnderwritingWorkspaceController::class, 'evaluate'])->middleware(['permission:underwriting.decide', 'throttle:30,1']);
        Route::post('underwriting/cases/{case}/start-review', [\App\Application\Underwriting\Http\UnderwritingWorkspaceController::class, 'startReview'])->middleware('permission:underwriting.decide');
        Route::post('underwriting/cases/{case}/ready-for-decision', [\App\Application\Underwriting\Http\UnderwritingWorkspaceController::class, 'readyForDecision'])->middleware('permission:underwriting.decide');
        Route::post('underwriting/cases/{case}/information-requests', [\App\Application\Underwriting\Http\UnderwritingWorkspaceController::class, 'requestInformation'])->middleware('permission:underwriting.decide');
        Route::get('policies', [PolicyController::class, 'index']);
        Route::post('policy-issuance-requests', [PolicyController::class, 'requestIssuance'])->middleware('permission:policies.issue.request');
        Route::post('policy-issuance-requests/{issuance}/approve', [PolicyController::class, 'approveIssuance'])->middleware('permission:policies.issue.approve');
        Route::post('policy-issuance-requests/{issuance}/reject', [PolicyController::class, 'rejectIssuance'])->middleware('permission:policies.issue.approve');
        Route::get('policies/{policy}', [PolicyController::class, 'show']);
        Route::post('policies/{policy}/transactions', [PolicyController::class, 'requestService']);
        Route::post('policies/{policy}/transactions/{transaction}/payment-intents', [PolicyController::class, 'requestServicePayment'])->middleware('throttle:10,1');
        Route::post('policies/{policy}/transactions/{transaction}/payment', [PolicyController::class, 'linkServicePayment']);
        Route::post('policies/{policy}/transactions/{transaction}/approve', [PolicyController::class, 'approveService'])->middleware('permission:policies.service.approve');
        Route::post('policies/{policy}/transactions/{transaction}/reject', [PolicyController::class, 'rejectService'])->middleware('permission:policies.service.approve');
        Route::post('renewals/seed', [RenewalController::class, 'seed'])->middleware('permission:renewals.manage');
        Route::post('renewals/{renewal}/quote', [RenewalController::class, 'createQuote'])->middleware('permission:renewals.manage');
        Route::post('renewals/{renewal}/complete', [RenewalController::class, 'complete'])->middleware('permission:renewals.manage');
        Route::post('configuration/cancellation-rules', [PolicyConfigurationController::class, 'createCancellationRule'])->middleware('permission:policies.configuration.manage');
        Route::post('configuration/cancellation-rules/{rule}/approve', [PolicyConfigurationController::class, 'approveCancellationRule'])->middleware('permission:policies.configuration.approve');
        Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:20,1');
        Route::post('payments/{payment}/initiate', [PaymentController::class, 'initiate'])->middleware('throttle:10,1');
        Route::get('payments/{payment}', [PaymentController::class, 'show']);
        Route::post('payments/{payment}/refunds', [PaymentController::class, 'requestRefund'])->middleware('permission:refund.request');
        Route::post('refunds/{refund}/approve', [PaymentController::class, 'approveRefund'])->middleware('permission:refund.approve');
        Route::get('mobile/purchases/{proposal}/status', [MobilePurchaseController::class, 'status']);
        Route::get('mobile/payments', [MobilePaymentController::class, 'index']);
        Route::get('mobile/payments/{payment}', [MobilePaymentController::class, 'show']);
        Route::post('mobile/payments/{payment}/retry', [MobilePaymentController::class, 'retry'])->middleware('throttle:10,1');
        Route::get('mobile/payments/{payment}/receipt', [MobilePaymentController::class, 'receipt']);
        // PAYMENT_REFUND_REQUEST step-up (CLAUDE_MERGE_GUIDE.md, Patch 7):
        // retrofits a real elevated-grant check onto a mutating financial
        // endpoint that previously had none. COMMISSION_WITHDRAWAL and
        // CLAIM_SETTLEMENT_DECISION should adopt the identical
        // '->middleware('step-up:<PURPOSE>')' pattern once those endpoints
        // exist — see the batch report.
        Route::post('mobile/payments/{payment}/refunds', [MobilePaymentController::class, 'requestRefund'])->middleware(['step-up:PAYMENT_REFUND_REQUEST', 'throttle:10,1']);
        Route::get('mobile/wallet', [MobileWalletController::class, 'index']);
        Route::get('mobile/wallet/policies/{policy}', [MobileWalletController::class, 'show']);
        Route::get('policies/{policy}/certificate', [MobileWalletController::class, 'certificate']);
        Route::get('mobile/deliveries/{delivery}', [MobileDeliveryController::class, 'show']);
        Route::put('mobile/deliveries/{delivery}/address', [MobileDeliveryController::class, 'updateAddress']);
        Route::post('mobile/deliveries/{delivery}/confirm', [MobileDeliveryController::class, 'confirm'])->middleware('throttle:10,1');
        Route::get('mobile/quotes', [MobileQuoteController::class, 'index']);
        Route::get('mobile/quotes/{quote}', [MobileQuoteController::class, 'show']);
        Route::delete('mobile/quotes/{quote}', [MobileQuoteController::class, 'destroy']);
        Route::post('mobile/quotes/{quote}/resume', [MobileQuoteController::class, 'resume'])->middleware('throttle:20,1');
        Route::get('mobile/documents', [MobileDocumentController::class, 'index']);
        Route::get('mobile/documents/{document}', [MobileDocumentController::class, 'show']);
        Route::post('mobile/documents', [MobileDocumentController::class, 'upload'])->middleware('throttle:10,1');
        Route::post('mobile/documents/{document}/access', [MobileDocumentController::class, 'requestAccess'])->middleware('throttle:30,1');
        Route::post('payments/{payment}/chargebacks', [FinancialControlController::class, 'openChargeback'])->middleware('permission:chargeback.manage');
        Route::post('chargebacks/{chargeback}/resolve', [FinancialControlController::class, 'resolveChargeback'])->middleware('permission:chargeback.resolve');
        Route::get('ledger/accounts', [LedgerController::class, 'accounts'])->middleware('permission:ledger.read');
        Route::get('ledger/journals/{journal}', [LedgerController::class, 'journal'])->middleware('permission:ledger.read');
        Route::post('ledger/journals', [LedgerController::class, 'manual'])->middleware('permission:ledger.adjust');
        Route::post('ledger/journals/{journal}/reverse', [LedgerController::class, 'reverse'])->middleware('permission:ledger.reverse');
        // The legacy commission/settlement WRITE routes were removed: they wrote the
        // same tables as the Wave6 domain via raw queries, with an incompatible status
        // vocabulary. routes/wave6.php is now the only write path. These two reads stay
        // because Wave6 has no equivalent. See docs/design/FINANCIAL_DISTRIBUTION_LEGACY_PATHS.md
        Route::get('partners/{partner}/commission-balance', [CommissionController::class, 'balance'])->middleware('permission:commission.read');
        Route::post('reconciliation/imports', [ReconciliationController::class, 'import'])->middleware('permission:reconciliation.import');
        Route::get('reconciliation/imports/{import}', [ReconciliationController::class, 'show'])->middleware('permission:reconciliation.read');
        Route::post('reconciliation/items/{item}/resolve', [ReconciliationController::class, 'resolve'])->middleware('permission:reconciliation.resolve');
        Route::post('reconciliation/imports/{import}/approve', [ReconciliationController::class, 'approve'])->middleware('permission:reconciliation.approve');
        Route::get('settlements/{batch}', [SettlementController::class, 'show'])->middleware('permission:settlement.read');
        require __DIR__.'/wave7.php';
        require __DIR__.'/wave8.php';
        require __DIR__.'/wave9.php';
        require __DIR__.'/wave10.php';
        require __DIR__.'/wave11.php';
        require __DIR__.'/wave12.php';
        require __DIR__.'/wave12_kyc.php';
        require __DIR__.'/wave12_claims.php';
        require __DIR__.'/wave13_runtime.php';
        require __DIR__.'/wave12_agentmode.php';
        require __DIR__.'/wave12_brokercarrier.php';
        require __DIR__.'/wave14_mobile.php';
        require __DIR__.'/wave15_mobile.php';
        require __DIR__.'/wave16_partner.php';
        require __DIR__.'/wave16_lifecycle.php';
        Route::post('documents', [DocumentController::class, 'register']);
        Route::post('documents/{document}/review', [DocumentController::class, 'review'])->middleware('permission:documents.review');
        Route::post('documents/{document}/access', [DocumentController::class, 'access']);
        Route::post('certificates', [CertificateController::class, 'issue'])->middleware('permission:certificates.issue');
        Route::post('certificate-templates', [CertificateController::class, 'createTemplate'])->middleware('permission:certificates.templates.manage');
        Route::post('certificate-templates/{template}/approve', [CertificateController::class, 'approveTemplate'])->middleware('permission:certificates.templates.approve');
        Route::post('sticker-batches', [CertificateController::class, 'receiveBatch'])->middleware('permission:stickers.receive');
        Route::post('certificates/{certificate}/void', [CertificateController::class, 'void'])->middleware('permission:certificates.void');
        Route::post('fulfilment-orders', [FulfilmentController::class, 'store']);
        Route::post('fulfilment-orders/{order}/transitions', [FulfilmentController::class, 'transition'])->middleware('permission:fulfilment.transition');
        Route::put('communication-preferences', [NotificationController::class, 'preference']);
        // 'notifications' POST is now registered by wave8.php under permission:communications.manage,
        // which supersedes this with idempotency-key handling; removed here to avoid a duplicate
        // route registration with two different required permissions.
        Route::post('support-tickets', [SupportController::class, 'store']);
        Route::post('support-tickets/{ticket}/transitions', [SupportController::class, 'transition'])->middleware('permission:support.manage');
        Route::post('fraud-rules', [RiskAlertController::class, 'createRule'])->middleware('permission:fraud.rules.manage');
        Route::post('risk-alerts', [RiskAlertController::class, 'alert'])->middleware('permission:fraud.alert.create');
        Route::post('risk-alerts/{alert}/decision', [RiskAlertController::class, 'decide'])->middleware('permission:fraud.alert.decide');
        Route::post('compliance/privileged-access', [ComplianceController::class, 'grantAccess'])->middleware('permission:compliance.access.grant');
        Route::post('compliance/data-subject-requests', [ComplianceController::class, 'dataRequest']);
        Route::get('compliance/audit-log', [ComplianceController::class, 'audit'])->middleware('permission:audit.read');
        Route::get('reports/insurance-portfolio', [InsuranceReportController::class, 'portfolio'])->middleware('permission:reports.insurance.read');
        Route::get('reports/renewals', [InsuranceReportController::class, 'renewals'])->middleware('permission:reports.insurance.read');
        Route::get('configuration/regulatory-reference-sets/{code}', [RegulatoryConfigurationController::class, 'show']);
        Route::post('configuration/regulatory-reference-sets', [RegulatoryConfigurationController::class, 'store'])->middleware('permission:configuration.regulatory.manage');
        Route::post('configuration/regulatory-reference-sets/{set}/approve', [RegulatoryConfigurationController::class, 'approve'])->middleware('permission:configuration.regulatory.approve');
        Route::post('integrations/clients', [IntegrationController::class, 'createClient'])->middleware('permission:integrations.manage');
        Route::post('integrations/clients/{client}/advance', [IntegrationController::class, 'advance'])->middleware('permission:integrations.manage');
        Route::post('integrations/clients/{client}/suspend', [IntegrationController::class, 'suspend'])->middleware('permission:integrations.manage');
        Route::post('integrations/clients/{client}/reinstate', [IntegrationController::class, 'reinstate'])->middleware('permission:integrations.manage');
        Route::post('integrations/clients/{client}/revoke', [IntegrationController::class, 'revoke'])->middleware('permission:integrations.revoke');
        Route::post('integrations/clients/{client}/webhooks', [IntegrationController::class, 'subscribe'])->middleware('permission:integrations.manage');
        Route::post('integrations/delivery-attempts/{attempt}/replay', [IntegrationController::class, 'replayDeliveryAttempt'])->middleware('permission:integrations.manage');
        Route::get('integrations/health', [IntegrationController::class, 'health'])->middleware('permission:integrations.manage');
        // REQ-DUP-008: canonical is the `bordereaux` resource (routes/wave6.php + Batch 10-5 block); broker/carrier
        // bordereau routes are deprecated aliases mapping their legacy bodies onto the one BordereauService.
        Route::post('broker/bordereaux', [BrokerOperationsController::class, 'createBordereau'])->middleware(['permission:broker.bordereaux.manage', \App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('bordereaux', 'REQ-DUP-008')]);
        Route::post('broker/bordereaux/{bordereau}/submit', [BrokerOperationsController::class, 'submitBordereau'])->middleware(['permission:broker.bordereaux.submit', \App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('bordereaux/{bordereau}/submit', 'REQ-DUP-008')]);
        // REQ-DUP-010: canonical is renewals/seed (RenewalController). The broker variant is a
        // deprecated alias: its action only maps the legacy body (days_ahead) onto the same
        // RenewalService::sweep (which also keeps renewal_work_items); removal pending.
        Route::post('broker/renewals/seed', [BrokerOperationsController::class, 'seedRenewals'])->middleware(['permission:broker.renewals.manage', \App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('renewals/seed', 'REQ-DUP-010')]);
        Route::post('carrier/delegated-authorities', [CarrierOperationsController::class, 'createAuthority'])->middleware('permission:carrier.authority.manage');
        Route::post('carrier/delegated-authorities/{agreement}/approve', [CarrierOperationsController::class, 'approveAuthority'])->middleware('permission:carrier.authority.approve');
        Route::post('carrier/delegated-authorities/{agreement}/check', [CarrierOperationsController::class, 'checkAuthority']);
        Route::post('carrier/bordereaux/{bordereau}/decision', [CarrierOperationsController::class, 'acknowledgeBordereau'])->middleware(['permission:carrier.bordereaux.decide', \App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('bordereaux/{bordereau}/acknowledge', 'REQ-DUP-008')]);

        // Batch 13D — REQ-COI-001 co-insurance (apériteur + followers, share apportionment)
        Route::get('coinsurance/arrangements', [\App\Application\Coinsurance\Http\CoinsuranceController::class, 'index'])->middleware('permission:coinsurance.view');
        Route::post('coinsurance/arrangements', [\App\Application\Coinsurance\Http\CoinsuranceController::class, 'store'])->middleware('permission:coinsurance.manage');
        Route::get('coinsurance/arrangements/{arrangement}', [\App\Application\Coinsurance\Http\CoinsuranceController::class, 'show'])->middleware('permission:coinsurance.view')->whereUuid('arrangement');
        Route::post('coinsurance/arrangements/{arrangement}/activate', [\App\Application\Coinsurance\Http\CoinsuranceController::class, 'activate'])->middleware('permission:coinsurance.approve')->whereUuid('arrangement');
        Route::post('coinsurance/arrangements/{arrangement}/terminate', [\App\Application\Coinsurance\Http\CoinsuranceController::class, 'terminate'])->middleware('permission:coinsurance.approve')->whereUuid('arrangement');
        Route::post('coinsurance/arrangements/{arrangement}/preview', [\App\Application\Coinsurance\Http\CoinsuranceController::class, 'preview'])->middleware('permission:coinsurance.view')->whereUuid('arrangement');
        Route::post('coinsurance/arrangements/{arrangement}/apportionments', [\App\Application\Coinsurance\Http\CoinsuranceController::class, 'apportion'])->middleware('permission:coinsurance.apportion')->whereUuid('arrangement');
        Route::get('coinsurance/arrangements/{arrangement}/apportionments', [\App\Application\Coinsurance\Http\CoinsuranceController::class, 'apportionments'])->middleware('permission:coinsurance.view')->whereUuid('arrangement');
        // End Batch 13D
    });
    Route::middleware('auth:api')->group(function (): void {
        Route::post('invitations/accept', [InvitationController::class, 'accept']);
        Route::get('tenants', [TenantController::class, 'index']);
        Route::post('tenants', [TenantController::class, 'store'])->middleware('throttle:10,60');
    });

    // Partner-facing API: authenticated via a Passport client-credentials token
    // (POST /oauth/token, grant_type=client_credentials), not a human user, so
    // the stock auth:api middleware doesn't apply here — it requires a
    // resolvable user() and a client-credentials token deliberately has none.
    // AuthenticateIntegrationClient does its own Passport token validation via
    // Auth::guard('api')->client() and is the only auth gate for this group.
    Route::prefix('partner')->middleware(['json.api'])->group(function (): void {
        Route::get('whoami', [PartnerApiController::class, 'whoAmI'])->middleware('integration.client:*');
        Route::post('record-mappings', [PartnerApiController::class, 'mapRecord'])->middleware('integration.client:*');
        Route::get('record-mappings/{recordType}/{externalRecordId}', [PartnerApiController::class, 'showMapping'])->middleware('integration.client:*');
    });
});

require __DIR__.'/wave6.php';

// Batch 13A — REQ-PRV-001 / REQ-PRV-002 / REQ-PRV-004: provider master + network.
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $p = \App\Application\Providers\Http\ProviderController::class;
    $n = \App\Application\Providers\Http\ProviderNetworkController::class;
    Route::get('providers', [$p, 'index'])->middleware('permission:providers.view');
    Route::post('providers', [$p, 'store'])->middleware('permission:providers.manage');
    Route::get('providers/{provider}', [$p, 'show'])->middleware('permission:providers.view')->whereUuid('provider');
    Route::get('providers/{provider}/credentialing', [$p, 'history'])->middleware('permission:providers.view')->whereUuid('provider');
    Route::post('providers/{provider}/credentialing', [$p, 'transition'])->middleware('permission:providers.credential')->whereUuid('provider');
    Route::post('providers/{provider}/facilities', [$p, 'addFacility'])->middleware('permission:providers.manage')->whereUuid('provider');
    Route::post('provider-facilities/{facility}/services', [$p, 'addFacilityService'])->middleware('permission:providers.manage')->whereUuid('facility');
    Route::post('providers/{provider}/code-mappings', [$p, 'mapCode'])->middleware('permission:providers.manage')->whereUuid('provider');
    Route::post('providers/{provider}/relationships', [$p, 'relate'])->middleware('permission:providers.manage')->whereUuid('provider');
    Route::get('medical-services', [$n, 'services'])->middleware('permission:providers.view');
    Route::post('medical-services', [$n, 'addService'])->middleware('permission:providers.manage');
    Route::get('provider-networks', [$n, 'index'])->middleware('permission:provider_networks.view');
    Route::post('provider-networks', [$n, 'store'])->middleware('permission:provider_networks.manage');
    Route::get('provider-networks/{network}/members', [$n, 'members'])->middleware('permission:provider_networks.view')->whereUuid('network');
    Route::post('provider-networks/{network}/members', [$n, 'addMember'])->middleware('permission:provider_networks.manage')->whereUuid('network');
    Route::post('provider-network-memberships/{membership}/end', [$n, 'endMember'])->middleware('permission:provider_networks.manage')->whereUuid('membership');
    Route::post('provider-networks/{network}/contracts', [$n, 'addContract'])->middleware('permission:provider_networks.manage')->whereUuid('network');
    Route::post('provider-contracts/{contract}/tariffs', [$n, 'draftTariff'])->middleware('permission:provider_networks.manage')->whereUuid('contract');
    Route::get('provider-contracts/{contract}/price', [$n, 'price'])->middleware('permission:provider_networks.view')->whereUuid('contract');
    Route::post('provider-tariffs/{tariff}/approve', [$n, 'approveTariff'])->middleware('permission:provider_tariffs.approve')->whereUuid('tariff');
});

// Batch 13C — REQ-REI-001 treaties + REQ-REI-002 cessions / bordereaux.
Route::prefix('v1/reinsurance')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $c = \App\Application\Reinsurance\Http\ReinsuranceController::class;
    Route::get('reinsurers', [$c, 'reinsurers'])->middleware('permission:reinsurance.treaties.view');
    Route::post('reinsurers', [$c, 'createReinsurer'])->middleware('permission:reinsurance.reinsurers.manage');
    Route::post('reinsurers/{reinsurer}/status', [$c, 'reinsurerStatus'])->middleware('permission:reinsurance.reinsurers.manage');
    Route::get('treaties', [$c, 'treatiesIndex'])->middleware('permission:reinsurance.treaties.view');
    Route::post('treaties', [$c, 'createTreaty'])->middleware('permission:reinsurance.treaties.manage');
    Route::get('treaties/{treaty}', [$c, 'showTreaty'])->middleware('permission:reinsurance.treaties.view');
    Route::post('treaties/{treaty}/versions', [$c, 'addVersion'])->middleware('permission:reinsurance.treaties.manage');
    Route::post('treaty-versions/{version}/activate', [$c, 'activateVersion'])->middleware('permission:reinsurance.treaties.approve');
    Route::get('treaties/{treaty}/bordereau', [$c, 'bordereau'])->middleware('permission:reinsurance.cessions.view');
    Route::get('policies/{policy}/cessions', [$c, 'policyCessions'])->middleware('permission:reinsurance.cessions.view');
    Route::post('policies/{policy}/cessions/preview', [$c, 'previewCession'])->middleware('permission:reinsurance.cessions.view');
    Route::post('policies/{policy}/cessions', [$c, 'cede'])->middleware('permission:reinsurance.cessions.calculate');
});

// Batch 8-7 — REQ-POL-009 policy portfolio transfer (maker-checker, notice/consent) + portability export packs (ICE gaps 36, 42).
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $c = \App\Application\Policies\Portability\Http\PolicyPortabilityController::class;
    Route::post('policy-portfolio-transfers/preview', [$c, 'preview'])->middleware('permission:policies.portfolio_transfer.request');
    Route::post('policy-portfolio-transfers', [$c, 'store'])->middleware(['permission:policies.portfolio_transfer.request', 'throttle:10,1']);
    Route::get('policy-portfolio-transfers', [$c, 'index'])->middleware('permission:policies.portfolio_transfer.read');
    Route::get('policy-portfolio-transfers/{transfer}', [$c, 'show'])->middleware('permission:policies.portfolio_transfer.read')->whereUuid('transfer');
    Route::post('policy-portfolio-transfers/{transfer}/approve', [$c, 'approve'])->middleware('permission:policies.portfolio_transfer.approve')->whereUuid('transfer');
    Route::post('policy-portfolio-transfers/{transfer}/reject', [$c, 'reject'])->middleware('permission:policies.portfolio_transfer.approve')->whereUuid('transfer');
    Route::post('policy-portfolio-transfers/{transfer}/policies/{policy}/consent', [$c, 'consent'])->middleware('permission:policies.portfolio_transfer.request')->whereUuid(['transfer', 'policy']);
    Route::get('policies/{policy}/servicing-history', [$c, 'servicingHistory'])->middleware('permission:policies.portfolio_transfer.read')->whereUuid('policy');
    Route::post('policies/{policy}/portability-exports', [$c, 'export'])->middleware(['permission:policies.portability.export', 'throttle:10,1'])->whereUuid('policy');
    Route::get('policies/{policy}/portability-exports', [$c, 'exports'])->middleware('permission:policies.portability.export')->whereUuid('policy');
    Route::get('policy-portability-exports/{export}', [$c, 'pack'])->middleware('permission:policies.portability.export')->whereUuid('export');
    Route::get('policy-portability-exports/{export}/pdf', [$c, 'pdf'])->middleware('permission:policies.portability.export')->whereUuid('export');
});
// Batch 8-6 — REQ-PRD-011 life & special products (group master + members, fleet, open cover cargo, construction, agriculture, life surrender).
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $s = \App\Application\Policies\Special\Http\SpecialPolicyController::class;
    Route::post('policies/{policy}/special-profile', [$s, 'createProfile'])->middleware('permission:special_policies.manage')->whereUuid('policy');
    Route::get('policies/{policy}/special-profile', [$s, 'showProfile'])->middleware('permission:special_policies.view')->whereUuid('policy');
    Route::get('policies/{policy}/schedule', [$s, 'schedule'])->middleware('permission:special_policies.view')->whereUuid('policy');
    Route::post('policies/{policy}/schedule-items', [$s, 'addItem'])->middleware('permission:special_policies.schedule.manage')->whereUuid('policy');
    Route::post('policy-schedule-items/{item}/remove', [$s, 'removeItem'])->middleware('permission:special_policies.schedule.manage')->whereUuid('item');
    Route::get('policies/{policy}/cargo-declarations', [$s, 'declarations'])->middleware('permission:special_policies.view')->whereUuid('policy');
    Route::post('policies/{policy}/cargo-declarations', [$s, 'declare'])->middleware('permission:cargo_declarations.declare')->whereUuid('policy');
    Route::post('cargo-declarations/{declaration}/cancel', [$s, 'cancelDeclaration'])->middleware('permission:cargo_declarations.cancel')->whereUuid('declaration');
    Route::post('life/surrender-scales', [$s, 'createScale'])->middleware('permission:life_surrender.scales.manage');
    Route::post('life/surrender-scales/{scale}/activate', [$s, 'activateScale'])->middleware('permission:life_surrender.scales.approve')->whereUuid('scale');
    Route::post('policies/{policy}/surrender-quotes', [$s, 'surrenderQuote'])->middleware('permission:life_surrender.quote')->whereUuid('policy');
});
// End Batch 8-6
// Batch 8-9 — REQ-DOC-008 origin/evidence, REQ-DOC-009 access log/retention/legal hold/destruction, REQ-DOC-010 intake, REQ-DOC-012 e-signature.
Route::prefix('v1/document-governance')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $g = \App\Application\Documents\Http\DocumentGovernanceController::class;
    Route::get('third-party-evidence', [$g, 'thirdPartyEvidence'])->middleware('permission:documents.read');
    Route::get('intake', [$g, 'intakeIndex'])->middleware('permission:documents.intake.manage');
    Route::post('intake', [$g, 'intakeReceive'])->middleware('permission:documents.intake.manage');
    Route::post('intake/{item}/classify', [$g, 'intakeClassify'])->middleware('permission:documents.intake.manage')->whereUuid('item');
    Route::post('intake/{item}/reject', [$g, 'intakeReject'])->middleware('permission:documents.intake.manage')->whereUuid('item');
    Route::get('documents/{document}/access-log', [$g, 'accessLog'])->middleware('permission:documents.access_log.read')->whereUuid('document');
    Route::get('documents/{document}/retention', [$g, 'retentionStatus'])->middleware('permission:documents.retention.manage')->whereUuid('document');
    Route::post('documents/{document}/destruction-requests', [$g, 'destructionRequest'])->middleware('permission:documents.destruction.request')->whereUuid('document');
    Route::post('destruction-requests/{request}/decide', [$g, 'destructionDecide'])->middleware('permission:documents.destruction.approve')->whereUuid('request');
    Route::get('retention-schedules', [$g, 'retentionIndex'])->middleware('permission:documents.retention.manage');
    Route::post('retention-schedules', [$g, 'retentionDraft'])->middleware('permission:documents.retention.manage');
    Route::post('retention-schedules/{schedule}/approve', [$g, 'retentionApprove'])->middleware('permission:documents.retention.approve')->whereUuid('schedule');
    Route::get('legal-holds', [$g, 'holdIndex'])->middleware('permission:documents.legal_hold.manage');
    Route::post('legal-holds', [$g, 'holdPlace'])->middleware('permission:documents.legal_hold.manage');
    Route::post('legal-holds/{hold}/release', [$g, 'holdRelease'])->middleware('permission:documents.legal_hold.manage')->whereUuid('hold');
    Route::post('signature-requests', [$g, 'signatureCreate'])->middleware('permission:documents.signatures.manage');
    Route::get('signature-requests/{request}', [$g, 'signatureShow'])->middleware('permission:documents.signatures.manage')->whereUuid('request');
    Route::post('signature-requests/{request}/cancel', [$g, 'signatureCancel'])->middleware('permission:documents.signatures.manage')->whereUuid('request');
});
Route::prefix('v1/signature-requests')->middleware(['auth:api', 'json.api', 'throttle:30,1'])->group(function (): void {
    $g = \App\Application\Documents\Http\DocumentGovernanceController::class;
    Route::post('{request}/sign', [$g, 'sign'])->whereUuid('request');
    Route::post('{request}/decline', [$g, 'decline'])->whereUuid('request');
});
// Batch 9-8 — REQ-PAY-015 account statements (customer / broker / agent / carrier), derived, read-only; ?format=pdf for PDF.
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $st = \App\Application\Finance\Statements\Http\AccountStatementController::class;
    Route::get('finance/statements/{subjectType}/{subject}', [$st, 'show'])->middleware('permission:statements.read')->whereIn('subjectType', ['customer', 'broker', 'agent', 'carrier'])->whereUuid('subject');
    Route::get('finance/partner-statements/{statement}', [$st, 'partnerStatement'])->middleware('permission:statements.read')->whereUuid('statement');
    Route::get('mobile/statements', [$st, 'mine'])->middleware('throttle:30,1');
});
// End Batch 9-8
// Batch 9-7 — REQ-PAY-010 cashier sessions, REQ-PAY-013 multi-currency & immutable FX rates.
Route::prefix('v1/finance')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $c = \App\Application\Finance\Cashier\Http\CashierSessionController::class;
    Route::get('cashier-sessions', [$c, 'index'])->middleware('permission:cashier.sessions.view');
    Route::post('cashier-sessions', [$c, 'open'])->middleware('permission:cashier.sessions.operate');
    Route::get('cashier-sessions/{session}', [$c, 'show'])->middleware('permission:cashier.sessions.view')->whereUuid('session');
    Route::post('cashier-sessions/{session}/collections', [$c, 'collect'])->middleware('permission:cashier.sessions.operate')->whereUuid('session');
    Route::post('cashier-sessions/{session}/close', [$c, 'close'])->middleware('permission:cashier.sessions.operate')->whereUuid('session');
    Route::post('cashier-sessions/{session}/decide', [$c, 'decide'])->middleware('permission:cashier.sessions.approve')->whereUuid('session');
    $f = \App\Application\Finance\Fx\Http\FxRateController::class;
    Route::get('fx-rates', [$f, 'index'])->middleware('permission:fx.rates.view');
    Route::post('fx-rates', [$f, 'store'])->middleware('permission:fx.rates.manage');
    Route::get('fx-rates/lookup', [$f, 'lookup'])->middleware('permission:fx.rates.view');
});
// End Batch 9-7
// Batch 9-5 — REQ-PAY-007 reconciliation exceptions / unmatched-items workspace (manual match under maker-checker).
Route::prefix('v1/reconciliation')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $w = \App\Application\Reconciliation\Http\ReconciliationWorkspaceController::class;
    Route::get('workspace/items', [$w, 'search'])->middleware('permission:reconciliation.read');
    Route::get('workspace/items/{item}/candidates', [$w, 'candidates'])->middleware('permission:reconciliation.read')->whereUuid('item');
    Route::post('items/{item}/manual-matches', [$w, 'requestMatch'])->middleware('permission:reconciliation.resolve')->whereUuid('item');
    Route::post('manual-matches/{match}/decide', [$w, 'decideMatch'])->middleware('permission:reconciliation.approve')->whereUuid('match');
});
// End Batch 9-5
// Batch 9-6 — REQ-PAY-009 refund engine / queue (WF-063) and REQ-PAY-011 mobile-money clearing + suspense.
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $rf = \App\Application\Finance\Refunds\Http\RefundQueueController::class;
    Route::get('refunds', [$rf, 'index'])->middleware('permission:refund.view');
    Route::get('refunds/{refund}', [$rf, 'show'])->middleware('permission:refund.view')->whereUuid('refund');
    Route::post('payments/{payment}/refund-candidates', [$rf, 'candidate'])->middleware('permission:refund.request')->whereUuid('payment');
    Route::post('refunds/{refund}/calculate', [$rf, 'calculate'])->middleware('permission:refund.request')->whereUuid('refund');
    Route::post('refunds/{refund}/review', [$rf, 'review'])->middleware('permission:refund.review')->whereUuid('refund');
    Route::post('refunds/{refund}/reject', [$rf, 'reject'])->middleware('permission:refund.approve')->whereUuid('refund');
    Route::post('refunds/{refund}/pay', [$rf, 'pay'])->middleware('permission:refund.pay')->whereUuid('refund');
    Route::post('refunds/{refund}/reconcile', [$rf, 'reconcile'])->middleware('permission:refund.reconcile')->whereUuid('refund');
    $cl = \App\Application\Finance\Clearing\Http\ClearingController::class;
    Route::get('clearing/suspense', [$cl, 'suspense'])->middleware('permission:clearing.view');
    Route::get('clearing/batches', [$cl, 'index'])->middleware('permission:clearing.view');
    Route::post('clearing/batches', [$cl, 'store'])->middleware('permission:clearing.manage');
    Route::get('clearing/batches/{batch}', [$cl, 'show'])->middleware('permission:clearing.view')->whereUuid('batch');
    Route::post('clearing/batches/{batch}/items', [$cl, 'attach'])->middleware('permission:clearing.manage')->whereUuid('batch');
    Route::post('clearing/batches/{batch}/settle', [$cl, 'settle'])->middleware('permission:clearing.manage')->whereUuid('batch');
    Route::post('clearing/batches/{batch}/reconcile', [$cl, 'reconcile'])->middleware('permission:clearing.reconcile')->whereUuid('batch');
});
// End Batch 9-6

// Agent W1 — Batch 8 wiring: cancellation (REQ-CAN-001, BRK-062 approver queue), suspension/reinstatement (REQ-POL-006),
// premium recovery (REQ-POL-010) and staff triage of customer service requests (REQ-DUP-014; conversion goes through
// POST policies/{policy}/transactions with service_request_id).
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $cn = \App\Application\Policies\Http\PolicyCancellationController::class;
    Route::post('policies/{policy}/cancellations/preview', [$cn, 'preview'])->middleware('permission:policies.cancellation.request')->whereUuid('policy');
    Route::post('policies/{policy}/cancellations', [$cn, 'store'])->middleware('permission:policies.cancellation.request')->whereUuid('policy');
    Route::get('policy-cancellations', [$cn, 'index'])->middleware('permission:policies.cancellation.review');
    Route::get('policy-cancellations/{cancellation}', [$cn, 'show'])->middleware('permission:policies.cancellation.review')->whereUuid('cancellation');
    Route::post('policy-cancellations/{cancellation}/review', [$cn, 'review'])->middleware('permission:policies.cancellation.review')->whereUuid('cancellation');
    Route::post('policy-cancellations/{cancellation}/approve', [$cn, 'approve'])->middleware('permission:policies.cancellation.approve')->whereUuid('cancellation');
    Route::post('policy-cancellations/{cancellation}/reject', [$cn, 'reject'])->middleware('permission:policies.cancellation.approve')->whereUuid('cancellation');

    $su = \App\Application\Policies\Http\PolicySuspensionController::class;
    Route::post('policies/{policy}/suspend', [$su, 'suspend'])->middleware('permission:policies.suspend')->whereUuid('policy');
    Route::post('policies/{policy}/reinstatement-requests', [$su, 'requestReinstatement'])->middleware('permission:policies.reinstatement.request')->whereUuid('policy');
    Route::post('policies/{policy}/reinstatement-requests/reject', [$su, 'rejectReinstatement'])->middleware('permission:policies.reinstatement.approve')->whereUuid('policy');
    Route::post('policies/{policy}/reinstate', [$su, 'reinstate'])->middleware('permission:policies.reinstatement.approve')->whereUuid('policy');
    Route::get('policy-reinstatement-queue', [$su, 'queue'])->middleware('permission:policies.reinstatement.approve');

    $rc = \App\Application\Policies\Http\PolicyRecoveryController::class;
    Route::post('policies/{policy}/recovery-cases', [$rc, 'open'])->middleware('permission:policy.recovery.request')->whereUuid('policy');
    Route::get('policy-recovery-cases', [$rc, 'index'])->middleware('permission:policy.recovery.approve');
    Route::get('policy-recovery-cases/{case}', [$rc, 'show'])->middleware('permission:policy.recovery.request')->whereUuid('case');
    Route::post('policy-recovery-cases/{case}/approve', [$rc, 'approve'])->middleware('permission:policy.recovery.approve')->whereUuid('case');
    Route::post('policy-recovery-cases/{case}/reject', [$rc, 'reject'])->middleware('permission:policy.recovery.approve')->whereUuid('case');
    Route::post('policy-premium-instalments/{instalment}/settle', [$rc, 'settleInstalment'])->middleware('permission:policy.recovery.request')->whereUuid('instalment');
    Route::post('policy-premium-instalments/{instalment}/waive', [$rc, 'waiveInstalment'])->middleware('permission:policy.premium.waive')->whereUuid('instalment');

    Route::get('policy-service-requests', [\App\Application\Policies\Http\ServiceRequestTriageController::class, 'index'])->middleware('permission:policies.service.approve');
});
// Batch 9-2 — REQ-PAY-004 payment allocations + versioned allocation rule, REQ-PAY-005 premium components/status.
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $a = \App\Application\Finance\Allocations\Http\AllocationController::class;
    $p = \App\Application\Finance\PremiumStatus\Http\PremiumStatusController::class;
    Route::get('payments/{payment}/allocations', [$a, 'show'])->middleware('permission:payments.allocations.read')->whereUuid('payment');
    Route::post('payments/{payment}/allocations', [$a, 'allocate'])->middleware('permission:payments.allocations.manage')->whereUuid('payment');
    Route::post('payment-allocation-runs/{run}/reverse', [$a, 'reverse'])->middleware('permission:payments.allocations.reverse')->whereUuid('run');
    Route::get('finance/allocation-rule', [$a, 'rule'])->middleware('permission:payments.allocations.read');
    Route::post('finance/allocation-rule', [$a, 'publishRule'])->middleware('permission:finance.allocation_rules.manage');
    Route::get('policies/{policy}/premium-status', [$p, 'show'])->middleware('permission:premium_status.read')->whereUuid('policy');
    Route::post('policies/{policy}/premium-components', [$p, 'record'])->middleware('permission:premium_components.manage')->whereUuid('policy');
    Route::post('premium-components/{component}/close', [$p, 'close'])->middleware('permission:premium_components.close')->whereUuid('component');
});
// End Batch 9-2
// Batch 9-4 — REQ-PAY-008 failed-payment retry under the same intent/obligation, REQ-PAY-014 payment collection mode.
Route::prefix('v1/payments')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $m = \App\Application\Payments\ExecutionModes\Http\PaymentModesController::class;
    Route::post('{payment}/retry', [$m, 'retry'])->middleware('throttle:10,1')->whereUuid('payment');
    Route::get('{payment}/attempts', [$m, 'attempts'])->whereUuid('payment');
    Route::get('{payment}/collection-mode', [$m, 'collectionMode'])->whereUuid('payment');
});
// End Batch 9-4
// Batch 9-1 — REQ-OBL-001 financial obligations (money chain) + REQ-PAY-006 instalment schedules.
Route::prefix('v1/finance')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $o = \App\Application\Finance\Obligations\Http\ObligationController::class;
    Route::get('obligations', [$o, 'index'])->middleware('permission:finance.obligations.view');
    Route::get('obligations/aging', [$o, 'aging'])->middleware('permission:finance.obligations.view');
    Route::get('obligations/{obligation}', [$o, 'show'])->middleware('permission:finance.obligations.view')->whereUuid('obligation');
    Route::post('obligations/{obligation}/write-off', [$o, 'writeOff'])->middleware('permission:finance.obligations.manage')->whereUuid('obligation');
    Route::post('obligations/{obligation}/cancel', [$o, 'cancel'])->middleware('permission:finance.obligations.manage')->whereUuid('obligation');
    Route::get('policies/{policy}/instalments', [$o, 'policyInstalments'])->middleware('permission:finance.obligations.view')->whereUuid('policy');
});
// End Batch 9-1

// Batch 10-2 — REQ-COM-002 commission rules: resolver preview + rule components (tiers, splits).
Route::prefix('v1/financial-distribution')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $c = \App\Application\Commissions\Rules\Http\CommissionRuleController::class;
    Route::get('commission-rules/resolve', [$c, 'resolve'])->middleware('permission:commission.manage');
    Route::get('commission-rules/{rule}', [$c, 'show'])->middleware('permission:commission.manage')->whereUuid('rule');
});
// End Batch 10-2
// Batch 10-3 — REQ-COM-003 commission statements: generation, adjustments (maker-checker), disputes (case engine).
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $c = \App\Application\Commissions\Statements\Http\CommissionStatementController::class;
    Route::post('commission-statements/generate', [$c, 'generate'])->middleware('permission:statements.prepare');
    Route::post('partner-statements/{statement}/adjustments', [$c, 'propose'])->middleware('permission:commission.statements.adjust')->whereUuid('statement');
    Route::post('partner-statement-adjustments/{item}/approve', [$c, 'approve'])->middleware('permission:commission.statements.adjustments.approve')->whereUuid('item');
    Route::post('partner-statement-adjustments/{item}/reject', [$c, 'reject'])->middleware('permission:commission.statements.adjustments.approve')->whereUuid('item');
    Route::post('partner-statements/{statement}/dispute', [$c, 'dispute'])->middleware('permission:commission.statements.dispute')->whereUuid('statement');
    Route::post('partner-statements/{statement}/resolve-dispute', [$c, 'resolve'])->middleware('permission:commission.statements.dispute.resolve')->whereUuid('statement');
});
// End Batch 10-3
// Batch 10-5 — REQ-DUP-008 one `bordereaux` resource (reads; writes are in routes/wave6.php).
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $f = \App\Interfaces\Http\Controllers\Api\V1\FinancialDistribution\FinancialDistributionController::class;
    Route::get('bordereaux', [$f, 'bordereaux'])->middleware('permission:bordereaux.view');
    Route::get('bordereaux/{bordereau}', [$f, 'showBordereau'])->middleware('permission:bordereaux.view')->whereUuid('bordereau');
});
// End Batch 10-5
// Batch 10-9 — REQ-ACC-004 technical accounting (written/earned/UPR, claims paid/outstanding/incurred, imported IBNR/life values).
Route::prefix('v1/finance/technical')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $t = \App\Application\Ledger\Technical\Http\TechnicalAccountingController::class;
    Route::get('reports/{report}', [$t, 'report'])->middleware('permission:technical_accounting.read')->where('report', 'premiums|claims|summary');
    Route::get('actuarial-imports', [$t, 'listImports'])->middleware('permission:technical_accounting.read');
    Route::get('actuarial-imports/{import}', [$t, 'showImport'])->middleware('permission:technical_accounting.read')->whereUuid('import');
    Route::post('actuarial-imports', [$t, 'storeImport'])->middleware('permission:technical_accounting.actuarial.import');
    Route::post('actuarial-imports/{import}/approve', [$t, 'approveImport'])->middleware('permission:technical_accounting.actuarial.approve')->whereUuid('import');
    Route::post('actuarial-imports/{import}/reject', [$t, 'rejectImport'])->middleware('permission:technical_accounting.actuarial.approve')->whereUuid('import');
    Route::post('upr-postings', [$t, 'postUpr'])->middleware('permission:technical_accounting.upr.post');
});
// End Batch 10-9
// Batch 10-4 — REQ-STL-001 ledger-calculated broker–insurer settlement lifecycle; REQ-DUP-011 one settlement read:
// GET carrier-settlements/{batch} and GET broker-settlements/{batch} are aliases of GET settlements/{batch}
// (same SettlementService + SettlementResource). POST settlements/* stays retired (legacy write path).
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $s = \App\Application\Settlements\Http\SettlementLifecycleController::class;
    Route::get('carrier-settlements/{batch}', [SettlementController::class, 'show'])->middleware(['permission:settlement.read', \App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('settlements/{batch}', 'REQ-DUP-011')])->whereUuid('batch');
    Route::get('broker-settlements/{batch}', [SettlementController::class, 'show'])->middleware(['permission:settlement.read', \App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('settlements/{batch}', 'REQ-DUP-011')])->whereUuid('batch');
    Route::post('broker-settlements', [$s, 'store'])->middleware('permission:settlement.prepare');
    Route::post('broker-settlements/{batch}/calculate', [$s, 'calculate'])->middleware('permission:settlement.prepare')->whereUuid('batch');
    Route::post('broker-settlements/{batch}/review', [$s, 'review'])->middleware('permission:settlement.prepare')->whereUuid('batch');
    Route::post('broker-settlements/{batch}/cancel', [$s, 'cancel'])->middleware('permission:settlement.prepare')->whereUuid('batch');
    Route::post('broker-settlements/{batch}/approve', [$s, 'approve'])->middleware('permission:settlement.approve')->whereUuid('batch');
    Route::post('broker-settlements/{batch}/reject', [$s, 'reject'])->middleware('permission:settlement.approve')->whereUuid('batch');
    Route::post('broker-settlements/{batch}/process', [$s, 'process'])->middleware('permission:settlement.submit')->whereUuid('batch');
    Route::post('broker-settlements/{batch}/fail', [$s, 'fail'])->middleware('permission:settlement.confirm')->whereUuid('batch');
    Route::post('broker-settlements/{batch}/settle', [$s, 'settle'])->middleware('permission:settlement.confirm')->whereUuid('batch');
    Route::post('broker-settlements/{batch}/reconcile', [$s, 'reconcile'])->middleware('permission:settlement.reconcile')->whereUuid('batch');
});
// End Batch 10-4
// Agent 10-10 — REQ-ACC-005 finance exception centre (FIN-006) and finance reports registry (FIN-024), read-only.
Route::prefix('v1/finance')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('exception-centre', [\App\Application\Finance\ExceptionCentre\Http\FinanceExceptionCentreController::class, 'index'])->middleware('permission:finance.exceptions.view');
    $fr = \App\Application\Finance\Reports\Http\FinanceReportController::class;
    Route::get('reports', [$fr, 'index'])->middleware('permission:finance.reports.view');
    Route::get('reports/{report}', [$fr, 'show'])->middleware('permission:finance.reports.view')->where('report', '[Ff][Rr]-[0-9]{2}');
});
// End Agent 10-10
// Batch 10-7 — REQ-ACC-002 manual journal lifecycle (maker-checker) + trial balance.
Route::prefix('v1/ledger')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $m = \App\Interfaces\Http\Controllers\Api\V1\Ledger\ManualJournalController::class;
    Route::post('manual-journals', [$m, 'store'])->middleware('permission:ledger.adjust');
    Route::post('manual-journals/{journal}/validate', [$m, 'validateJournal'])->middleware('permission:ledger.adjust')->whereUuid('journal');
    Route::post('manual-journals/{journal}/approve', [$m, 'approve'])->middleware('permission:ledger.approve')->whereUuid('journal');
    Route::post('manual-journals/{journal}/reject', [$m, 'reject'])->middleware('permission:ledger.approve')->whereUuid('journal');
    Route::post('manual-journals/{journal}/post', [$m, 'post'])->middleware('permission:ledger.post')->whereUuid('journal');
    Route::post('manual-journals/{journal}/reverse', [$m, 'reverse'])->middleware('permission:ledger.reverse')->whereUuid('journal');
    Route::get('trial-balance', [$m, 'trialBalance'])->middleware('permission:ledger.read');
});
// End Batch 10-7
// Batch 10-1 — REQ-COM-001 commission machine (App\Application\Commissions\Machine).
Route::prefix('v1/commissions/accruals')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $c = \App\Application\Commissions\Machine\Http\CommissionLifecycleController::class;
    Route::get('{accrual}', [$c, 'show'])->middleware('permission:commission.read')->whereUuid('accrual');
    Route::post('{accrual}/earn', [$c, 'earn'])->middleware('permission:commission.vest')->whereUuid('accrual');
    Route::post('{accrual}/approve', [$c, 'approve'])->middleware('permission:commission.approve')->whereUuid('accrual');
    Route::post('{accrual}/make-payable', [$c, 'makePayable'])->middleware('permission:commission.vest')->whereUuid('accrual');
    Route::post('{accrual}/adjust', [$c, 'adjust'])->middleware('permission:commission.manage')->whereUuid('accrual');
    Route::post('{accrual}/dispute', [$c, 'dispute'])->middleware('permission:commission.manage')->whereUuid('accrual');
    Route::post('{accrual}/resolve-dispute', [$c, 'resolveDispute'])->middleware('permission:commission.approve')->whereUuid('accrual');
    Route::post('{accrual}/reverse', [$c, 'reverse'])->middleware('permission:commission.clawback')->whereUuid('accrual');
});
// End Batch 10-1
// Agent C14 — REQ-CLM-014 claim closure checklist, closure reasons, reopening (maker-checker) (App\Application\Claims\Closure).
Route::prefix('v1/claims')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $c = \App\Application\Claims\Closure\Http\ClaimClosureController::class;
    Route::get('{claim}/closure/checklist', [$c, 'checklist'])->middleware('permission:claims.read')->whereUuid('claim');
    Route::get('{claim}/closure/history', [$c, 'history'])->middleware('permission:claims.read')->whereUuid('claim');
    Route::post('{claim}/close', [$c, 'close'])->middleware('permission:claims.close')->whereUuid('claim');
    Route::post('{claim}/reopen-requests', [$c, 'requestReopen'])->middleware('permission:claims.reopen.request')->whereUuid('claim');
    Route::post('reopen-requests/{request}/approve', [$c, 'approveReopen'])->middleware('permission:claims.reopen.approve')->whereUuid('request');
    Route::post('reopen-requests/{request}/reject', [$c, 'rejectReopen'])->middleware('permission:claims.reopen.approve')->whereUuid('request');
    Route::post('recoveries/{recovery}/transfer', [$c, 'transferRecovery'])->middleware('permission:claims.close')->whereUuid('recovery');
});
// End Agent C14

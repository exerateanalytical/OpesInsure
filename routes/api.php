<?php

use App\Interfaces\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Interfaces\Http\Controllers\Api\V1\Payments\MtnMomoCallbackController;
use App\Interfaces\Http\Controllers\Api\V1\Payments\OrangeMoneyCallbackController;
use App\Interfaces\Http\Controllers\Api\V1\Notifications\TwilioDeliveryReceiptController;
use App\Interfaces\Http\Controllers\Api\V1\Identity\MobileAuthController;
use App\Interfaces\Http\Controllers\Api\V1\Payments\MobilePurchaseController;
use App\Interfaces\Http\Controllers\Api\V1\Payments\MobilePaymentController;
use App\Interfaces\Http\Controllers\Api\V1\Policies\MobileWalletController;
use App\Interfaces\Http\Controllers\Api\V1\Logistics\MobileDeliveryController;
use App\Interfaces\Http\Controllers\Api\V1\Quotes\MobileQuoteController;
use App\Interfaces\Http\Controllers\Api\V1\QuoteController;
use App\Interfaces\Http\Controllers\Api\V1\SystemController;
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
    Route::post('public/accounts', [AccountController::class, 'register'])->middleware('throttle:5,1');
    Route::post('webhooks/payments/{provider}', PaymentWebhookController::class)->middleware('throttle:120,1')->name('payments.webhook');
    Route::post('webhooks/payments/mtn-momo/callback', MtnMomoCallbackController::class)->middleware('throttle:120,1')->name('payments.mtn_momo.callback');
    Route::match(['get', 'post'], 'webhooks/payments/orange-money/callback', OrangeMoneyCallbackController::class)->middleware('throttle:120,1')->name('payments.orange_money.callback');
    Route::post('webhooks/notifications/twilio/callback', TwilioDeliveryReceiptController::class)->middleware('throttle:120,1')->name('notifications.twilio.callback');
    Route::post('public/certificates/verify', [CertificateController::class, 'verify'])->middleware('throttle:30,1');
    // The signed-URL target MobileDocumentService's LocalSignedUrlAdapter
    // points to. Outside auth:api/tenant/json.api on purpose: a valid,
    // unexpired signature (see the 'signed' middleware) IS the
    // authorization here, exactly like Laravel's own signed
    // email-verification links — see MobileDocumentDownloadController.
    Route::get('mobile/documents/{document}/download', MobileDocumentDownloadController::class)->middleware(['signed', 'throttle:60,1'])->name('mobile.documents.download');
    Route::post('auth/mobile/otp/request', [MobileAuthController::class, 'requestOtp'])->middleware('throttle:5,1');
    Route::post('auth/mobile/otp/verify', [MobileAuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::post('auth/mobile/refresh', [MobileAuthController::class, 'refresh'])->middleware('throttle:20,1');
    // Not tenant-scoped: the whole point of session/logout is to work before
    // (session, to discover workspaces) or independently of (logout) any
    // single tenant selection — unlike every route in the group below.
    Route::middleware(['auth:api', 'json.api'])->group(function (): void {
        Route::get('auth/mobile/session', [MobileAuthController::class, 'session']);
        Route::post('auth/mobile/logout', [MobileAuthController::class, 'logout'])->middleware('throttle:20,1');
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
        Route::post('mobile/payments/{payment}/refunds', [MobilePaymentController::class, 'requestRefund']);
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
        Route::post('commission-rules', [CommissionController::class, 'createRule'])->middleware('permission:commission.manage');
        Route::post('commission-rules/{rule}/approve', [CommissionController::class, 'approveRule'])->middleware('permission:commission.approve');
        Route::post('commissions/accrue', [CommissionController::class, 'accrue'])->middleware('permission:commission.accrue');
        Route::post('commissions/{accrual}/vest', [CommissionController::class, 'vest'])->middleware('permission:commission.vest');
        Route::post('commissions/{accrual}/clawback', [CommissionController::class, 'clawback'])->middleware('permission:commission.clawback');
        Route::get('partners/{partner}/commission-balance', [CommissionController::class, 'balance'])->middleware('permission:commission.read');
        Route::post('reconciliation/imports', [ReconciliationController::class, 'import'])->middleware('permission:reconciliation.import');
        Route::get('reconciliation/imports/{import}', [ReconciliationController::class, 'show'])->middleware('permission:reconciliation.read');
        Route::post('reconciliation/items/{item}/resolve', [ReconciliationController::class, 'resolve'])->middleware('permission:reconciliation.resolve');
        Route::post('reconciliation/imports/{import}/approve', [ReconciliationController::class, 'approve'])->middleware('permission:reconciliation.approve');
        Route::post('settlements', [SettlementController::class, 'prepare'])->middleware('permission:settlement.prepare');
        Route::get('settlements/{batch}', [SettlementController::class, 'show'])->middleware('permission:settlement.read');
        Route::post('settlements/{batch}/approve', [SettlementController::class, 'approve'])->middleware('permission:settlement.approve');
        require __DIR__.'/wave7.php';
        require __DIR__.'/wave8.php';
        require __DIR__.'/wave9.php';
        require __DIR__.'/wave10.php';
        require __DIR__.'/wave11.php';
        require __DIR__.'/wave12.php';
        require __DIR__.'/wave12_agentmode.php';
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
        Route::post('broker/bordereaux', [BrokerOperationsController::class, 'createBordereau'])->middleware('permission:broker.bordereaux.manage');
        Route::post('broker/bordereaux/{bordereau}/submit', [BrokerOperationsController::class, 'submitBordereau'])->middleware('permission:broker.bordereaux.submit');
        Route::post('broker/renewals/seed', [BrokerOperationsController::class, 'seedRenewals'])->middleware('permission:broker.renewals.manage');
        Route::post('carrier/delegated-authorities', [CarrierOperationsController::class, 'createAuthority'])->middleware('permission:carrier.authority.manage');
        Route::post('carrier/delegated-authorities/{agreement}/approve', [CarrierOperationsController::class, 'approveAuthority'])->middleware('permission:carrier.authority.approve');
        Route::post('carrier/delegated-authorities/{agreement}/check', [CarrierOperationsController::class, 'checkAuthority']);
        Route::post('carrier/bordereaux/{bordereau}/decision', [CarrierOperationsController::class, 'acknowledgeBordereau'])->middleware('permission:carrier.bordereaux.decide');
    });
    Route::middleware('auth:api')->group(function (): void {
        Route::post('invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:5,10');
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

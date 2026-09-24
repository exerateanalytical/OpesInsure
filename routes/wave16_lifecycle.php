<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Catalogue\MobileRiskSchemaController;
use App\Interfaces\Http\Controllers\Api\V1\Identity\MobileLogoutAllController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileCustomerAccountController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileSupportController;
use App\Interfaces\Http\Controllers\Api\V1\Payments\MobilePaymentController;
use App\Interfaces\Http\Controllers\Api\V1\Policies\PolicyDocumentDownloadController;
use App\Interfaces\Http\Controllers\Api\V1\Underwriting\MobileProposalController;
use Illuminate\Support\Facades\Route;

/*
 | Wave 16 — policy lifecycle (payment -> issuance -> documents ->
 | notifications -> expiry) and customer account completion. Included from
 | routes/api.php inside the auth:api + tenant + json.api group.
 */

// Risk questionnaire per insurance line (fields + steps) for the quote wizard.
Route::get('mobile/catalogue/lines/{code}/risk-schema', MobileRiskSchemaController::class);

// The customer's own proposals and their answer to a counter-offer.
Route::get('mobile/proposals', [MobileProposalController::class, 'index']);
Route::post('mobile/proposals/{proposal}/counteroffer/{answer}', [MobileProposalController::class, 'counterOffer'])
    ->whereIn('answer', ['accept', 'decline'])->middleware('throttle:20,1');

// Customer profile / KYC details, consents, privacy requests, support cases.
Route::get('mobile/account/customer-profile', [MobileCustomerAccountController::class, 'profile']);
Route::patch('mobile/account/customer-profile', [MobileCustomerAccountController::class, 'updateProfile'])->middleware('throttle:20,1');
Route::get('mobile/account/consents', [MobileCustomerAccountController::class, 'consents']);
Route::put('mobile/account/consents', [MobileCustomerAccountController::class, 'saveConsents'])->middleware('throttle:20,1');
Route::get('mobile/account/privacy-requests', [MobileCustomerAccountController::class, 'privacyRequests']);
Route::post('mobile/account/privacy-requests', [MobileCustomerAccountController::class, 'createPrivacyRequest'])->middleware('throttle:5,60');
Route::post('mobile/support/cases/{case}/escalate', [MobileSupportController::class, 'escalate'])->middleware('throttle:10,1');

// Revoke every access + refresh token of the user (all devices). Works
// without a tenant selection, like auth/mobile/logout.
Route::post('auth/mobile/logout-all', MobileLogoutAllController::class)
    ->withoutMiddleware('tenant')
    ->middleware('throttle:10,1');

// Signed, short-lived downloads: the unexpired signature is the
// authorization (same model as mobile.documents.download), so a PDF can be
// opened by the OS viewer / browser without the bearer token.
Route::withoutMiddleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('mobile/policy-documents/{document}/download', PolicyDocumentDownloadController::class)
        ->middleware(['signed', 'throttle:60,1'])->name('mobile.policy-documents.download');
    Route::get('mobile/payments/{payment}/receipt.pdf', [MobilePaymentController::class, 'receiptPdf'])
        ->middleware(['signed', 'throttle:60,1'])->name('mobile.payments.receipt.pdf');
});

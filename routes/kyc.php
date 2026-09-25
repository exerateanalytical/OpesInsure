<?php

declare(strict_types=1);

use App\Application\Kyc\Http\KycController;
use Illuminate\Support\Facades\Route;

/*
 | REQ-KYC-001..003 — staff KYC review on the case engine. Loaded by App\Providers\KycServiceProvider.
 | Customer self-service stays on /v1/mobile/kyc/* (routes/wave12_kyc.php), now a thin adapter over KycService.
 */
Route::prefix('v1/kyc')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::middleware('permission:kyc.view')->group(function (): void {
        Route::get('submissions', [KycController::class, 'index']);
        Route::get('submissions/{submission}', [KycController::class, 'show'])->whereUuid('submission');
        Route::get('expiring', [KycController::class, 'expiring']);
        Route::get('requirements', [KycController::class, 'requirements']);
        Route::get('parties/{party}/status', [KycController::class, 'partyStatus'])->whereUuid('party');
        Route::get('risk-configuration', [KycController::class, 'riskConfiguration']);
    });
    // Owner decision 27 — risk-based KYC, source of funds / wealth, rescreening (MANUAL_AUDITED).
    Route::post('submissions/{submission}/risk-assessment', [KycController::class, 'riskAssessment'])->whereUuid('submission')->middleware('permission:kyc.review');
    Route::post('submissions/{submission}/sources', [KycController::class, 'sources'])->whereUuid('submission')->middleware('permission:kyc.manage');
    Route::post('submissions/{submission}/rescreen', [KycController::class, 'rescreen'])->whereUuid('submission')->middleware('permission:kyc.screen');
    Route::middleware('permission:kyc.manage')->group(function (): void {
        Route::post('parties/{party}/submissions', [KycController::class, 'open'])->whereUuid('party');
        Route::post('submissions/{submission}/documents', [KycController::class, 'attach'])->whereUuid('submission');
        Route::post('submissions/{submission}/submit', [KycController::class, 'submit'])->whereUuid('submission');
        Route::post('submissions/{submission}/remediate', [KycController::class, 'remediate'])->whereUuid('submission');
    });
    Route::middleware('permission:kyc.review')->group(function (): void {
        Route::post('submissions/{submission}/start-review', [KycController::class, 'startReview'])->whereUuid('submission');
        Route::post('submissions/{submission}/request-information', [KycController::class, 'requestInformation'])->whereUuid('submission');
        Route::post('submissions/{submission}/level', [KycController::class, 'level'])->whereUuid('submission');
        Route::post('submissions/{submission}/recommend', [KycController::class, 'recommend'])->whereUuid('submission');
    });
    Route::post('submissions/{submission}/screenings/{check}', [KycController::class, 'screening'])->whereUuid('submission')->whereUuid('check')->middleware('permission:kyc.screen');
    Route::post('submissions/{submission}/decision', [KycController::class, 'decide'])->whereUuid('submission')->middleware('permission:kyc.decide');
});

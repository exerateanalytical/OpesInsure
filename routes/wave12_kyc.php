<?php

use App\Interfaces\Http\Controllers\Api\V1\Kyc\MobileKycController;
use App\Interfaces\Http\Controllers\Api\V1\Risks\MobileRiskAssetController;
use Illuminate\Support\Facades\Route;

// Customer-facing KYC verification — see App\Application\Kyc\MobileKycService.
// Contract from OPESINSURE_EXPO_CUSTOMER_CORE_PATCH_v0.5.0's
// CLAUDE_MERGE_GUIDE.md, adapted to reference an already-uploaded Document
// (POST /mobile/documents) instead of a second upload mechanism.
Route::get('mobile/kyc/profile', [MobileKycController::class, 'profile']);
Route::patch('mobile/kyc/profile', [MobileKycController::class, 'updateProfile'])->middleware('throttle:10,1');
Route::post('mobile/kyc/documents', [MobileKycController::class, 'attachDocument'])->middleware('throttle:20,1');
// Idempotency-Key required: a client retrying a dropped "submit" request
// must not create a second submission or re-trigger audit/outbox writes.
Route::post('mobile/kyc/submission', [MobileKycController::class, 'submit'])->middleware(['throttle:10,1', 'idempotency:mobile.kyc.submit']);

// Customer-facing insured risk assets — see App\Application\Risks\MobileRiskAssetService.
Route::get('mobile/assets', [MobileRiskAssetController::class, 'index']);
Route::post('mobile/assets', [MobileRiskAssetController::class, 'store'])->middleware('throttle:20,1');
Route::get('mobile/assets/{asset}', [MobileRiskAssetController::class, 'show']);
Route::post('mobile/assets/{asset}/documents', [MobileRiskAssetController::class, 'attachDocument'])->middleware('throttle:20,1');
Route::post('mobile/assets/{asset}/scan', [MobileRiskAssetController::class, 'requestScan'])->middleware('throttle:20,1');
Route::post('mobile/assets/{asset}/scan/{document}/confirm', [MobileRiskAssetController::class, 'confirmScan'])->middleware('throttle:20,1');

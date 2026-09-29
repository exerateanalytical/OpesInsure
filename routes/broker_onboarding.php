<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Partners\BrokerOnboardingController;
use Illuminate\Support\Facades\Route;

/*
 | S9 bulk broker onboarding (BrokerOnboardingServiceProvider). Platform tenant only; tenant.manage + identity.invite
 | (both checked again in the controller). Maker-checker through ImportPipeline / ApprovalService.
 */
Route::prefix('v1/platform/broker-onboarding')->middleware(['auth:api', 'tenant', 'platform.tenant', 'permission:tenant.manage', 'permission:identity.invite', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('template', [BrokerOnboardingController::class, 'template']);
        Route::get('batches', [BrokerOnboardingController::class, 'index']);
        Route::post('batches', [BrokerOnboardingController::class, 'store'])->middleware('json.api');
        Route::get('batches/{batch}', [BrokerOnboardingController::class, 'show'])->whereUuid('batch');
        Route::get('batches/{batch}/report', [BrokerOnboardingController::class, 'report'])->whereUuid('batch');
        Route::post('batches/{batch}/submit', [BrokerOnboardingController::class, 'submit'])->middleware('json.api')->whereUuid('batch');
        Route::post('batches/{batch}/approve', [BrokerOnboardingController::class, 'approve'])->middleware('json.api')->whereUuid('batch');
        Route::post('batches/{batch}/reject', [BrokerOnboardingController::class, 'reject'])->middleware('json.api')->whereUuid('batch');
        Route::post('batches/{batch}/cancel', [BrokerOnboardingController::class, 'cancel'])->middleware('json.api')->whereUuid('batch');
    });

<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Documents\DocumentEngineController as C;
use Illuminate\Support\Facades\Route;

// Document engine (App\Providers\DocumentEngineServiceProvider).
Route::prefix('v1')->group(function (): void {
    Route::middleware(['auth:api', 'tenant', 'json.api', 'throttle:60,1'])->group(function (): void {
        // Customer: own policy's documents grouped by pack/stage + contract history, and the pack link.
        Route::get('mobile/policies/{policy}/documents', [C::class, 'policyDocuments']);
        Route::get('mobile/policies/{policy}/documents/pack', [C::class, 'packUrl']);

        // Staff.
        Route::post('policies/{policy}/carrier-documents', [C::class, 'uploadCarrierDocument'])->middleware('permission:documents.carrier.upload');
        Route::post('documents/{document}/status-changes', [C::class, 'requestStatusChange'])->middleware('permission:documents.status.request');
        Route::post('document-status-changes/{change}/{decision}', [C::class, 'decideStatusChange'])->whereIn('decision', ['approve', 'reject'])->middleware('permission:documents.status.approve');
        Route::get('products/{product}/document-gate', [C::class, 'productGate'])->middleware('permission:documents.templates.manage');
        Route::post('document-templates', [C::class, 'createTemplate'])->middleware('permission:documents.templates.manage');
        Route::post('document-templates/{template}/{action}', [C::class, 'templateTransition'])->whereIn('action', ['submit', 'approve', 'publish', 'retire'])->middleware('permission:documents.templates.manage');
    });

    Route::get('mobile/policy-packs/{policy}/download', [C::class, 'packDownload'])
        ->middleware(['signed', 'throttle:30,1'])->name('mobile.policy-pack.download');
});

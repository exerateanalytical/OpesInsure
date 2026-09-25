<?php

declare(strict_types=1);

use App\Application\Authority\Http\AuthorityTypeController;
use App\Application\ReferenceDatasets\Http\ReferenceDatasetController;
use App\Application\Rules\PremiumCover\Http\PremiumCoverController;
use Illuminate\Support\Facades\Route;

/*
 | Owner decisions 2026-09-25: #12 authority types, #17 premium-to-cover rules, versioned institutional
 | datasets (public holidays, hazard zones). Loaded by App\Providers\OwnerDecisionsServiceProvider.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('authority-types', [AuthorityTypeController::class, 'index'])->middleware('permission:authority.types.view');
    Route::post('authority-types', [AuthorityTypeController::class, 'store'])->middleware('permission:authority.types.manage');
    Route::post('authority-types/{code}/retire', [AuthorityTypeController::class, 'retire'])->middleware('permission:authority.types.manage');

    Route::middleware('permission:premium_cover.rules.view')->group(function (): void {
        Route::get('premium-cover-rules', [PremiumCoverController::class, 'index']);
        Route::post('premium-cover/evaluate', [PremiumCoverController::class, 'evaluate']);
    });
    Route::post('premium-cover-rules', [PremiumCoverController::class, 'store'])->middleware('permission:premium_cover.rules.manage');
    Route::post('premium-cover-rules/{id}/approve', [PremiumCoverController::class, 'approve'])->whereUuid('id')->middleware('permission:premium_cover.rules.approve');
    Route::post('premium-cover-rules/{id}/retire', [PremiumCoverController::class, 'retire'])->whereUuid('id')->middleware('permission:premium_cover.rules.manage');

    Route::middleware('permission:reference_datasets.view')->group(function (): void {
        Route::get('reference-datasets', [ReferenceDatasetController::class, 'index']);
        Route::get('reference-datasets/{id}', [ReferenceDatasetController::class, 'show'])->whereUuid('id');
    });
    Route::post('reference-datasets', [ReferenceDatasetController::class, 'store'])->middleware('permission:reference_datasets.manage');
    Route::post('reference-datasets/{id}/activate', [ReferenceDatasetController::class, 'activate'])->whereUuid('id')->middleware('permission:reference_datasets.approve');
    Route::post('reference-datasets/{id}/retire', [ReferenceDatasetController::class, 'retire'])->whereUuid('id')->middleware('permission:reference_datasets.approve');
});

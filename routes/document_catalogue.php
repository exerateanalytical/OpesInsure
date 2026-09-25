<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\DocumentCatalogue\DocumentCatalogueController;
use Illuminate\Support\Facades\Route;

/*
 | Canonical document registry — public, read-only reference data.
 | Loaded by App\Providers\DocumentCatalogueServiceProvider under the `api` group.
 */
Route::prefix('v1/public')->middleware('throttle:120,1')->group(function (): void {
    Route::get('document-types', [DocumentCatalogueController::class, 'types']);
    Route::get('document-types/{idOrCode}', [DocumentCatalogueController::class, 'type']);
    Route::get('document-packs', [DocumentCatalogueController::class, 'packs']);
    Route::get('document-requirements', [DocumentCatalogueController::class, 'requirements']);
});

// Canonical document verification (crypto spec §45): random QR token only; privacy-safe by confidentiality class.
Route::prefix('v1/public')->middleware('throttle:20,1')->group(function (): void {
    Route::get('verify-document/{token}', [\App\Interfaces\Http\Controllers\Api\V1\Documents\PublicDocumentVerificationController::class, 'verify']);
    Route::get('document-signing-keys', [\App\Interfaces\Http\Controllers\Api\V1\Documents\PublicDocumentVerificationController::class, 'keys']);
});

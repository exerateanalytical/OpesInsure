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

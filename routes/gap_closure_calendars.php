<?php

declare(strict_types=1);

use App\Application\ReferenceDatasets\Http\PublicHolidayGeneratorController;
use Illuminate\Support\Facades\Route;

/*
 | Gap Closure Pack v1 file 02 — yearly public-holiday datasets generated from the seeded Law 73/5 rules.
 | Loaded by App\Providers\GapClosureCalendarsServiceProvider. Reuses the reference_datasets permissions.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('reference-datasets/public-holidays/preview', [PublicHolidayGeneratorController::class, 'preview'])->middleware('permission:reference_datasets.view');
    Route::post('reference-datasets/public-holidays/generate', [PublicHolidayGeneratorController::class, 'generate'])->middleware('permission:reference_datasets.manage');
});

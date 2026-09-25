<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\PlatformConfiguration\CarrierBrokerAgreementController as A;
use App\Interfaces\Http\Controllers\Api\V1\PlatformConfiguration\PlatformConfigurationController as P;
use Illuminate\Support\Facades\Route;

/*
 | Batch 3 / 3E — platform setup (REQ-SET-001), configuration inheritance (REQ-SET-004), demo coverage (REQ-SEED-005),
 | carrier ↔ broker agreements (REQ-SEED-004 / REQ-DUP-023). Loaded by App\Providers\PlatformConfigurationServiceProvider.
 | Governed changes are drafted here, then submitted/published through configuration-changes/{id}/{step}
 | and decided in the approvals inbox (routes/approvals.php) — one decision path.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('platform/setup', [P::class, 'setup'])->middleware('permission:platform.settings.manage');
    Route::post('platform/setup/identity', [P::class, 'draftIdentity'])->middleware('permission:configuration.changes.manage');
    Route::post('platform/setup/complete', [P::class, 'completeSetup'])->middleware('permission:platform.settings.manage');

    Route::get('configuration/inheritance', [P::class, 'inheritanceKeys'])->middleware('permission:configuration.changes.manage');
    Route::get('configuration/inheritance/resolve', [P::class, 'resolve'])->middleware('permission:configuration.changes.manage');
    Route::post('configuration/inheritance/overrides', [P::class, 'draftOverride'])->middleware('permission:configuration.changes.manage');

    Route::get('demo/coverage', [P::class, 'demoCoverage'])->middleware('permission:platform.settings.manage');

    Route::get('carrier-broker-agreements', [A::class, 'index'])->middleware('permission:distribution.agreements.view');
    Route::post('carrier-broker-agreements', [A::class, 'store'])->middleware('permission:distribution.agreements.manage');
    Route::get('carrier-broker-agreements/permits', [A::class, 'permits'])->middleware('permission:distribution.agreements.view');
    Route::get('carrier-broker-agreements/{agreement}', [A::class, 'show'])->whereUuid('agreement')->middleware('permission:distribution.agreements.view');
    Route::put('carrier-broker-agreements/{agreement}/products', [A::class, 'setProduct'])->whereUuid('agreement')->middleware('permission:distribution.agreements.manage');
    Route::post('carrier-broker-agreements/{agreement}/{action}', [A::class, 'transition'])->whereUuid('agreement')->whereIn('action', ['activate', 'suspend', 'terminate'])->middleware('permission:distribution.agreements.approve');
});

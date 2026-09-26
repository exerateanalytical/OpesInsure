<?php

declare(strict_types=1);

use App\Application\Claims\RepairNetwork\Http\MotorClaimsReferenceController;
use Illuminate\Support\Facades\Route;

/*
 | Agent GP3 — Gap Closure Pack v1 file 03 (motor, claims taxonomies, garage network, technical experts).
 | Loaded by App\Application\Claims\RepairNetwork\MotorClaimsServiceProvider. Reuses the providers.* permissions for the network
 | and claims.view for the taxonomies (reference data). Imports go through the generic /api/v1/imports pipeline
 | (targets repair_garages, technical_experts).
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    $c = MotorClaimsReferenceController::class;
    Route::get('claims/taxonomies', [$c, 'taxonomies'])->middleware('permission:claims.view');
    Route::get('claims/taxonomies/cause-of-loss/resolve', [$c, 'resolveCause'])->middleware('permission:claims.view');
    Route::get('motor/vehicle-classes', [$c, 'vehicleClasses'])->middleware('permission:vehicle_power.view');
    Route::get('repair-network/garages', [$c, 'garages'])->middleware('permission:providers.view');
    Route::get('repair-network/experts', [$c, 'experts'])->middleware('permission:providers.view');
    Route::get('repair-network/providers/{provider}', [$c, 'show'])->middleware('permission:providers.view')->whereUuid('provider');
    Route::post('repair-network/providers/{provider}/capabilities', [$c, 'capabilities'])->middleware('permission:providers.manage')->whereUuid('provider');
    Route::post('repair-network/providers/{provider}/verify-source', [$c, 'verify'])->middleware('permission:providers.credential')->whereUuid('provider');
});

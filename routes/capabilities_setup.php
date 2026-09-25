<?php

declare(strict_types=1);

use App\Application\Capabilities\Http\CapabilityController;
use App\Application\CarrierOperations\Setup\Http\CarrierSetupController;
use App\Application\Partners\Setup\Http\PartnerSetupController;
use Illuminate\Support\Facades\Route;

/*
 | REQ-AOM-001 capability profiles, REQ-SET-002 insurer setup, REQ-SET-003 broker setup.
 | Loaded by App\Providers\CapabilitySetupServiceProvider under the `api` group.
 | Transition permissions (carrier_setup.manage/approve, partner_setup.manage/approve)
 | are enforced per event by the state machine.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::middleware('permission:capability_profiles.view')->group(function (): void {
        Route::get('capability-catalogue', [CapabilityController::class, 'catalogue']);
        Route::get('carriers/{carrier}/capability-profiles', [CapabilityController::class, 'index'])->whereUuid('carrier');
        Route::get('capability-profiles/{profile}', [CapabilityController::class, 'show'])->whereUuid('profile');
        Route::get('carriers/{carrier}/capabilities', [CapabilityController::class, 'resolve'])->whereUuid('carrier');
        Route::get('carriers/{carrier}/capabilities/maturity', [CapabilityController::class, 'maturity'])->whereUuid('carrier');
        Route::get('capability-pins', [CapabilityController::class, 'pins']);
    });
    Route::middleware('permission:capability_profiles.manage')->group(function (): void {
        Route::post('carriers/{carrier}/capability-profiles', [CapabilityController::class, 'store'])->whereUuid('carrier');
        Route::put('capability-profiles/{profile}/modes', [CapabilityController::class, 'replaceModes'])->whereUuid('profile');
        Route::post('capability-profiles/{profile}/submit', [CapabilityController::class, 'submit'])->whereUuid('profile');
        Route::post('capability-pins', [CapabilityController::class, 'pin']);
    });
    Route::middleware('permission:capability_profiles.approve')->group(function (): void {
        Route::post('capability-profiles/{profile}/approve', [CapabilityController::class, 'approve'])->whereUuid('profile');
        Route::post('capability-profiles/{profile}/reject', [CapabilityController::class, 'reject'])->whereUuid('profile');
    });

    Route::get('carriers/{carrier}/setup', [CarrierSetupController::class, 'show'])->whereUuid('carrier')->middleware('permission:carrier_setup.view');
    Route::middleware('permission:carrier_setup.manage')->group(function (): void {
        Route::post('carriers/{carrier}/setup', [CarrierSetupController::class, 'store'])->whereUuid('carrier');
        Route::put('carriers/{carrier}/setup/checklist/{item}', [CarrierSetupController::class, 'attest'])->whereUuid('carrier');
        Route::post('carriers/{carrier}/setup/transitions', [CarrierSetupController::class, 'transition'])->whereUuid('carrier');
    });

    Route::get('partners/{partner}/setup', [PartnerSetupController::class, 'show'])->whereUuid('partner')->middleware('permission:partner_setup.view');
    Route::middleware('permission:partner_setup.manage')->group(function (): void {
        Route::post('partners/{partner}/setup', [PartnerSetupController::class, 'store'])->whereUuid('partner');
        Route::put('partners/{partner}/setup/checklist/{item}', [PartnerSetupController::class, 'attest'])->whereUuid('partner');
        Route::post('partners/{partner}/setup/transitions', [PartnerSetupController::class, 'transition'])->whereUuid('partner');
    });
});

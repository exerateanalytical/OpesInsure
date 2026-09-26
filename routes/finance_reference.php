<?php

declare(strict_types=1);

use App\Application\Finance\ReferenceMasters\Http\FinanceReferenceController as C;
use Illuminate\Support\Facades\Route;

/*
 | Agent GP6 — gap closure pack 06: banks / payment institutions, payment provider profiles, GL control accounts,
 | event-to-GL view and cost centres. Loaded by App\Application\Finance\ReferenceMasters\FinanceReferenceServiceProvider.
 */
Route::prefix('v1/finance/reference')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('institutions', [C::class, 'institutions'])->middleware('permission:finance.accounts.view');
    Route::patch('institutions/{institution}', [C::class, 'updateInstitution'])->middleware('permission:finance.institutions.manage')->whereUuid('institution');
    Route::get('payment-providers', [C::class, 'profiles'])->middleware('permission:finance.accounts.view');
    Route::post('payment-providers', [C::class, 'createProfile'])->middleware('permission:finance.payment_providers.configure');
    Route::patch('payment-providers/{profile}', [C::class, 'updateProfile'])->middleware('permission:finance.payment_providers.configure')->whereUuid('profile');
    Route::post('payment-providers/{profile}/submit', [C::class, 'submitProfile'])->middleware('permission:finance.payment_providers.configure')->whereUuid('profile');
    Route::post('payment-providers/{profile}/decision', [C::class, 'decideProfile'])->middleware('permission:finance.payment_providers.approve')->whereUuid('profile');
    Route::get('control-accounts', [C::class, 'controlAccounts'])->middleware('permission:finance.accounts.view');
    Route::post('control-accounts', [C::class, 'proposeControlAccount'])->middleware('permission:finance.gl.configure');
    Route::post('control-accounts/{mapping}/approve', [C::class, 'approveControlAccount'])->middleware('permission:finance.gl.approve')->whereUuid('mapping');
    Route::get('gl-event-mappings', [C::class, 'glEventMappings'])->middleware('permission:finance.accounts.view');
    Route::get('cost-centres', [C::class, 'costCentres'])->middleware('permission:finance.accounts.view');
    Route::post('cost-centres', [C::class, 'createCostCentre'])->middleware('permission:finance.gl.configure');
    Route::post('cost-centres/{costCentre}/status', [C::class, 'costCentreStatus'])->middleware('permission:finance.gl.configure')->whereUuid('costCentre');
});

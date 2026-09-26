<?php

declare(strict_types=1);

use App\Application\Compliance\Catalogue\Http\ComplianceCatalogueController as C;
use Illuminate\Support\Facades\Route;

// Gap Closure Pack 08 / 09 — REQ-KYC-GC-008, REQ-CMP-GC-009 (App\Application\Compliance\Catalogue).
Route::prefix('v1/compliance-catalogue')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('kyc/document-matrix', [C::class, 'documentMatrix'])->middleware('permission:compliance.catalogue.view');
    Route::get('kyc/refresh-policies', [C::class, 'refreshPolicies'])->middleware('permission:compliance.catalogue.view');
    Route::put('kyc/refresh-policies/{level}', [C::class, 'configureRefresh'])->middleware('permission:compliance.catalogue.configure');
    Route::post('kyc/refresh-policies/{id}/approve', [C::class, 'approveRefresh'])->middleware('permission:compliance.catalogue.approve')->whereUuid('id');
    Route::get('country-risk', [C::class, 'countryRisk'])->middleware('permission:compliance.catalogue.view');
    Route::get('fraud/indicators', [C::class, 'fraudIndicators'])->middleware('permission:compliance.catalogue.view');
    Route::post('fraud/indicators/{code}/flag', [C::class, 'flag'])->middleware('permission:fraud.indicators.flag');
    Route::get('regulatory/dictionary', [C::class, 'dictionary'])->middleware('permission:compliance.catalogue.view');
    Route::get('controls', [C::class, 'controls'])->middleware('permission:compliance.catalogue.view');
    Route::get('controls/{control}/assessments', [C::class, 'assessments'])->middleware('permission:compliance.catalogue.view')->whereUuid('control');
    Route::post('controls/{control}/assessments', [C::class, 'assess'])->middleware('permission:compliance.controls.assess')->whereUuid('control');
    Route::get('readiness', [C::class, 'readiness'])->middleware('permission:compliance.catalogue.view');
});

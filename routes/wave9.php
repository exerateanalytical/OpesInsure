<?php
use App\Interfaces\Http\Controllers\Api\V1\Trust\Wave9Controller;use Illuminate\Support\Facades\Route;use App\Interfaces\Http\Controllers\Api\V1\Compliance\{ComplianceCaseController as CC,ComplianceController as CO};
// REQ-DUP-009: fraud-alerts, compliance-cases, data-subject-requests and privileged-access are deprecated aliases of the canonical
// risk-alerts / compliance/* routes (same controller action, historical trust.* permission kept). Only regulatory reports remain native here.
Route::prefix('trust')->controller(Wave9Controller::class)->group(function(){
    Route::post('fraud-alerts',[\App\Interfaces\Http\Controllers\Api\V1\Fraud\RiskAlertController::class,'alert'])->middleware(['permission:trust.fraud-alerts.create',\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('risk-alerts','REQ-DUP-009')]);
    Route::post('fraud-alerts/{alert}/decision',[\App\Interfaces\Http\Controllers\Api\V1\Fraud\RiskAlertController::class,'decide'])->middleware(['permission:trust.fraud-alerts.decide',\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('risk-alerts/{alert}/decision','REQ-DUP-009')]);
    Route::post('compliance-cases',[CC::class,'open'])->middleware(['permission:trust.compliance-cases.create',\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('compliance/cases','REQ-DUP-009')]);
    Route::post('compliance-cases/{case}/transition',[CC::class,'transition'])->middleware(['permission:trust.compliance-cases.transition',\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('compliance/cases/{case}/transition','REQ-DUP-009')]);
    Route::post('data-subject-requests',[CO::class,'dataRequest'])->middleware(['permission:trust.dsr.receive',\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('compliance/data-subject-requests','REQ-DUP-009')]);
    Route::post('data-subject-requests/{x}/verify',[CO::class,'verifyDataRequest'])->middleware(['permission:trust.dsr.verify',\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('compliance/data-subject-requests/{x}/verify','REQ-DUP-009')]);
    Route::post('data-subject-requests/{x}/resolve',[CO::class,'resolveDataRequest'])->middleware(['permission:trust.dsr.resolve',\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('compliance/data-subject-requests/{x}/resolve','REQ-DUP-009')]);
    Route::post('privileged-access',[CO::class,'grantAccess'])->middleware(['permission:trust.privileged-access.request',\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('compliance/privileged-access','REQ-DUP-009')]);
    Route::post('privileged-access/{x}/approve',[CO::class,'approveAccess'])->middleware(['permission:trust.privileged-access.approve',\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('compliance/privileged-access/{x}/approve','REQ-DUP-009')]);
    Route::post('privileged-access/{x}/revoke',[CO::class,'revokeAccess'])->middleware(['permission:trust.privileged-access.revoke',\App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('compliance/privileged-access/{x}/revoke','REQ-DUP-009')]);
    Route::post('regulatory-reports/{d}/runs','prepareReport')->middleware('permission:trust.regulatory-reports.prepare');
    Route::post('regulatory-report-runs/{x}/approve','approveReport')->middleware('permission:trust.regulatory-reports.approve');
    Route::post('regulatory-report-runs/{x}/submit','submitReport')->middleware('permission:trust.regulatory-reports.submit');
    Route::post('regulatory-report-runs/{x}/failure','reportFailure')->middleware('permission:trust.regulatory-reports.submit');
    Route::post('regulatory-report-runs/{x}/acknowledge','acknowledgeReport')->middleware('permission:trust.regulatory-reports.acknowledge');
});

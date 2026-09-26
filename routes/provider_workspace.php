<?php

declare(strict_types=1);

use App\Application\Providers\Portal\ProviderScope;
use App\Application\Providers\Workspace\Http\ProviderDocumentController as PD;
use App\Application\Providers\Workspace\Http\ProviderWorkspaceController as W;
use Illuminate\Support\Facades\Route;

/*
 * Provider Portal Gap-Free spec v1 (REQ-PRV-003, REQ-HLT-001..003) — loaded by ProviderWorkspaceServiceProvider.
 * Same prefix / middleware stack as the existing read-only provider portal (routes/api.php "Agent E1" group); the
 * existing GET lists (preauthorizations, claims, contracts, tariffs …) stay there, the canonical-filter paginated lists
 * are the /search variants below. Every POST requires an Idempotency-Key (duplicate API submissions are replayed, never
 * executed twice).
 */
Route::prefix('api/v1/provider-portal')->middleware(['api', 'auth:api', 'tenant', 'json.api', ProviderScope::class])->group(function (): void {
    $idem = fn (string $op) => 'idempotency:provider_portal.'.$op;

    Route::get('screens', [W::class, 'screens'])->middleware('permission:provider.dashboard.view');
    Route::get('dashboard', [W::class, 'dashboard'])->middleware('permission:provider.dashboard.view');

    Route::post('eligibility/check', [W::class, 'eligibilityCheck'])->middleware(['permission:provider.eligibility.check', 'throttle:120,1', $idem('eligibility.check')]);
    Route::get('eligibility/{check}', [W::class, 'eligibilityShow'])->middleware('permission:provider.eligibility.check')->whereUuid('check');

    Route::get('preauthorizations/search', [W::class, 'preauthIndex'])->middleware('permission:provider.preauth.view');
    Route::post('preauthorizations', [W::class, 'preauthStore'])->middleware(['permission:provider.preauth.create', $idem('preauth.create')]);
    Route::get('preauthorizations/{id}', [W::class, 'preauthShow'])->middleware('permission:provider.preauth.view')->whereUuid('id');
    Route::post('preauthorizations/{id}/respond-to-query', [W::class, 'preauthRespond'])->middleware(['permission:provider.preauth.respond_to_query', $idem('preauth.respond')])->whereUuid('id');
    Route::post('preauthorizations/{id}/cancel', [W::class, 'preauthCancel'])->middleware(['permission:provider.preauth.create', $idem('preauth.cancel')])->whereUuid('id');

    Route::get('admissions', [W::class, 'admissionIndex'])->middleware('permission:provider.preauth.view');
    Route::post('admissions', [W::class, 'admissionStore'])->middleware(['permission:provider.admission.create', $idem('admission.create')]);
    Route::get('admissions/{id}', [W::class, 'admissionShow'])->middleware('permission:provider.preauth.view')->whereUuid('id');
    Route::post('admissions/{id}/extensions', [W::class, 'admissionExtend'])->middleware(['permission:provider.admission.extend', $idem('admission.extend')])->whereUuid('id');
    Route::post('admissions/{id}/discharge', [W::class, 'admissionDischarge'])->middleware(['permission:provider.admission.create', $idem('admission.discharge')])->whereUuid('id');

    Route::get('treatment-episodes', [W::class, 'episodeIndex'])->middleware('permission:provider.treatment.view');
    Route::post('treatment-episodes', [W::class, 'episodeStore'])->middleware(['permission:provider.treatment.update', $idem('episode.create')]);
    Route::get('treatment-episodes/{id}', [W::class, 'episodeShow'])->middleware('permission:provider.treatment.view')->whereUuid('id');
    Route::post('treatment-episodes/{id}/lines', [W::class, 'episodeLine'])->middleware(['permission:provider.treatment.update', $idem('episode.line')])->whereUuid('id');
    Route::post('treatment-episodes/{id}/close', [W::class, 'episodeClose'])->middleware(['permission:provider.treatment.update', $idem('episode.close')])->whereUuid('id');
    Route::post('treatment-episodes/{id}/bill', [W::class, 'episodeBill'])->middleware(['permission:provider.claim.create', $idem('episode.bill')])->whereUuid('id');

    Route::get('claims/search', [W::class, 'claimIndex'])->middleware('permission:provider.claim.view');
    Route::post('claims', [W::class, 'claimStore'])->middleware(['permission:provider.claim.create', $idem('claim.create')]);
    Route::get('claims/{id}', [W::class, 'claimShow'])->middleware('permission:provider.claim.view')->whereUuid('id');
    Route::post('claims/{id}/submit', [W::class, 'claimSubmit'])->middleware(['permission:provider.claim.submit', $idem('claim.submit')])->whereUuid('id');
    Route::post('claims/{id}/respond-to-query', [W::class, 'claimRespond'])->middleware(['permission:provider.claim.respond_to_query', $idem('claim.respond')])->whereUuid('id');

    Route::get('accounts', [W::class, 'accounts'])->middleware('permission:provider.finance.view');
    Route::get('accounts/{insurer}', [W::class, 'account'])->middleware('permission:provider.finance.view')->whereUuid('insurer');
    Route::get('settlements', [W::class, 'settlements'])->middleware('permission:provider.settlement.view');
    Route::get('settlements/{id}', [W::class, 'settlement'])->middleware('permission:provider.settlement.view')->whereUuid('id');
    Route::get('reconciliations', [W::class, 'reconciliations'])->middleware('permission:provider.reconciliation.view');
    Route::post('reconciliations', [W::class, 'reconciliationStore'])->middleware(['permission:provider.reconciliation.match', $idem('reconciliation.create')]);
    Route::get('reconciliations/{id}', [W::class, 'reconciliationShow'])->middleware('permission:provider.reconciliation.view')->whereUuid('id');
    Route::post('reconciliations/{id}/match', [W::class, 'reconciliationMatch'])->middleware(['permission:provider.reconciliation.match', $idem('reconciliation.match')])->whereUuid('id');
    Route::get('disputes', [W::class, 'disputes'])->middleware('permission:provider.dispute.view');
    Route::post('disputes', [W::class, 'disputeStore'])->middleware(['permission:provider.dispute.create', $idem('dispute.create')]);
    Route::get('disputes/{id}', [W::class, 'disputeShow'])->middleware('permission:provider.dispute.view')->whereUuid('id');

    Route::get('contracts/{id}', [W::class, 'contractShow'])->middleware('permission:provider.contract.view')->whereUuid('id');
    Route::get('tariffs/{id}', [W::class, 'tariffShow'])->middleware('permission:provider.tariff.view')->whereUuid('id');

    Route::get('reports/{report}', [W::class, 'report'])->middleware('permission:provider.reports.view');
    Route::get('audit', [W::class, 'audit'])->middleware('permission:provider.audit.view');
    Route::get('documents', [W::class, 'documents'])->middleware('permission:provider.documents.view');
    // D4: engine-issued provider documents (eligibility confirmation, preauth request, EOB, settlement statement, contract, tariff schedule + GOP pack).
    Route::get('documents/issued', [PD::class, 'providerIndex'])->middleware('permission:provider.documents.view');
    Route::get('documents/{id}/download', [PD::class, 'providerDownload'])->middleware('permission:provider.documents.view')->whereUuid('id');
    Route::get('notifications', [W::class, 'notifications'])->middleware('permission:provider.dashboard.view');

    Route::get('roles', [W::class, 'roles'])->middleware('permission:provider.users.manage');
    Route::get('users', [W::class, 'users'])->middleware('permission:provider.users.manage');
    Route::post('users', [W::class, 'userAssign'])->middleware(['permission:provider.users.manage', $idem('user.assign')]);
    Route::post('users/{id}/revoke', [W::class, 'userRevoke'])->middleware(['permission:provider.users.manage', $idem('user.revoke')])->whereUuid('id');
    Route::get('departments', [W::class, 'departments'])->middleware('permission:provider_portal.profile.view');
    Route::post('facilities/{facility}/departments', [W::class, 'departmentStore'])->middleware(['permission:provider.settings.manage', $idem('department.create')])->whereUuid('facility');
    Route::get('integration', [W::class, 'integration'])->middleware('permission:provider.settings.manage');
});

// Insurer back office: resolve a provider dispute (the dispute row is never deleted).
Route::prefix('api/v1/health')->middleware(['api', 'auth:api', 'tenant', 'json.api'])->group(function (): void {
    // D4: provider documents for the insurer (documents.read + the security level permission, enforced in the service).
    Route::get('provider-documents', [PD::class, 'insurerIndex'])->middleware('permission:documents.read');
    Route::get('provider-documents/{id}/download', [PD::class, 'insurerDownload'])->middleware('permission:documents.read')->whereUuid('id');
    Route::post('provider-disputes/{id}/resolve', [W::class, 'resolveDispute'])->middleware(['permission:health.provider_claims.adjudicate', 'idempotency:health.provider_dispute.resolve'])->whereUuid('id');
});

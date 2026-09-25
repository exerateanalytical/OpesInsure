<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Approvals\ApprovalController as C;
use Illuminate\Support\Facades\Route;

/*
 | REQ-RBAC-005 / REQ-RBAC-006 / REQ-SET-005 — central approvals (WF-081, ESR ADM-029).
 | Loaded by App\Providers\ApprovalServiceProvider under the `api` group. Decisions are further gated by the
 | approval matrix (checker permission / roles) and segregation of duties inside ApprovalService.
 | Configuration change-set approve/reject goes through approvals/{id}/approve|reject (one decision path).
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('approvals', [C::class, 'index'])->middleware('permission:approvals.inbox.view');
    Route::get('approvals/actions', [C::class, 'actions'])->middleware('permission:approvals.inbox.view');
    Route::get('approvals/matrix', [C::class, 'matrix'])->middleware('permission:approvals.matrix.view');
    Route::get('approvals/matrix/resolve', [C::class, 'resolve'])->middleware('permission:approvals.matrix.view');
    Route::get('approvals/{approval}', [C::class, 'show'])->whereUuid('approval')->middleware('permission:approvals.inbox.view');
    Route::post('approvals/{approval}/cancel', [C::class, 'cancel'])->whereUuid('approval')->middleware('permission:approvals.inbox.view');
    Route::post('approvals/{approval}/{decision}', [C::class, 'decide'])->whereUuid('approval')->whereIn('decision', ['approve', 'reject'])->middleware('permission:approvals.decide');

    Route::get('configuration-changes', [C::class, 'configurationChanges'])->middleware('permission:configuration.changes.manage');
    Route::post('configuration-changes', [C::class, 'draftConfigurationChange'])->middleware('permission:configuration.changes.manage');
    Route::post('configuration-changes/{change}/{step}', [C::class, 'transitionConfigurationChange'])->whereUuid('change')->whereIn('step', ['submit', 'publish'])->middleware('permission:configuration.changes.manage');
});

<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Customers\PartyGoldenRecordController as C;
use Illuminate\Support\Facades\Route;

/*
 | Batch 4A party golden record (App\Providers\PartyGoldenRecordServiceProvider).
 | REQ-PTY-002 roles · REQ-PTY-003 relationships, ownership, UBO, graph · REQ-PTY-004 matching + maker-checker merge.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api', 'throttle:60,1'])->group(function (): void {
    // REQ-CRM-002 — access decided by DataScopeResolver inside the service (own / assigned / carrier / tenant)
    Route::get('customers/{party}/overview', [C::class, 'overview'])->whereUuid('party');
    Route::get('party-roles/catalogue', [C::class, 'roleCatalogue'])->middleware('permission:parties.manage');
    Route::get('party-roles', [C::class, 'contextRoles'])->middleware('permission:parties.manage');
    Route::post('party-roles/{role}/end', [C::class, 'endRole'])->middleware('permission:parties.roles.manage')->whereUuid('role');
    Route::get('parties/{party}/roles', [C::class, 'roles'])->middleware('permission:parties.manage')->whereUuid('party');
    Route::post('parties/{party}/roles', [C::class, 'assignRole'])->middleware('permission:parties.roles.manage')->whereUuid('party');

    Route::get('parties/{party}/relationships', [C::class, 'relationships'])->middleware('permission:parties.manage')->whereUuid('party');
    Route::post('parties/{party}/relationships', [C::class, 'link'])->middleware('permission:parties.relationships.manage')->whereUuid('party');
    Route::post('party-relationships/{relationship}/end', [C::class, 'endLink'])->middleware('permission:parties.relationships.manage')->whereUuid('relationship');
    Route::get('parties/{party}/ownership', [C::class, 'ownership'])->middleware('permission:parties.manage')->whereUuid('party');
    Route::post('parties/{party}/ownership', [C::class, 'addOwnership'])->middleware('permission:parties.relationships.manage')->whereUuid('party');
    Route::post('ownership-interests/{interest}/end', [C::class, 'endOwnership'])->middleware('permission:parties.relationships.manage')->whereUuid('interest');
    Route::get('parties/{party}/ubo', [C::class, 'ubo'])->middleware('permission:parties.manage')->whereUuid('party');
    Route::get('parties/{party}/graph', [C::class, 'graph'])->middleware('permission:parties.manage')->whereUuid('party');

    Route::post('parties/{party}/match-scan', [C::class, 'scan'])->middleware('permission:parties.match.review')->whereUuid('party');
    Route::get('party-match-candidates', [C::class, 'candidates'])->middleware('permission:parties.match.review');
    Route::post('party-match-candidates/{candidate}/dismiss', [C::class, 'dismiss'])->middleware('permission:parties.match.review')->whereUuid('candidate');
    Route::post('party-merges', [C::class, 'requestMerge'])->middleware('permission:parties.merge.request');
    Route::get('party-merges/{merge}', [C::class, 'showMerge'])->middleware('permission:parties.match.review')->whereUuid('merge');
    Route::post('party-merges/{merge}/decision', [C::class, 'decideMerge'])->middleware('permission:parties.merge.approve')->whereUuid('merge');
    Route::post('party-merges/{merge}/unmerge', [C::class, 'unmerge'])->middleware('permission:parties.merge.approve')->whereUuid('merge');
});

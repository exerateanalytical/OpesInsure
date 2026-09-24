<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Temporal\TemporalResolveController;
use Illuminate\Support\Facades\Route;

/*
 | ICE E1 §1.10 — Temporal engine diagnostics (REQ-TMP-001/002).
 | Loaded by App\Providers\TemporalServiceProvider under the `api` group.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api', 'permission:trust.regulatory'])->group(function (): void {
    Route::get('admin/temporal/resolve', [TemporalResolveController::class, 'resolve']);
    Route::get('admin/temporal/resolved-versions/{subjectType}/{subjectId}', [TemporalResolveController::class, 'resolvedVersions'])->whereUuid('subjectId');
});

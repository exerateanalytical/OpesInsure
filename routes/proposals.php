<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\Underwriting\ProposalLifecycleController as Lifecycle;
use Illuminate\Support\Facades\Route;

/*
 | Batch 6D — proposal lifecycle (REQ-PRP-001…005). Loaded by App\Providers\ProposalServiceProvider under `api`.
 | The existing proposal routes (routes/api.php, wave14_mobile.php, wave16_lifecycle.php) are unchanged.
 */
Route::prefix('v1/proposals/{proposal}')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('checklist', [Lifecycle::class, 'checklist']);
    Route::post('declarations', [Lifecycle::class, 'declare']);
    Route::put('cover-terms', [Lifecycle::class, 'coverTerms']);
    Route::post('resubmit', [Lifecycle::class, 'resubmit']);
    Route::post('withdraw', [Lifecycle::class, 'withdraw']);
    Route::get('submissions', [Lifecycle::class, 'submissions']);
    Route::post('information-requests', [Lifecycle::class, 'requestInformation'])->middleware('permission:underwriting.decide');
    Route::get('issuability', [Lifecycle::class, 'issuability'])->middleware('permission:proposals.issuability.read');
});

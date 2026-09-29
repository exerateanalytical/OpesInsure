<?php

declare(strict_types=1);

use App\Application\Ledger\Periods\Http\AccountingPeriodController as C;
use Illuminate\Support\Facades\Route;

/*
 | Q10 2026-09-29 — FIN-023 financial period closing (AccountingPeriodService, until now console-only). Read with
 | ledger.read; close with ledger.periods.close; reopen maker-checker with ledger.periods.reopen.
 | Loaded by App\Application\Ledger\Periods\LedgerPeriodsServiceProvider.
 */
Route::prefix('v1/ledger/periods')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('/', [C::class, 'index'])->middleware('permission:ledger.read');
    Route::get('{period}/checklist', [C::class, 'checklist'])->middleware('permission:ledger.read')->whereUuid('period');
    Route::post('{period}/start-close', [C::class, 'startClose'])->middleware('permission:ledger.periods.close')->whereUuid('period');
    Route::post('{period}/close', [C::class, 'close'])->middleware('permission:ledger.periods.close')->whereUuid('period');
    Route::post('{period}/reopen-requests', [C::class, 'requestReopen'])->middleware('permission:ledger.periods.reopen')->whereUuid('period');
    Route::post('{period}/reopen-requests/approve', [C::class, 'approveReopen'])->middleware('permission:ledger.periods.reopen')->whereUuid('period');
    Route::post('{period}/reopen-requests/reject', [C::class, 'rejectReopen'])->middleware('permission:ledger.periods.reopen')->whereUuid('period');
});

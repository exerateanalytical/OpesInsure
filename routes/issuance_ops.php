<?php

declare(strict_types=1);

use App\Application\Policies\IssuanceQueue\Http\IssuanceQueueController;
use App\Application\Stickers\Http\StickerCustodyController;
use Illuminate\Support\Facades\Route;

/*
 | Batch 7D — issuance operations. Loaded by App\Application\Policies\IssuanceQueue\IssuanceOpsServiceProvider.
 | REQ-POL-004: failed / paid-not-issued issuance queue. REQ-POL-007: motor sticker custody chain.
 */
Route::prefix('v1')->middleware(['auth:api', 'tenant', 'json.api'])->group(function (): void {
    Route::get('issuance-exceptions', [IssuanceQueueController::class, 'index'])->middleware('permission:policies.issuance_queue.view');
    Route::get('issuance-exceptions/{exception}', [IssuanceQueueController::class, 'show'])->whereUuid('exception')->middleware('permission:policies.issuance_queue.view');
    Route::post('issuance-exceptions/scan', [IssuanceQueueController::class, 'scan'])->middleware('permission:policies.issuance_queue.manage');
    Route::post('issuance-exceptions/{exception}/retry', [IssuanceQueueController::class, 'retry'])->whereUuid('exception')->middleware('permission:policies.issuance_queue.manage');
    Route::post('issuance-exceptions/{exception}/escalate', [IssuanceQueueController::class, 'escalate'])->whereUuid('exception')->middleware('permission:policies.issuance_queue.manage');
    Route::post('issuance-exceptions/{exception}/resolve', [IssuanceQueueController::class, 'resolve'])->whereUuid('exception')->middleware('permission:policies.issuance_queue.resolve');

    Route::get('stickers/inventory', [StickerCustodyController::class, 'inventory'])->middleware('permission:stickers.view');
    Route::get('stickers/{serial}/custody', [StickerCustodyController::class, 'history'])->middleware('permission:stickers.view');
    Route::get('sticker-handovers', [StickerCustodyController::class, 'handovers'])->middleware('permission:stickers.view');
    Route::post('sticker-handovers', [StickerCustodyController::class, 'initiate'])->middleware('permission:stickers.handover');
    Route::post('sticker-handovers/{handover}/accept', [StickerCustodyController::class, 'accept'])->whereUuid('handover')->middleware('permission:stickers.handover');
    Route::post('sticker-handovers/{handover}/reject', [StickerCustodyController::class, 'reject'])->whereUuid('handover')->middleware('permission:stickers.handover');
    Route::post('sticker-handovers/{handover}/cancel', [StickerCustodyController::class, 'cancel'])->whereUuid('handover')->middleware('permission:stickers.handover');
    Route::post('sticker-reconciliations', [StickerCustodyController::class, 'reconcile'])->middleware('permission:stickers.reconcile');
    Route::post('policies/{policy}/sticker', [StickerCustodyController::class, 'assign'])->whereUuid('policy')->middleware('permission:stickers.assign');
});

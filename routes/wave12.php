<?php

use App\Interfaces\Http\Controllers\Api\V1\Uploads\ResumableUploadController;
use Illuminate\Support\Facades\Route;

// Generic resumable/chunked upload — see App\Application\Uploads\ResumableUploadService.
// `start` is guarded by the idempotency middleware as its worked example:
// a client retrying a dropped "create session" call must not end up with
// two orphaned upload sessions consuming double storage.
Route::post('mobile/uploads', [ResumableUploadController::class, 'start'])->middleware('idempotency:mobile.uploads.start');
Route::get('mobile/uploads/{upload}/status', [ResumableUploadController::class, 'status']);
Route::put('mobile/uploads/{upload}/chunks/{index}', [ResumableUploadController::class, 'putChunk'])->middleware('throttle:120,1');
Route::post('mobile/uploads/{upload}/finalize', [ResumableUploadController::class, 'finalize']);

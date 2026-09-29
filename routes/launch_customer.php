<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileActivityController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileComplaintController;
use Illuminate\Support\Facades\Route;

/*
 | Launch 2026-10-02 (Q2) — customer shared screens with no customer API yet. Included from routes/api.php inside the
 | auth:api + tenant + json.api group. Own-party / own-actor scoping is done in the controllers.
 |  SHR-013/014 complaints: thin layer over ComplaintService (REQ-CPL-001);
 |  SHR-008 activity: the caller's own audit_log rows.
 */
Route::get('mobile/complaints', [MobileComplaintController::class, 'index']);
Route::post('mobile/complaints', [MobileComplaintController::class, 'store'])->middleware('throttle:5,1');
Route::get('mobile/complaints/{complaint}', [MobileComplaintController::class, 'show']);
Route::get('mobile/account/activity', MobileActivityController::class);

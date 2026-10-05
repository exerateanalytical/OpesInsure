<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileActivityController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileComplaintController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileCustomerMoneyController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileSettlementDischargeController;
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

/*
 | Launch 2026-10-02 — customer missing flows (fix agent K). Own-party scoping in the controllers.
 |  REQ-PAY-006 instalment schedule + pay one instalment (double-charge guarded, per instalment);
 |  REQ-PAY-009 refunds of an own payment (and the /refunds/{id} notification deep link);
 |  REQ-CLM-013 discharge signing / decline and the payout destination on the customer's own settlement.
 */
Route::get('mobile/policies/{policy}/instalments', [MobileCustomerMoneyController::class, 'instalments']);
Route::post('mobile/policies/{policy}/instalments/{instalment}/pay', [MobileCustomerMoneyController::class, 'payInstalment'])->middleware('throttle:10,1');
Route::get('mobile/payments/{payment}/refunds', [MobileCustomerMoneyController::class, 'paymentRefunds']);
Route::get('mobile/refunds/{refund}', [MobileCustomerMoneyController::class, 'refundShow']);
Route::post('mobile/claims/{claim}/settlement/discharge/sign', [MobileSettlementDischargeController::class, 'sign'])->middleware(['step-up:CLAIM_SETTLEMENT_DECISION', 'throttle:10,1']);
Route::post('mobile/claims/{claim}/settlement/discharge/decline', [MobileSettlementDischargeController::class, 'decline'])->middleware('throttle:10,1');
Route::put('mobile/claims/{claim}/settlement/payout', [MobileSettlementDischargeController::class, 'payout'])->middleware(['step-up:PAYOUT_DESTINATION_CHANGE', 'throttle:10,1']);

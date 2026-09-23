<?php

use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileBrokerOpsController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileCarrierOpsController;
use App\Interfaces\Http\Controllers\Api\V1\FinancialDistribution\MobileBrokerFinanceController;
use App\Interfaces\Http\Controllers\Api\V1\FinancialDistribution\MobileCarrierFinanceController;
use Illuminate\Support\Facades\Route;

// Broker/carrier-side mobile dashboard: read-only views over the Wave6
// FinancialDistribution domain (commission accruals, partner statements,
// carrier settlements, bordereaux). Ownership: PartyResolver::partnerForUser
// for partner-scoped data (commission accruals/statements), plain tenant
// scoping for tenant-wide financial documents (settlements/bordereaux) —
// see MobilePartnerFinanceService / MobileCarrierFinanceService docblocks
// and the batch report for why the split follows the schema, not the
// calling persona.
Route::get('mobile/broker/dashboard', [MobileBrokerOpsController::class, 'dashboard']);
Route::get('mobile/broker/receivables', [MobileBrokerOpsController::class, 'receivables']);
Route::get('mobile/broker/commission-accruals', [MobileBrokerFinanceController::class, 'commissionAccruals']);
Route::get('mobile/broker/statements', [MobileBrokerFinanceController::class, 'statements']);
Route::get('mobile/broker/statements/{statement}', [MobileBrokerFinanceController::class, 'statement']);

Route::get('mobile/carrier/dashboard', [MobileCarrierOpsController::class, 'dashboard']);
Route::get('mobile/carrier/settlements', [MobileCarrierOpsController::class, 'settlements']);
Route::get('mobile/carrier/settlements/{settlement}', [MobileCarrierFinanceController::class, 'settlement']);
Route::get('mobile/carrier/bordereaux', [MobileCarrierFinanceController::class, 'bordereaux']);
Route::get('mobile/carrier/bordereaux/{bordereau}', [MobileCarrierFinanceController::class, 'bordereau']);

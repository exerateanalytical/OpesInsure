<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileAccountController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileAgentPortalController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileBrokerOpsController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileCarrierOpsController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileClaimCompletionController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileDeviceAttestationController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileDisclosureController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileNotificationController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobilePolicyServiceController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileSupportController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileWorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 | Wave 14 — every mobile screen wired to the platform. Included from
 | routes/api.php inside the auth:api + tenant + json.api group. See
 | docs/HANDOVER_MOBILE_PLATFORM_AUDIT.md for the audit this closes.
 */

// account/*
Route::get('mobile/account/devices', [MobileAccountController::class, 'devices']);
Route::delete('mobile/account/devices/{device}', [MobileAccountController::class, 'revokeDevice']);
Route::patch('mobile/account/profile', [MobileAccountController::class, 'updateProfile']);
Route::put('mobile/account/locale', [MobileAccountController::class, 'setLocale']);
Route::get('mobile/account/notification-preferences', [MobileAccountController::class, 'notificationPreferences']);
Route::put('mobile/account/notification-preferences', [MobileAccountController::class, 'saveNotificationPreferences']);
Route::post('mobile/account/push-tokens', [MobileAccountController::class, 'registerPush']);

// notifications/*
Route::get('mobile/notifications', [MobileNotificationController::class, 'index']);
Route::post('mobile/notifications/read-all', [MobileNotificationController::class, 'markAllRead']);
Route::get('mobile/notifications/{notification}', [MobileNotificationController::class, 'show']);
Route::post('mobile/notifications/{notification}/read', [MobileNotificationController::class, 'markRead']);

// support/*
Route::get('mobile/support/cases', [MobileSupportController::class, 'index']);
Route::post('mobile/support/cases', [MobileSupportController::class, 'store'])->middleware('throttle:10,1');
Route::get('mobile/support/cases/{case}', [MobileSupportController::class, 'show']);
Route::post('mobile/support/cases/{case}/messages', [MobileSupportController::class, 'message'])->middleware('throttle:30,1');
Route::post('mobile/support/cases/{case}/attachments', [MobileSupportController::class, 'attachment'])->middleware('throttle:10,1');

// services/* and the policy detail actions
Route::get('mobile/policy-service-requests', [MobilePolicyServiceController::class, 'index']);
Route::post('mobile/policy-service-requests', [MobilePolicyServiceController::class, 'store'])->middleware('throttle:10,1');
Route::get('mobile/policy-service-requests/{id}', [MobilePolicyServiceController::class, 'show']);
Route::post('mobile/policy-service-requests/{id}/messages', [MobilePolicyServiceController::class, 'message'])->middleware('throttle:30,1');
Route::post('policies/{policy}/service-requests', [MobilePolicyServiceController::class, 'store'])->middleware('throttle:10,1');
Route::post('policies/{policy}/renewal-quote', [MobilePolicyServiceController::class, 'renewalQuote'])->middleware('throttle:10,1');

// claim/[id]/* completion + emergency + appeal
Route::get('mobile/claims/{claim}/incident', [MobileClaimCompletionController::class, 'incident']);
Route::put('mobile/claims/{claim}/incident', [MobileClaimCompletionController::class, 'saveIncident']);
Route::get('mobile/claims/{claim}/evidence-requirements', [MobileClaimCompletionController::class, 'evidenceRequirements']);
Route::get('mobile/claims/{claim}/inspection', [MobileClaimCompletionController::class, 'inspection']);
Route::post('mobile/claims/{claim}/inspection/reschedule', [MobileClaimCompletionController::class, 'rescheduleInspection'])->middleware('throttle:10,1');
Route::get('mobile/claims/{claim}/repair', [MobileClaimCompletionController::class, 'repair']);
Route::get('mobile/claims/{claim}/settlement', [MobileClaimCompletionController::class, 'settlement']);
Route::post('mobile/claims/{claim}/settlement/decision', [MobileClaimCompletionController::class, 'decideSettlement'])->middleware(['step-up:CLAIM_SETTLEMENT_DECISION', 'throttle:10,1']);
Route::post('mobile/claims/{claim}/appeals', [MobileClaimCompletionController::class, 'appeal'])->middleware('throttle:10,1');
Route::post('mobile/claims/emergency-assistance', [MobileClaimCompletionController::class, 'emergency'])->middleware('throttle:5,1');

// quote/questions + quote/terms adapters onto ProposalService
Route::get('proposals/{proposal}/disclosure', [MobileDisclosureController::class, 'session']);
Route::put('proposals/{proposal}/disclosure/answers', [MobileDisclosureController::class, 'answers']);
Route::post('proposals/{proposal}/disclosure/submit', [MobileDisclosureController::class, 'submit']);
Route::post('proposals/{proposal}/terms', [MobileDisclosureController::class, 'terms']);

// agent/* (app-shaped; replaces the wave12 agent-mode list routes)
Route::get('mobile/agent/dashboard', [MobileAgentPortalController::class, 'dashboard'])->middleware('permission:agent.clients.read');
Route::get('mobile/agent/profile', [MobileAgentPortalController::class, 'profile'])->middleware('permission:agent.clients.read');
Route::patch('mobile/agent/profile', [MobileAgentPortalController::class, 'updateProfile'])->middleware('permission:agent.clients.read');
Route::get('mobile/agent/renewals', [MobileAgentPortalController::class, 'renewals'])->middleware('permission:agent.clients.read');
Route::post('mobile/agent/sales', [MobileAgentPortalController::class, 'createSale'])->middleware(['permission:agent.clients.manage', 'throttle:20,1']);
Route::get('mobile/agent/sales/{id}', [MobileAgentPortalController::class, 'sale'])->middleware('permission:agent.clients.read');
Route::post('mobile/agent/sales/{id}/payment-request', [MobileAgentPortalController::class, 'requestPayment'])->middleware(['permission:agent.clients.manage', 'throttle:20,1']);

// broker/*
Route::get('mobile/broker/clients', [MobileBrokerOpsController::class, 'clients']);
Route::get('mobile/broker/clients/{customer}', [MobileBrokerOpsController::class, 'client']);
Route::get('mobile/broker/production', [MobileBrokerOpsController::class, 'production']);
Route::get('mobile/broker/renewals', [MobileBrokerOpsController::class, 'renewals']);
Route::get('mobile/broker/compliance', [MobileBrokerOpsController::class, 'compliance']);
Route::get('mobile/broker/marketplace-publications', [MobileBrokerOpsController::class, 'publications']);
Route::patch('mobile/broker/marketplace-publications/{id}', [MobileBrokerOpsController::class, 'togglePublication'])->middleware('throttle:20,1');

// carrier/*
Route::get('mobile/carrier/referrals', [MobileCarrierOpsController::class, 'referrals']);
Route::get('mobile/carrier/referrals/{id}', [MobileCarrierOpsController::class, 'referral']);
Route::post('mobile/carrier/referrals/{id}/decision', [MobileCarrierOpsController::class, 'decideReferral'])->middleware(['permission:carrier.referrals.decide', 'throttle:20,1']);
Route::get('mobile/carrier/issuance', [MobileCarrierOpsController::class, 'issuance']);
Route::get('mobile/carrier/claims', [MobileCarrierOpsController::class, 'claims']);

// workspace/[role]
Route::get('mobile/workspace/dashboard', [MobileWorkspaceController::class, 'dashboard']);
Route::get('mobile/workspace/modules/{key}', [MobileWorkspaceController::class, 'module']);

// security/device-status
Route::post('mobile/security/device-attestation/nonce', [MobileDeviceAttestationController::class, 'nonce'])->middleware('throttle:20,1');
Route::post('mobile/security/device-attestation/assess', [MobileDeviceAttestationController::class, 'assess'])->middleware('throttle:20,1');

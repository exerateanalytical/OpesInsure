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
// Multipart upload (field "file"), so it is exempt from the JSON-only guard; the app (client.ts SupportApi.upload) and the web send FormData.
Route::post('mobile/support/cases/{case}/attachments', [MobileSupportController::class, 'attachment'])->middleware('throttle:10,1')->withoutMiddleware('json.api');

// services/* and the policy detail actions
Route::get('mobile/policy-service-requests', [MobilePolicyServiceController::class, 'index']);
// REQ-DUP-014: canonical create is policies/{policy}/service-requests (below); this alias
// stays for installed app 1.2.2 (client.ts createServiceCase).
Route::post('mobile/policy-service-requests', [MobilePolicyServiceController::class, 'store'])->middleware(['throttle:10,1', \App\Interfaces\Http\Middleware\DeprecatedRouteAlias::using('policies/{policy}/service-requests', 'REQ-DUP-014')]);
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
Route::patch('mobile/agent/profile', [MobileAgentPortalController::class, 'updateProfile'])->middleware(['permission:agent.clients.read', 'throttle:10,1']);
Route::get('mobile/agent/renewals', [MobileAgentPortalController::class, 'renewals'])->middleware('permission:agent.clients.read');
Route::post('mobile/agent/sales', [MobileAgentPortalController::class, 'createSale'])->middleware(['permission:agent.clients.manage', 'throttle:20,1']);
Route::get('mobile/agent/sales/{id}', [MobileAgentPortalController::class, 'sale'])->middleware('permission:agent.clients.read');
Route::post('mobile/agent/sales/{id}/payment-request', [MobileAgentPortalController::class, 'requestPayment'])->middleware(['permission:agent.clients.manage', 'throttle:20,1']);

// broker/*
Route::get('mobile/broker/clients', [MobileBrokerOpsController::class, 'clients'])->middleware('permission:broker.portal.read');
// Broker client onboarding: the new client is origin-locked to the caller's own BROKER partner (so it is in the book at once).
Route::post('mobile/broker/clients', [MobileBrokerOpsController::class, 'createClient'])->middleware(['permission:crm.leads.manage', 'idempotency:mobile.broker.clients.store', 'throttle:10,1']);
Route::get('mobile/broker/clients/{customer}', [MobileBrokerOpsController::class, 'client'])->middleware('permission:broker.portal.read');
Route::get('mobile/broker/production', [MobileBrokerOpsController::class, 'production'])->middleware('permission:broker.portal.read');
Route::get('mobile/broker/renewals', [MobileBrokerOpsController::class, 'renewals'])->middleware('permission:broker.portal.read');
Route::get('mobile/broker/compliance', [MobileBrokerOpsController::class, 'compliance'])->middleware('permission:broker.portal.read');
// Phase-1 fix S (2026-09-30): per-id reads for the production and compliance detail screens.
Route::get('mobile/broker/production/{policy}', [MobileBrokerOpsController::class, 'productionItem'])->middleware('permission:broker.portal.read')->whereUuid('policy');
Route::get('mobile/broker/compliance/{id}', [MobileBrokerOpsController::class, 'complianceItem'])->middleware('permission:broker.portal.read')->whereUuid('id');
Route::get('mobile/broker/marketplace-publications', [MobileBrokerOpsController::class, 'publications'])->middleware('permission:broker.portal.read');
Route::patch('mobile/broker/marketplace-publications/{id}', [MobileBrokerOpsController::class, 'togglePublication'])->middleware(['permission:broker.marketplace.manage', 'throttle:20,1']);

// Intermediary desk 2026-09-30 (MobileIntermediaryDeskController): broker assisted sale, broker/agent renewal actions,
// broker claimable policies. Book-scoped server-side; the `portal` default picks the broker or agent book.
$desk = \App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileIntermediaryDeskController::class;
Route::post('mobile/broker/sales', [$desk, 'createSale'])->middleware(['permission:quotes.manage', 'permission:quotes.rate', 'throttle:20,1']);
Route::get('mobile/broker/sales/{id}', [$desk, 'sale'])->middleware('permission:broker.portal.read')->whereUuid('id');
Route::post('mobile/broker/sales/{id}/payment-request', [$desk, 'advanceSale'])->middleware(['permission:quotes.manage', 'throttle:20,1'])->whereUuid('id');
Route::get('mobile/broker/claimable-policies', [$desk, 'claimablePolicies'])->middleware('permission:broker.claims.file');
Route::get('mobile/broker/renewals/{policy}', [$desk, 'renewal'])->middleware('permission:broker.portal.read')->whereUuid('policy')->defaults('portal', 'broker');
Route::post('mobile/broker/renewals/{policy}/requote', [$desk, 'requote'])->middleware(['permission:broker.renewals.manage', 'permission:quotes.rate', 'throttle:10,1'])->whereUuid('policy')->defaults('portal', 'broker');
Route::post('mobile/broker/renewals/{policy}/decline', [$desk, 'declineRenewal'])->middleware(['permission:broker.renewals.manage', 'throttle:10,1'])->whereUuid('policy')->defaults('portal', 'broker');
Route::get('mobile/agent/renewals/{policy}', [$desk, 'agentRenewal'])->middleware('permission:agent.clients.read')->whereUuid('policy')->defaults('portal', 'agent');
Route::post('mobile/agent/renewals/{policy}/requote', [$desk, 'agentRequote'])->middleware(['permission:agent.clients.manage', 'permission:quotes.rate', 'throttle:10,1'])->whereUuid('policy')->defaults('portal', 'agent');
Route::post('mobile/agent/renewals/{policy}/decline', [$desk, 'agentDeclineRenewal'])->middleware(['permission:agent.clients.manage', 'throttle:10,1'])->whereUuid('policy')->defaults('portal', 'agent');

// carrier/*
Route::get('mobile/carrier/referrals', [MobileCarrierOpsController::class, 'referrals'])->middleware('permission:carrier.referrals.read');
Route::get('mobile/carrier/referrals/{id}', [MobileCarrierOpsController::class, 'referral'])->middleware('permission:carrier.referrals.read');
Route::post('mobile/carrier/referrals/{id}/decision', [MobileCarrierOpsController::class, 'decideReferral'])->middleware(['permission:carrier.referrals.decide', 'throttle:20,1']);
Route::get('mobile/carrier/issuance', [MobileCarrierOpsController::class, 'issuance'])->middleware('permission:carrier.issuance.read');
// Mobile audit E2: issuance maker-checker (approve/reject: mobile/partner/carrier/issuance/{id}/approve|reject).
Route::get('mobile/carrier/issuance/{id}', [\App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileCarrierIssuanceController::class, 'show'])->middleware('permission:carrier.issuance.read');
Route::post('mobile/carrier/issuance/{id}/request-correction', [\App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileCarrierIssuanceController::class, 'requestCorrection'])->middleware(['permission:carrier.referrals.decide', 'throttle:20,1']);
Route::post('mobile/carrier/issuance/{id}/verify', [\App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileCarrierIssuanceController::class, 'verify'])->middleware(['permission:carrier.referrals.decide', 'throttle:20,1']);
Route::post('mobile/carrier/issuance/{id}/second-approve', [\App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileCarrierIssuanceController::class, 'secondApprove'])->middleware(['permission:carrier.referrals.decide', 'throttle:20,1']);
Route::get('mobile/carrier/claims', [MobileCarrierOpsController::class, 'claims'])->middleware('permission:carrier.claims.read');

// workspace/[role]
Route::get('mobile/workspace/dashboard', [MobileWorkspaceController::class, 'dashboard'])->middleware('permission:workspace.read');
Route::get('mobile/workspace/modules/{key}', [MobileWorkspaceController::class, 'module'])->middleware('permission:workspace.read');
// Phase-1 fix S (2026-09-30): the workspace claims module (scoped list, detail, the caller's claim actions).
Route::get('mobile/workspace/claims', [\App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileWorkspaceClaimsController::class, 'index'])->middleware(['permission:workspace.read', 'permission:claims.view']);
Route::get('mobile/workspace/claims/{claim}', [\App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileWorkspaceClaimsController::class, 'show'])->middleware(['permission:workspace.read', 'permission:claims.view']);
Route::post('mobile/workspace/claims/{claim}/assign-to-me', [\App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileWorkspaceClaimsController::class, 'assignToMe'])->middleware(['permission:workspace.read', 'permission:claims.assign', 'throttle:30,1']);
Route::post('mobile/workspace/claims/{claim}/transitions', [\App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileWorkspaceClaimsController::class, 'transition'])->middleware(['permission:workspace.read', 'permission:claims.transition', 'throttle:30,1']);
Route::post('mobile/workspace/claims/{claim}/decisions', [\App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileWorkspaceClaimsController::class, 'proposeDecision'])->middleware(['permission:workspace.read', 'permission:claims.decision.propose', 'throttle:20,1']);
Route::post('mobile/workspace/claims/{claim}/decisions/{decision}/approve', [\App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileWorkspaceClaimsController::class, 'approveDecision'])->middleware(['permission:workspace.read', 'permission:claims.decision.approve', 'throttle:20,1']);

// security/device-status
Route::post('mobile/security/device-attestation/nonce', [MobileDeviceAttestationController::class, 'nonce'])->middleware('throttle:20,1');
Route::post('mobile/security/device-attestation/assess', [MobileDeviceAttestationController::class, 'assess'])->middleware('throttle:20,1');

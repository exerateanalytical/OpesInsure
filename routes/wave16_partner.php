<?php

declare(strict_types=1);

use App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace\PartnerAgentWorkspaceController as Agent;
use App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace\PartnerBrokerWorkspaceController as Broker;
use App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace\PartnerCarrierWorkspaceController as Carrier;
use App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace\PartnerStaffSecurityController as StaffSecurity;
use Illuminate\Support\Facades\Route;

/*
 | Wave 16 — partner workspaces (agent / broker / insurer menus from the
 | 2026-09-24 checklist audit, section D). Included from routes/api.php
 | inside the auth:api + tenant + json.api group. Every route is
 | permission-gated with the role permissions the partner roles already
 | carry (RoleCatalogue), and every controller additionally scopes rows to
 | the caller's own Partner (AgentPartnerResolver / PartyResolver) or
 | carrier (CarrierScopeResolver) — a customer gets 403 at the gate.
 */

// ------------------------------------------------------------------ agent
Route::get('mobile/partner/agent/leads', [Agent::class, 'leads'])->middleware('permission:agent.clients.read');
Route::post('mobile/partner/agent/leads', [Agent::class, 'createLead'])->middleware(['permission:agent.clients.manage', 'throttle:30,1']);
Route::get('mobile/partner/agent/leads/{lead}', [Agent::class, 'lead'])->middleware('permission:agent.clients.read');
Route::patch('mobile/partner/agent/leads/{lead}', [Agent::class, 'updateLead'])->middleware(['permission:agent.clients.manage', 'throttle:30,1']);
Route::post('mobile/partner/agent/leads/{lead}/convert', [Agent::class, 'convertLead'])->middleware(['permission:agent.clients.manage', 'throttle:10,1']);
Route::post('mobile/partner/agent/clients', [Agent::class, 'createClient'])->middleware(['permission:agent.clients.manage', 'throttle:10,1']);
Route::get('mobile/partner/agent/quotes', [Agent::class, 'quotes'])->middleware('permission:agent.clients.read');
Route::get('mobile/partner/agent/policies', [Agent::class, 'policies'])->middleware('permission:agent.clients.read');
Route::get('mobile/partner/agent/proposals', [Agent::class, 'proposals'])->middleware('permission:agent.clients.read');
Route::get('mobile/partner/agent/claims', [Agent::class, 'claims'])->middleware('permission:agent.clients.read');
Route::get('mobile/partner/agent/clients/{customer}/documents', [Agent::class, 'clientDocuments'])->middleware('permission:agent.clients.read')->whereUuid('customer');
// Q3 launch: agent-assisted KYC and document capture for a client in the agent's own book (AGT-014/015/030/033).
Route::get('mobile/partner/agent/clients/{customer}/kyc', [\App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace\AgentClientAssistController::class, 'kyc'])->middleware('permission:agent.clients.read')->whereUuid('customer');
Route::post('mobile/partner/agent/clients/{customer}/documents', [\App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace\AgentClientAssistController::class, 'upload'])->middleware(['permission:agent.clients.manage', 'throttle:20,1'])->whereUuid('customer');
Route::post('mobile/partner/agent/clients/{customer}/kyc/documents', [\App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace\AgentClientAssistController::class, 'attach'])->middleware(['permission:agent.clients.manage', 'throttle:20,1'])->whereUuid('customer');
Route::post('mobile/partner/agent/clients/{customer}/kyc/submit', [\App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace\AgentClientAssistController::class, 'submit'])->middleware(['permission:agent.clients.manage', 'throttle:10,1'])->whereUuid('customer');

// ----------------------------------------------------------------- broker
Route::get('mobile/partner/broker/quotes', [Broker::class, 'quotes'])->middleware('permission:broker.portal.read');
Route::get('mobile/partner/broker/policies', [Broker::class, 'policies'])->middleware('permission:broker.portal.read');
Route::get('mobile/partner/broker/claims', [Broker::class, 'claims'])->middleware('permission:broker.portal.read');
Route::get('mobile/partner/broker/proposals', [Broker::class, 'proposals'])->middleware('permission:broker.portal.read');
// Phase-1 fix S (2026-09-30): per-id reads bounded by the broker's book.
Route::get('mobile/partner/broker/policies/{policy}', [Broker::class, 'policy'])->middleware('permission:broker.portal.read')->whereUuid('policy');
Route::get('mobile/partner/broker/claims/{claim}', [Broker::class, 'claim'])->middleware('permission:broker.portal.read')->whereUuid('claim');
Route::get('mobile/partner/broker/clients/{customer}/documents', [Broker::class, 'clientDocuments'])->middleware('permission:broker.portal.read')->whereUuid('customer');
Route::get('mobile/partner/broker/staff', [Broker::class, 'staff'])->middleware('permission:broker.portal.read');
Route::post('mobile/partner/broker/staff/invitations', [Broker::class, 'inviteStaff'])->middleware(['permission:broker.portal.read', 'throttle:10,1']);
Route::get('mobile/partner/broker/commissions', [Broker::class, 'commissions'])->middleware('permission:broker.finance.read');

// ---------------------------------------------------------------- insurer
Route::get('mobile/partner/carrier/products', [Carrier::class, 'products'])->middleware('permission:carrier.dashboard.read');
Route::post('mobile/partner/carrier/products/{product}/status', [Carrier::class, 'productStatus'])->middleware(['permission:carrier.authority.manage', 'throttle:20,1']);
Route::get('mobile/partner/carrier/proposals', [Carrier::class, 'proposals'])->middleware('permission:carrier.referrals.read');
Route::get('mobile/partner/carrier/policies', [Carrier::class, 'policies'])->middleware('permission:carrier.dashboard.read');
Route::get('mobile/partner/carrier/claims/{claim}', [Carrier::class, 'claim'])->middleware('permission:carrier.claims.read');
// Claim evidence for the insurer: metadata list + per-document short-lived signed URL (access logged),
// both scoped to claims on the caller's own carrier (404 otherwise). Claim PAYMENT is deliberately not
// exposed here: claims.payment.request|approve|execute and claims.settlement.pay are granted by the
// RoleCatalogue to platform claims/finance roles only (no CARRIER_* role), so the insurer decides
// (propose / approve decision) and the platform claims finance team pays (POST /claims/{id}/decisions/{d}/payments ...).
Route::get('mobile/partner/carrier/claims/{claim}/evidence', [Carrier::class, 'claimEvidence'])->middleware('permission:carrier.claims.read');
Route::post('mobile/partner/carrier/claims/{claim}/evidence/{document}/access', [Carrier::class, 'claimEvidenceAccess'])->middleware(['permission:carrier.claims.read', 'throttle:60,1']);
Route::post('mobile/partner/carrier/claims/{claim}/acknowledge', [Carrier::class, 'acknowledgeClaim'])->middleware(['permission:carrier.referrals.decide', 'throttle:30,1']);
Route::post('mobile/partner/carrier/claims/{claim}/request-information', [Carrier::class, 'requestClaimInformation'])->middleware(['permission:carrier.referrals.decide', 'throttle:30,1']);
Route::post('mobile/partner/carrier/claims/{claim}/decisions', [Carrier::class, 'proposeClaimDecision'])->middleware(['permission:carrier.referrals.decide', 'throttle:20,1']);
Route::post('mobile/partner/carrier/claims/{claim}/decisions/{decision}/approve', [Carrier::class, 'approveClaimDecision'])->middleware(['permission:carrier.authority.approve', 'throttle:20,1']);
Route::post('mobile/partner/carrier/issuance/{issuance}/approve', [Carrier::class, 'approveIssuance'])->middleware(['permission:carrier.referrals.decide', 'throttle:20,1']);
Route::post('mobile/partner/carrier/issuance/{issuance}/reject', [Carrier::class, 'rejectIssuance'])->middleware(['permission:carrier.referrals.decide', 'throttle:20,1']);
Route::get('mobile/partner/carrier/payments', [Carrier::class, 'payments'])->middleware('permission:carrier.finance.read');
Route::get('mobile/partner/carrier/partners', [Carrier::class, 'partners'])->middleware('permission:carrier.dashboard.read');
// Phase-1 fix S (2026-09-30): per-id reads (the app no longer finds a record in the first page of its list).
Route::get('mobile/partner/carrier/products/{product}', [Carrier::class, 'product'])->middleware('permission:carrier.dashboard.read')->whereUuid('product');
Route::get('mobile/partner/carrier/proposals/{proposal}', [Carrier::class, 'proposal'])->middleware('permission:carrier.referrals.read')->whereUuid('proposal');
Route::get('mobile/partner/carrier/policies/{policy}', [Carrier::class, 'policy'])->middleware('permission:carrier.dashboard.read')->whereUuid('policy');
Route::get('mobile/partner/carrier/payments/{payment}', [Carrier::class, 'payment'])->middleware('permission:carrier.finance.read')->whereUuid('payment');
Route::get('mobile/partner/carrier/partners/{partner}', [Carrier::class, 'partner'])->middleware('permission:carrier.dashboard.read')->whereUuid('partner');

// ------------------------------------------------------------------ staff security (mobile audit B4)
Route::get('partner/staff/{user}/security', [StaffSecurity::class, 'show'])->whereUuid('user')->middleware('permission:staff.security.read');
Route::post('partner/staff/{user}/suspend-access', [StaffSecurity::class, 'suspendAccess'])->whereUuid('user')->middleware(['permission:staff.security.manage', 'throttle:10,1']);
Route::post('partner/staff/{user}/force-reauth', [StaffSecurity::class, 'forceReauth'])->whereUuid('user')->middleware(['permission:staff.security.manage', 'throttle:10,1']);

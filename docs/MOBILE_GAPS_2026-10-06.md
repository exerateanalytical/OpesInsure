# Mobile gap scan, 2026-09-30 (open items as of 2026-10-06)

Phase-3 fix batches for these were paused mid-way on 2026-10-06; their partial edits are uncommitted in the working trees. Customer-side gaps 1-9 are closed (f40e7ec / mobile 075c827).

# Staff roles gap scan (insurer, claims, finance, operations, compliance, platform)

P0
1. Approvals inbox (four-eyes): routes/approvals.php:15-21 (GET approvals, approvals/{id}, POST approvals/{id}/{approve|reject}); perms approvals.inbox.view/approvals.decide (CARRIER_ADMIN + '*' roles). Build /carrier/approvals list+detail, approve/reject with reason, step-up; carrier home + workspace entry; notification target.
2. Underwriter referral case file: routes/api.php:199-204 underwriting/cases, cases/{case}, evaluate, start-review, ready-for-decision, information-requests. Referral payload thin (MobileCarrierOpsController.php:161-178). Placeholder app/carrier/referrals/[id].tsx:88. Build risk facts, disclosures, documents (signed), recommendation, start-review.
3. Conditional / counter-offer decisions: UnderwritingCaseMachine.php:23 supports CONDITIONAL, COUNTEROFFERED; mobile decide only APPROVE/DECLINE/MORE_INFORMATION (MobileCarrierOpsController.php:91, client.ts CarrierApi.decideReferral). Extend endpoint + form (conditions, loadings/exclusions, counter premium).
4. Claim reserves: routes/wave7.php:8-9 claims/{id}/reserves, approve (claims.reserve.approve). Reserve card on claim (current/pending/history, approve/reject).
5. Claim settlement calculate/offer/pay: api.php:834 calculate, :836 offer, :841 claim-settlements/{id}/payment (claims.settlement.pay). Settlement section.
6. Cashier sessions: api.php:479-484 list/open/show/collections/close/decide (cashier.sessions.operate/.approve). Cashier screen + approver queue.
7. Customer service support tickets: only POST support-tickets/{ticket}/transitions (api.php:301, support.manage). NEED backend staff ticket list/show/message endpoints; mobile queue + detail reply/assign/transition.

P1
8. Cancellation review: api.php:529-533 (policies.cancellation.review/.approve). Queue + detail (refund calc, review, approve/reject).
9. Reinstatement/suspension/recovery cases: api.php:536-548. Actions on carrier policy detail + reinstatement queue.
10. Carrier policy detail dead end: policies/[id].tsx:20 built from list. Need GET mobile/partner/carrier/policies/{id} + full detail with allowed_actions.
11. Policy service request triage: api.php:551 policy-service-requests (policies.service.approve).
12. Expert/adjuster + claim assignment: api.php:754,:758; wave7.php:6 claims/{id}/assign.
13. Recoveries: api.php:791-797 claim-recoveries store/receive/dispute/close.
14. Fraud outcome: api.php:771-772, :304.
15. Health pre-auth + provider claims: api.php:1024-1033, :905-909.
16. Refund review/approve/pay: api.php:503-510, :225.
17. Premium override approval: routes/quotes.php:22-23 (quotes.premium_override.approve).
18. Delegated authority + agreement approval: api.php:329-330; partners/[id].tsx:54 placeholder.
19. Insurer staff management: invitations api.php:145-146; wave16_partner.php:72-74 security; NO mobile/partner/carrier/staff list endpoint (backend gap).
20. KYC review queue: routes/kyc.php:13-40.
21. Compliance/AML cases: api.php:974-982.
22. Reinsurance facultative + RI recoveries approvals: api.php:875-880, :919-924.
23. Workspace shell: notifications, search, account; deep-link allowlist src/lib/customerLogic.ts:7-27 excludes /workspace/*; SearchRole src/lib/crm.ts:112.
24. Workspace module rows not tappable (no ids, 50-row cap, next_cursor null).
25. Branch KPIs: no backend endpoint.
26. Finance sees Issuance module but can't act (carrierAccess.ts:106 vs wave14_mobile.php:102-104 requiring carrier.referrals.decide).

P2
27. issuance/[id].tsx:190,227 placeholders. 28. claims/[id].tsx:207 claim file more. 29. Reconciliation exceptions (api.php:494-497) link out + checker approve. 30. Commission adjustments/ledger/FX web only. 31. Products/pricing web + approvals inbox. 32. Reports: few KPI cards + link out.
Placeholders (UnavailableSection) in carrier: policies/[id].tsx:50, claims/[id].tsx:207, referrals/[id].tsx:88, partners/[id].tsx:54, products/[id].tsx:61, issuance/[id].tsx:190,227, payments/[id].tsx:59 (11 total).

# Agent & broker gap scan

Key: none of 14 routes in routes/agent_servicing.php used by app; agent client KYC wave16_partner.php:34-37 unused.

P0
1. Agent policy servicing: agent_servicing.php:13 policy detail, :14 service request, :15-16 cancellation preview/request, :18-19 service requests list/detail; api.php:537 reinstatement. App PartnerPolicyDetail.tsx only renewal + assist claim. Build service action sheet (change, cancel preview->confirm, reinstate), app/agent/requests(.tsx,[id]); policies/[id] use per-id endpoint.
2. Agent client KYC: wave16_partner.php:34-37 (GET kyc, POST documents, attach, submit). Build Verify identity on client detail (web ref customers/show/kyc.blade.php).
3. Returned proposals dead-end (agent+broker): /proposals/{id}/checklist, /resubmit, /documents; PartnerProposalsScreen.tsx:76-77,131-133. Build app/{agent,broker}/proposals/[id].tsx (checklist, upload, reply+resubmit, withdraw) using ProposalLifecycleApi.
4. Motor stickers (agent+broker): agent_servicing.php:25 stock+handovers, :17 assign; issuance_ops.php:21-29 inventory/handovers/reconcile/assign. Build stickers page, accept/reject handover, assign on policy & end of sale; allocate/reconcile for supervisor/admin.
5. Broker admin payouts & disputes: wave6.php:7 partner-statements/{id}/payouts; api.php:601 dispute. On app/broker/commissions/[id].tsx.
6. Broker lead -> client: crm.ts:16 leadMoves drops CONVERTED; convert route agent-only wave16_partner.php:26. Add broker convert (endpoint or prefill clients/new + transition).
7. Broker policy servicing: api.php:527-528 cancellation, :537 reinstatement, wave14_mobile.php:54 service-requests; confirm broker callers allowed or add mobile/partner/broker/policies/{id}/...

P1
8. Quotes send/history/cancel: quotes.php:16 send (share link), :18 cancel, :19 history; QuoteWorkflowPanel.tsx.
9. Insurer price request + premium override: carrier_quote_requests.php:14-15; quotes.php:21.
10. Agent claim detail: agent_servicing.php:26 (timeline/evidence/pending); upload on behalf needs partner evidence endpoint.
11. Broker claim detail: no mobile/partner/broker/claims/{id} endpoint -> add.
12. Broker admin client KYC: kyc.php:26-29; add kyc_status to broker client rows (MobileBrokerOpsController::clientOf).
13. Broker bordereaux: api.php:608-609, :323-324, wave6.php:9.
14. Broker admin carrier agreements: platform_configuration.php:28-31.
15. Agent payments: agent_servicing.php:20-21.
16. Broker team mgmt: PartnerBrokerWorkspaceController.php:139 invite role hardcoded; no cancel/role/remove endpoints; staff/[id].tsx placeholder bkStaffAdminPending; email invite.
17. Broker admin paid-not-issued queue: issuance_ops.php:14-18.
18. Agent client vehicles: agent_servicing.php:22-24.

P2
19. Broker lead assign not gated (crm.leads.assign) broker/leads/[id].tsx:58-80.
20. Edit client: CapabilityResolver.php:189 advertises update; no route/UI.
21. Follow-up agenda (follow_up_at).
22. Agent claim entry points + policy picker; client detail file_for_client.
23. Broker compliance evidence upload (no backend route) + licence renewal.
24. Broker account page thin (PortalAccount) — reuse agent account rows.
25. Marketplace publish product (wave10.php:8).
26. Web-only tools: portfolio transfer, duplicates, e-sign, marine cargo, reports, aging.
27. Broker supervisor = staff; permission-gate supervisor powers.

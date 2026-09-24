# OpesInsure Canonical Screen-to-API Implementation Matrix v1.0 (owner, 2026-09-24)

Status: ACCEPTED as the target contract, subject to the reconciliation rules below. The owner's full text (38 sections) was supplied in session 2026-09-24; this file is the canonical record.

## Reconciliation rules (engineering)
1. The paths in the matrix name capabilities, not literal URLs. Where the platform already has an equivalent route (416 routes under /api/v1), it is mapped, not duplicated. Examples: `POST /claims/{id}/decision` → existing `claims/{id}/decisions` + `/approve`; `POST /claim-reserves/{r}/increase|reduce` → reserve movements on existing `claims/{id}/reserves`; `/claims/{id}/set-reserve` and `POST /claims/{id}/reserves` are the same action.
2. Vehicle generations/variants (CUST-007) are not in the vehicle master (makes/models only). Added as MISSING and treated as optional: the flow must allow Make → Model → details, with the "Not listed" fallback.
3. New endpoints follow Implementation Blueprint API rules: /api/v1 prefix, idempotency keys on mutations, a standard error envelope with machine codes (drives the §30 failure screens), and optimistic version checks (stale-record state).
4. Action-button contract (§25), completeness gate (§37) and traceability chain (§38) are adopted as the Definition of Done per screen and folded into TRACEABILITY_MATRIX_V1.md.
5. Missing API families identified on first pass (to be confirmed by the traceability audit): dashboards/*, quote-comparisons, authority-profiles/referrals, coverage/check, reconciliation, refunds, journals/trial-balance, providers/networks/preauthorizations, reinsurance/*, coinsurance/*, screenings/UBO, regulatory-rules impact, operations/exceptions, developer/clients/webhooks.

## Owner rules §22–37 (verbatim intent)
- §22 List screens: search, filters, sorting, pagination, saved filters, column selection, bulk selection, export, row actions, empty, no-result, error states.
- §23 Detail screens: header, canonical identifier, status, key metadata, available actions, tabs, timeline, tasks, documents, correspondence, audit.
- §24 Forms: field + server validation, required markers, help text, EN/FR labels, save draft, unsaved-change warning, permission-aware and conditional fields, master-data search, "Other / Not listed" fallback.
- §25 Action button = permission + authority rule + valid state transition + endpoint + audit event + success state + failure state (e.g. Approve Claim: claims.claim.decide / CLAIM_APPROVAL / DECISION_PENDING / POST claims/{id}/decisions / ClaimDecisionRecorded).
- §26 Timeline component (customer, quote, proposal, policy, claim, complaint, provider, reinsurance): timestamp, actor, role, event, result, reason, channel, related document.
- §27 Document viewer: preview, download, verification status, issuer, issue date, expiry, number, version, replacement relationship, QR verification; permission checks for sensitive docs.
- §28 Financial panel: amount, currency, status, source, payer, payee, allocations, reconciliation, journal reference, audit.
- §29 Authority widget: your authority, transaction amount, required authority, within authority?, referral required? — never expose internal rules to unauthorized users.
- §30 Failure experience: explicit states for payment ok/issuance failed, issued/document failed, claim payment failed, provider settlement failed, carrier API down, reinsurance API down, stale record, duplicate submission. Never a generic "Something went wrong" when the condition is known.
- §31 Mobile: primary action first, collapse secondary metadata, bottom sheets, progressive disclosure, camera/document capture, searchable selectors, minimum touch targets.
- §32 Desktop: multi-column, tables, split panels, timeline sidebars, bulk actions, advanced filters, comparison views.
- §33 Screen spec fields: screen_id, name, actor, platform, route, workflow_ids, permissions, authority_checks, entities, API reads/writes, events, documents, states, components, analytics.
- §37 Completeness gate: route, permissions, API integrated, loading, empty, validation, server errors, network failure, permission denied, stale data, success feedback, audit event verified, tests.
- Customer app screens CUST-001…028, agent, broker, insurer admin, product admin, underwriting, policy, claims, finance, provider network/portal, reinsurance, co-insurance, compliance, complaints, document admin, MDM, regulatory, operations exception centre and developer portal screens, each with the API families listed in the owner text (see TRACEABILITY_MATRIX_V1.md for route mapping).

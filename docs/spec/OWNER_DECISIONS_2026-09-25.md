# Owner decisions — resolution of open questions (2026-09-25)

Source: the owner's "Owner Open Questions — Resolution & Recommendations", supplied in session 2026-09-25. This file is the canonical record. It supersedes the matching items in OWNER_OPEN_QUESTIONS.md. Each item gives the decision, then its implementation status (IMPLEMENTED / TODO / BLOCKED-EXTERNAL).

## Locked baseline (owner's 32 points)
1. CIMA product mapping is coverage-level (PRODUCT → COVERAGE → CIMA BRANCH, many-to-many), not only product-level. TODO: coverage-level mapping.
2. Travel medical expenses → Branch 2. Personal accident → 1, assistance → 18, cancellation/financial loss → 16 where applicable.
3. Motor theft/fire stay under Branch 3.
4. Home multirisk: property/fire → 8 and/or 9; household/general liability → 13.
5. Business interruption → Branch 16.
6. Life disability → complementary cover on its life branch (20/21): principal branch, complementary coverage, separate premium, dates, conditions.
7. Unknown insurer branch authorization → BLOCK_NEW_PRODUCT_PUBLICATION (no warning-only publication).
8. Existing live products → LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION. This status does not permit a new branch, material expansion into another branch, a new product family, or regulatory claims. Authorization record fields: insurer, branch, source authority, decision reference, source document, effective/expiry/revocation dates, status, evidence, verification status. Never fabricate.
9. Branch premium allocations are never invented: `branch_allocation_status = PENDING_CARRIER_ALLOCATION`. Internal estimates are tagged `ESTIMATED_NON_REGULATORY` and excluded from official reporting.
10. Tax/levy values stay DEMO / UNVERIFIED / config-only until sourced. Fields: code, legal basis, base, rate, effective and expiry dates, products, source, verification status. Demo rates can never silently become production rates.
11. Regulatory control engines (CIMA 003-25 AML/CFT, 010-24 ICT) are built from verified official texts. BLOCKED-EXTERNAL: official texts needed.
12. Authority types are extensible. Add ENDORSE, BACKDATE, CANCEL, OVERRIDE. Likely more: RESERVE_APPROVE, CLAIM_SETTLE, REFUND_APPROVE, WRITE_OFF, REINSTATE, PAYMENT_OVERRIDE, JOURNAL_APPROVE, FACULTATIVE_APPROVE.
13. One canonical Case aggregate: case_family + case_type + case_subtype + domain_reference. The blueprint case types are reporting/navigation families.
14. UUIDv7 for public/cross-system identifiers. Bigint surrogates may stay internal. Never expose sequential ids publicly.
15. The hash-chained `audit_log` is the authoritative audit ledger. Domain events and outbox are separate concerns.
16. `insurance_branches` is the canonical branch table (regulatory_regime_id, code, name, effective dates, status). TODO: reconcile with the current `regulatory_branches` (ADR-003 amendment).
17. Premium-to-cover is a configurable rule engine (product, class, jurisdiction, premium status, effective date, exception, activation rule), not a Boolean.
18. Sensitive actions use action-specific maker-checker (action, amount, branch, product, authority, SoD, regulatory sensitivity). Product publication: technical → compliance → business approval. Claims/finance: delegated financial authority.
19. Historical timestamp correction: targeted, audited migration only, following the 10-step process below. No blanket update.
20. Curated vehicle master is preferred. The ODbL global dataset is NOT imported; the importer stays disabled.
21. Insurer logos only from licensed/authorized files. Text wordmarks until then.
22. SLA/business calendars are tenant-configurable (working days, hours, optional lunch exclusion, holidays, closures, timezone). No official lunch break is inferred.
23. Reconstructed checklists are labelled truthfully: `OPESINSURE_DOD_V1_RECONSTRUCTED`, `OPESINSURE_CIMA_READINESS_V1_DRAFT`.
24. Platform reporting codes (ISSUED, COLLECTED, BROKER_COMMISSION, AGENT_COMMISSION) stay internal and are translated by the regulatory export adapters.
25. Unverified CIMA role codes stay NULL. Keep the 16-role working set.
26. Beneficial ownership: ownership > 25% (direct or indirect, capital or voting rights), plus control by other means; also settlor, trustee, protector, beneficiary and other controlling persons.
27. KYC is risk-based and audited: customer, country, product and channel risk; PEP/sanctions; beneficial ownership; EDD; source of funds and wealth where required; periodic refresh; rescreening. Screening mode is `MANUAL_AUDITED` until a provider is integrated. Automated screening is never claimed.
28. New product publication must use the governance workflow (new products, new versions, materially changed versions). The direct publish path is disabled for new versions after cutover. Legacy products are grandfathered. A missing capability profile blocks governance publication. Workflow: TECHNICAL_REVIEW → COMPLIANCE_REVIEW → BUSINESS_APPROVAL → SANDBOX_TESTS → PUBLICATION.
29. `quotes.enforce_direct_publication` stays OFF globally. It is enabled per insurer / product / channel when ready.
30. Proposal declarations stay `UNVERIFIED_LEGAL_WORDING` and the Terms page stays `DRAFT_LEGAL_REVIEW_REQUIRED` until counsel reviews them.
31. Submission may accept UPLOADED_NOT_YET_REVIEWED where the product permits. Issuance requires every ISSUANCE_REQUIRED document to be manually accepted or verified by an approved automated control.
32. Manual quote SLA defaults (platform SLAs, not legal deadlines): acknowledgement 4 business hours; standard decision 2 business days; complex/referred 5 business days. Timers pause in WAITING_FOR_CUSTOMER and WAITING_FOR_EXTERNAL_EVIDENCE. Configurable per insurer, product, case type, branch and market.

## Other decisions
- OQ-22: the inferred INS-SET-017…030 names are canonical working names. Screen IDs are preserved; wording changes go through spec change control.
- Document matrix §42+: reconstruct from the 220 registry, the product architecture and verified requirements, labelled "reconstructed".
- Support/partner emails: admin-configurable, never hard-coded.
- Credentials (Twilio, ETECH, MTN MoMo, Orange Money): secret management only. Never in Git, source, seeds, docs or frontend builds.
- Environments: **owner override 2026-09-25: no staging server; production keeps running as it is (demo mode stays ON in production).** (Original recommendation was separate staging with demo off.) Non-production documents carry large overlays (DEVELOPMENT / UAT / SANDBOX / DEMONSTRATION — NOT VALID INSURANCE).
- Complaint deadlines: configurable. Internal targets are labelled PLATFORM_SLA, never REGULATORY_DEADLINE without a legal basis.
- Public holidays and hazard zones: versioned institutional datasets (source, jurisdiction, dates, verification status), never hard-coded.
- Vehicle makes: add Datsun and Mahindra to curated reference consideration. McLaren, Lada and UAZ enter only via PENDING_MASTER_REVIEW.
- Insurer/broker setup checklists (23 / 19 items) are the canonical working definitions. IDs are preserved.

## Historical timestamp correction process (item 19)
1. Identify the defective application path.
2. Identify the fix deployment timestamp.
3. Identify the affected tables and columns.
4. Produce an affected-row report.
5. Back up.
6. Dry run.
7. Verify before/after samples.
8. Execute the targeted correction.
9. Persist a migration audit.
10. Verify downstream chronology.

## Owner answers to Q7 (2026-09-25)
1. Existing live products keep their current product-level CIMA mappings. No automatic move to coverage-level mappings.
2. Confirmed: broker and agent commission report under the same Article 557 measure, with the broker/agent distinction carried separately.
3. Keep the badge "Verified (group network)" for VERIFIED_NETWORK_SHARED_WITH_GROUP. Admins must be able to change verification statuses and how their labels (EN/FR) appear, from the admin panel.

## Workflow Institutional Data Master v1 (owner, 2026-09-25)
Stored at database/data/workflow_institutional_data_master_2026.json.
- Rule: PENDING_SOURCE, CONFIG_REQUIRED, UNVERIFIED and DEMO_ONLY are not production values. Support them; never invent missing legal, regulatory, financial, tariff, authorization, SLA or KYC data.
- Case families are now the owner's 10: KYC, UNDERWRITING, CLAIMS, FRAUD_REVIEW, COMPLAINT, FINANCE_EXCEPTION, PROVIDER, REINSURANCE, REGULATORY, OPERATIONS. They replace the 8 reconstructed families.
- Beneficial ownership rule: MORE_THAN_25_PERCENT_OR_CONTROL_BY_OTHER_MEANS (VERIFIED_RULE).

## Addendum 2026-09-25 (owner, via cloud session)
- **Decision 27 clarified (AML screening):** automated name matching against sanction/PEP/watchlists that the tenant itself imports and approves
  is compatible with decision 27, because every hit is dispositioned by a human (maker-checker). User-facing wording must say
  "screened against your approved lists; matches reviewed by your compliance team", never that the platform performs or guarantees screening.
- **Claims officers cannot approve a colleague's claim:** CLAIMS_OFFICER loses `*` and gets an explicit maker-only permission list (Batch 13–15 role pass).
- **Permission catalogue:** every permission used by a route must be catalogued (drift test enforces it).
## UI decisions (owner, 2026-09-25)
- D1 yes: move the public site to the canonical UI (Inter, blue #1769E0 for interactive, gold accent only, blue focus ring). The owner is supplying a folder of further public-site designs; follow them.
- D2 yes: retire the placeholder /portal/{portal} page (redirect to the right panel).
- D3 yes: adopt Lucide icons (composer package allowed).
- D4 yes: build the missing broker and carrier web sections (contracts, bordereaux, settlements, receivables, staff), each with a tenant and permission review.

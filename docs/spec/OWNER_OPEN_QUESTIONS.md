# Owner open questions — to be asked together later

> **Status 2026-09-25:** the owner answered Q1–Q6 in docs/spec/OWNER_DECISIONS_2026-09-25.md. Those decisions are authoritative; the items below stay only as history. What remains externally blocked: official CIMA 003-25 / 010-24 texts, verified tax and levy rates, insurer authorization evidence, licensed logos, counsel review of legal wording, production credentials and a screening provider.

Compiled for a single batch review with the owner. Do not implement answers until confirmed.

## Q1 — CIMA product mappings left open (CIMA dictionary, 2026-09-24)
For each, confirm the mapping (apply / don't apply / different branch):
1. Travel medical cover → CIMA branch 2 (Maladie) as COMPLEMENTARY?
2. Motor theft and motor fire covers → separate mapping (branch 3 own damage vs 8/9), or keep under branch 3?
3. Home household liability → CIMA branch 13 (RC générale) in addition to 8 + 9?
4. Business interruption → CIMA branch 16 (Pertes pécuniaires diverses)?
5. Life disability → COMPLEMENTARY cover on branch 20 (Art. 328 complementary risks)?

## Q2 — Insurer CIMA authorizations
None of the 29 official insurers has recorded authorized CIMA branches, so new product versions are blocked (existing products grandfathered). Provide authorized branches per insurer (agrément sources), or allow publication with a warning until recorded?

## Q3 — Rule engine implementation plan
- OQ-23: exact wording of the owner's 38-item Definition of Done (reconstructed in PRODUCT_RULE_ENGINE_IMPLEMENTATION_PLAN.md).
- OQ-9: confirmed Cameroon tax/levy rates and bases (all DEMO until then).
- OQ-22: INS-SET-017…030 screen names (inferred).
- OQ-24: CIMA branch premium split in manual mode when the insurer doesn't provide it.

## Q4 — Other pending inputs
- Document Requirement Matrix: sections 42 (Aviation) onward — message was cut off.
- Real support and partner email addresses for Terms/Invitation screens (now admin-fed; to be entered in Platform settings).
- Twilio / ETECH KEYS credentials; MTN MoMo / Orange Money credentials.
- Separate staging/demo environment vs switching production demo mode off (INSTITUTIONAL_SEED_SPEC §46).
- CIMA Regulation 003-25 (AML/CFT) and 010-24 (ICT) texts or summaries for the control-engine spec.
- Legal review of the draft Terms page.

## Q5 — Control engines & blueprint decisions (INSURANCE_CONTROL_ENGINES_SPEC_V1.md §10)
- Add ENDORSE, BACKDATE, CANCEL, OVERRIDE to the blueprint authority types? (OQ-3.4)
- Map engine case types onto the blueprint's 8 case types (OQ-6.4).
- ADRs: UUID vs bigint primary keys; audit_log (existing hash chain) vs audit_events; regulatory_branches vs insurance_branches naming.
- CIMA Reg. 003-25 AML specifics (thresholds, PEP scope, FIU reporting, retention); Reg. 010-24 ICT obligations.
- Complaint response deadlines; premium-to-cover legal basis; public-holiday and hazard-zone data sources.

## Q6 — Decisions found during Batch 2 (2026-09-25)
- Approval matrix: which role/permission approves each of the 38 maker-checker actions? (Today: two-person rule + segregation of duties only.)
- Historical timestamps: production DB session is UTC while the app wrote Douala wall-clock without offset, so rows written before the Batch 2 deploy are 1 hour late. Proposed: a one-off, audited correction (subtract 1h on Eloquent-written timestamp columns before the fix date). Needs your OK before touching production data.
- Vehicle makes missing from your file but in the old list: Datsun, McLaren, Mahindra, Lada, UAZ — add or leave to the review queue?
- Insurer logos: supply licensed logo files for the landing page, or keep text wordmarks.
- Business hours: do SLA clocks exclude an unpaid lunch hour? (Spec example ICE §6.11#1 implies yes: 16 business hours from Friday 16:00 lands Wednesday 15:00; continuous 08:00–17:00 gives 14:00.) Also: official working hours, public holidays and SLA targets per case type (OQ-6.2/6.3) — none are seeded until you confirm.
- Global vehicle dataset (gor3a/vehicle-makes-models): code is MIT but the data is ODbL v1.0 — requires attribution to the project and autoevolution.com, and share-alike if we publicly use an adapted database. Accept these terms so we can import generations/engines/specs? (Importer ready: opesinsure:import-vehicle-dataset --accept-license.) Until then generations/variants are filled by admins.
- CIMA readiness checklist (your spec §60, 28 items): the text isn't in the repo; a 29-item reconstruction is marked UNVERIFIED on the CIMA dashboard. Please paste the original list.
- Insurer setup checklist (23 items) and broker setup checklist (19 items): mapped to SETUP_CONFIGURATION_FRAMEWORK steps (counts match). Confirm the wording.
- Broker CIMA reporting subject codes (ISSUED/COLLECTED, BROKER_COMMISSION/AGENT_COMMISSION) are platform labels; rename if you prefer.
- Party roles: the official CIMA list of person/party roles isn't in the repo; a 16-role working set is used with CIMA codes NULL. Please supply the official list.
- Beneficial ownership threshold: 25% is configurable and marked UNVERIFIED (no CEMAC/CIMA source found). Confirm the legal threshold.
- KYC: required documents per level (seeded as PLATFORM_DEFAULT_UNVERIFIED), KYC refresh periods (unset), and when to switch the bind/issue KYC gate from OFF to ENFORCE (per insurer). Also: which sanctions/PEP screening provider to integrate (only MANUAL screening exists).
- Product governance: make the governance workflow (technical → compliance → business approval, sandbox tests, publication blockers) mandatory for all product publication? Today the old direct submit/publish path still exists so live products aren't broken. Also: insurers with no capability profile are blocked from publishing via governance ("missing underwriting mode") — keep that?
- Direct (B2C) sales: enforce marketplace publication for direct quotes (quotes.enforce_direct_publication)? Off today because no live tenant has marketplace publications and it would block every direct quote.
- Proposal declarations wording and missed-instalment consequences are placeholders (config/proposals.php, UNVERIFIED) — need counsel's review.
- Mandatory pre-contract uploads (e.g. carte grise for motor) now apply to products with a document product type: submission accepts uploaded-not-yet-reviewed documents; issuance requires them accepted. Confirm this is the rule you want for straight-through motor sales.
- Manual quotation SLA: how long should an insurer have to acknowledge and to answer a manual quote request? (No SLA targets are seeded until you confirm.)

## Q7 — After the decisions batch (2026-09-25)
- Existing live products keep their old product-level CIMA mappings; move them to coverage-level mappings via the maker-checker mapping flow? (Not done automatically, because it would change the branches of products already on sale.)
- Broker and agent commission both map to the same Article 557 measure, with the broker/agent distinction carried separately. Confirm this is the CIMA reporting treatment.
- Belife Insurance status "VERIFIED_NETWORK_SHARED_WITH_GROUP" shows the badge "Verified (group network)". Keep it, or show plain "Verified"?
- KYC defaults (UNVERIFIED, please confirm): EDD is triggered by a HIGH risk rating or any PEP/sanctions match; source of funds/wealth are required whenever EDD applies. All risk weights, bands and refresh/rescreen periods are empty until you set them.
- Timestamp correction: after the next deploy I'll run the read-only affected-rows report on production and send it to you before any correction (the local run found 1,196 affected values in 55 tables).
- Case families (8, RECONSTRUCTED_PENDING_OWNER, because the blueprint never lists its "8 case types"): Underwriting, Quotation, Claims, Complaints, Recovery & litigation, Compliance & KYC, Data quality, Operations. Confirm or replace.

## Q8 — Canonical document specification (2026-09-25)
- Document numbering conflict: the catalogue's DOC-### IDs come from your 220 register and differ from the canonical specification's DOC-### for 214 of 220 documents (e.g. spec DOC-036 = Motor Attestation, catalogue DOC-036 = Premium Schedule). Both are kept and linked. Which numbering is canonical for external use?
- 17 spec documents have no catalogue type yet (DOC-014, 015, 138–140, 169, 179, 185, 196, 199, 200, 209, 213, 214, 218–220). Should they be added?
- Document signing key: S3+ documents record their signature as CONFIG_REQUIRED until a platform Ed25519 key is provisioned. Generate one on the server (never committed)?
## Q8 — Batch 7 follow-ups (2026-09-25)
Tags: **DECIDED** = owner already answered (recorded for history); **RECOMMENDED** = implemented with the coordinator's recommendation, confirm or change; unmarked = still open.
- **DECIDED** — Intermediary register policy: an intermediary missing from, or not active in, the official register is referred for manual review (not hard-blocked, not ignored).
- Finance bordereau: `prepare()` currently creates NEW_BUSINESS line items for every bordereau type (endorsements, cancellations, claims are not yet separated). Confirm the item types each bordereau type must carry.
- **RECOMMENDED** — Reinsurance treaty application order: quota share → surplus → excess of loss → stop loss. Confirm.
- **RECOMMENDED** — Coinsurance apportionment: the rounding remainder (minor units) goes to the lead insurer. Confirm.
- Premium-to-cover: which field is the authoritative source of `class_code` for the rule lookup? And MORE_INFORMATION_REQUIRED currently does not block issuance — confirm it should not.
- Legacy proposals in PAYMENT_PENDING with no underwriting case are treated as straight-through (STP) approved by the issuability gate. Confirm.
- **RECOMMENDED** — Aviation: aircraft type and aircraft category are kept as separate fields (not merged). Confirm.
- Policy blueprint state mappings: legacy statuses are mapped onto blueprint states (e.g. LAPSED → EXPIRED, and the other legacy→blueprint pairs in the policy state map). Confirm each mapping.
- Underwriting risk-score weights are hard-coded (no configuration table, no source). Provide the weights/bands, or confirm they should become insurer-configurable.
- Complaint categories have no source list (working set only). Please supply the official list.
- SupportController can still change the status of support tickets that are bridged to a case, bypassing the case engine. Lock bridged tickets to the case workflow?
- **DECIDED** — Sticker scoping: insurer staff linked to a carrier may move, reconcile and assign stickers of their own carrier only (enforced in StickerCustodyService).
- Reinsurance, coinsurance and provider-master permissions are not in the REQ-RBAC-004 business-data module list, so SYSTEM_ADMIN's `*` still reaches them. Treat them as business data (platform admins then need a business role or break-glass)?

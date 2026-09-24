# Owner open questions — to be asked together later

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

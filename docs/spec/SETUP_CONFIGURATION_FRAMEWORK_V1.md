# OpesInsure Enterprise Setup & Configuration Framework v1.0 — owner specification (2026-09-24)

Full 100-section text supplied by the owner in the session of 2026-09-24. Locked essentials:

## Layers
1. Platform configuration → 2. Organization configuration (Insurance companies, Brokers) → 3. Product & business-rule configuration → 4. Daily operations. No organization is operational because a row exists; each passes a controlled setup lifecycle.

## Lifecycles
- **Insurer:** DRAFT → REGULATORY_REVIEW → CONFIGURATION → TESTING → READY_FOR_APPROVAL → ACTIVE; SUSPENDED, INACTIVE, TERMINATED. Steps: create → regulatory verification → profile → legal → branches → org structure → users & roles → products → tariffs → underwriting → policy rules → claims rules → documents → broker agreements → commission → payment & settlement → accounting → integrations → notifications → compliance → testing → approval → activation.
- **Broker:** DRAFT → REVIEW → CONFIGURATION → TESTING → ACTIVE; SUSPENDED, TERMINATED.

## Insurer configuration (sections 4–28)
Profile & regulatory data; branch hierarchy (head office, regional, branch, agency, service/claims/sales centres) with capabilities; departments with managers, approval limits, queues, SLA; users with branch/department/position/role/supervisor and underwriting/claims/financial/policy authority (roles CARRIER_SUPER_ADMIN … CUSTOMER_SERVICE); versioned products (EN/FR, family, class, market); coverage (base/optional/mandatory, limits, sums insured, deductibles, franchise, waiting periods, territories, exclusions, extensions); dynamic underwriting questions affecting eligibility/premium/referral/documents/decline; eligibility outcomes ELIGIBLE / INELIGIBLE / UNDERWRITING_REFERRAL / REQUIRES_DOCUMENT; versioned tariffs (base, rates, bands, discounts, loadings, taxes, fees, minimum premium, max discount, deductible impact, risk factors); underwriting rules incl. authority thresholds; policy rules (duration, numbering e.g. AXA-MOT-2026-000001 server-side only, grace, instalments, renewal, cancellation, suspension, reinstatement, endorsement types); claims configuration and configurable claims authority matrix; document templates per carrier (language, numbering, branding, signature, QR, version, effective period); broker management, agreements, product authorization matrix, commission (percentage, fixed, tiered, volume, renewal, reversal, clawback); payment channels, accounting event mapping, integrations with health.

## Broker configuration (sections 29–50)
Profile & regulatory; branches controlling agents/customers/leads/policies/renewals/claims/payments/stickers/collections/commission; departments; staff permissions and thresholds; agent types (employee, independent, sub-agent, branch, corporate rep) and hierarchy (branch → supervisor → agents → sub-agents); carrier portfolio; catalogue derived from carrier product + agreement + authorization (never invented); internal product controls (cannot override insurer coverage/tariff); internal commission split (retained / agent / supervisor / branch pool); customer ownership and lead assignment rules (NEW → CONTACTED → QUALIFIED → QUOTE → NEGOTIATION → WON / LOST); quote controls; servicing permissions; claims routing; premium collection; per-insurer settlement; sticker chain carrier → broker → branch → agent → policy; broker documents; accounting setup; targets.

## Platform configuration (sections 51–86)
Setup wizard; identity; country (Cameroon, CM, XAF, Africa/Douala, FR/EN, CIMA; CEMAC-ready); geography country → region → department → arrondissement → city; currency; languages; global taxonomy; RBAC scopes Platform / Tenant / Branch / Department / Record; workflow engines for 13 domains with tenant options inside guardrails; central approval matrix (workflow, action, amount, role, branch, product, insurer); numbering engine with per-tenant sequences; document engine; notifications; payment providers; integration registry; accounting event types mapped per tenant; effective-dated taxes & fees; commission engine; claim and KYC configuration; public verification; security policy; tenant isolation; retention; storage; email; SMS; push; API; feature flags scoped by environment/country/tenant/branch/product; SLA and business calendar; dashboard and search configuration; audit (actor, tenant, branch, entity, action, old/new, timestamp, source, IP/device); backup & recovery (RPO/RTO).

## New screens (102) — register now 530
- Insurance Company Setup INS-SET-001…038
- Broker Company Setup BRK-SET-001…030
- Platform Setup PLT-SET-001…034
(names as listed by the owner). Plus three guided wizards: Insurer, Broker, Platform.

## Governing rules
- Inheritance: Platform default → Insurer → Broker agreement → Broker internal → Branch → User; not every value overridable (broker cannot redefine cover; agent cannot change premium formula).
- Precedence: lower levels may only be more restrictive than mandatory platform controls.
- Activation checklists for insurer (23 items) and broker (19 items).
- Configuration audit: changed by/at, old/new, reason, effective date, approval.
- Effective-dated configuration; never overwrite tariff, commission, taxes, coverage, wording, agreements.
- Draft → Review → Approved → Published; maker-checker on sensitive configuration.
- Configuration sandbox: test quote/premium/policy/attestation/claim/commission, showing configuration versions used.

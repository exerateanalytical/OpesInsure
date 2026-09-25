# API family coverage — REQ-API-004 (Batch 16, agent B4)

Base: commit cbcbecb (1,218 `api/v1` routes from `php artisan route:list`). Sources: docs/spec/TRACEABILITY_MATRIX_V1.md §1.4 "API delta" (blueprint Part IV §136–155) and docs/spec/SCREEN_TO_API_MATRIX_V1.md (rule 5 families). Rule 1 of the screen-to-API matrix applies: a matrix path names a capability. When an equivalent route already exists, it is mapped here and not duplicated.

Status legend: **EXISTS**: the capability is served. **ADDED-B4**: this batch added a thin read controller over existing models or services. **OUT OF SCOPE**: no service backs it yet, so no logic was invented here.

| Family | Required capability | Route(s) at cbcbecb or added | Status |
|---|---|---|---|
| Auth / identity | login, OTP, MFA, me | auth/mobile/*, me/*, me/mfa/totp, me/devices | EXISTS (web OIDC flow is out of scope, see REQ-SEC) |
| Tenants / org | tenants, branches, departments | tenants*, organization/branches/tree, organization/departments, organization/settings | EXISTS |
| Customers / KYC | staff KYC queue/review, UBO, screenings | kyc/submissions* (start-review, decision, rescreen, screenings/{check}), kyc/expiring, parties/{party}/ubo, aml/screening/* | EXISTS |
| Leads | broker lead directory and assignment | crm/leads, crm/leads/{lead}/assign, /transitions, /activities | EXISTS |
| Master data | search/versions | master-data/{domain}/search, master-data/versions, public/master-data/* | EXISTS |
| Regulatory / CIMA | reference sets, report runs | configuration/regulatory-reference-sets*, trust/regulatory-report-runs/*, public/regulatory/cima/* | EXISTS. Regulatory-rules impact API is OUT OF SCOPE (no service) |
| Product admin (PRE §91) | products, versions, plans, coverages, limits, deductibles, exclusions, governance, test cases | catalogue/carrier-products*, catalogue/versions/{v}/(plans, coverages, limits, deductibles, exclusions, governance*, test-cases, test-runs, diff, snapshot, completeness, sandbox), catalogue/products* | EXISTS |
| Product runtime (owner routes) | eligibility check, rate, completeness, questionnaire, document gate | POST insurance/eligibility/check, POST insurance/rate, POST insurance/completeness/check, GET products/{p}/questionnaire, GET products/{p}/document-gate, catalogue/versions/resolve, mobile/runtime/bootstrap | EXISTS |
| Quotes / comparisons | PATCH, generate, send, document, comparisons | quotes/{q} PATCH, /generate, /send, /document, /history, quote-comparisons* | EXISTS |
| Proposals | info-request / resubmit | proposals*, underwriting/cases/{c}/information-requests | EXISTS |
| Underwriting / authority | evaluate, authority, **referrals queue** | underwriting/cases/{c}/evaluate, authority-types*, carrier/delegated-authorities*, underwriting/referrals/{r}/resolve; **GET underwriting/referrals, GET underwriting/referrals/{referral}** | EXISTS + ADDED-B4 (queue). "Authority profiles" as a separate resource: OUT OF SCOPE (AuthorityService works from authority types and delegated authorities; no profile model) |
| Policies / servicing | failed-issuance, cancellation, suspension queues | issuance-exceptions*, policy-cancellations*, policies/{p}/cancellations, renewals* | EXISTS |
| Documents | intake, retention | document-governance/intake*, document-governance/retention-schedules*, documents* | EXISTS |
| Coverage check | POST coverage/check | POST claims/coverage/check, claims/{c}/coverage-checks* | EXISTS |
| Payments / refunds / reconciliation | obligations, allocations, refund queue, unmatched | finance/obligations*, payments/{p}/allocations, refunds*, reconciliation/workspace/items*, reconciliation/imports* | EXISTS |
| Commission / settlement | statements, payouts, settlements | financial-distribution/*, partner-statements*, carrier-settlements*, bordereaux* | EXISTS (DUP-008/011 tracked separately) |
| Ledger / journals | trial balance, manual journal approval | ledger/trial-balance, ledger/manual-journals/* | EXISTS. Period close is OUT OF SCOPE here because Finance/Subledger and ledger posting belong to agent F1 |
| Claims | coverage, assessments, limits | claims/{c}/coverage-checks, claims/{c}/assessments, claims/{c}/limits, claims/* | EXISTS |
| Providers / networks / preauth | providers, networks, preauth | providers*, provider-networks*, provider-portal/*, health/preauthorizations* | EXISTS |
| Reinsurance / co-insurance | treaties, fac, cessions, recoveries, coinsurance | reinsurance/*, coinsurance/arrangements* | EXISTS |
| Screenings / UBO | screening, UBO | aml/screening/*, parties/{party}/ubo | EXISTS |
| Compliance / fraud / privacy | case API | compliance/*, trust/*, risk-alerts*, cases/{case}/tasks | EXISTS |
| Complaints | WF-071 | complaints* (acknowledge, assign, classify, investigate, escalate, resolution, communicate) | EXISTS |
| Notifications | queue, retry, cancel, **list, detail** | POST notifications, notifications/{d}/retry, /cancel, notification-templates*; **GET notifications, GET notifications/{d}** | EXISTS + ADDED-B4 (read side) |
| Unified tasks inbox | my work | GET me/work, cases/{case}/tasks | EXISTS |
| Global search | MPS §94 | GET search (GlobalSearchService) | EXISTS |
| Dashboards | metric contract with drill-down | web-experiences/{portal}/dashboard, mobile/{role}/dashboard | EXISTS (partial). A generic metric/KPI catalogue API is OUT OF SCOPE: no KPI catalogue service |
| Reports | KPI catalogue | reports/*, trust/regulatory-report-* | EXISTS (partial). KPI catalogue is OUT OF SCOPE |
| Operations / exceptions | unified exceptions queue | finance/exception-centre, issuance-exceptions*, integrations/health, integrations/delivery-attempts/{a}/replay | EXISTS per domain. A cross-domain unified queue is OUT OF SCOPE: it needs an aggregation contract, so an owner decision is required |
| Developer / webhooks | clients, webhooks, portal, usage | integrations/clients* (POST lifecycle), integrations/clients/{c}/webhooks, webhooks/* | EXISTS. Client list/usage/OpenAPI portal is OUT OF SCOPE: integration clients are platform-level (partner-scoped, no tenant_id) and there is no usage service |

## Added in this batch (routes/api.php, block "Agent B4")

| Route | Controller | Backing | Permission |
|---|---|---|---|
| GET api/v1/underwriting/referrals | ApiFamilies\UnderwritingReferralQueueController@index | UnderwritingReferralTask plus CarrierScopeResolver (the same scope as the underwriting workspace) | carrier.referrals.read (existing) |
| GET api/v1/underwriting/referrals/{referral} | …@show | same | carrier.referrals.read (existing) |
| GET api/v1/notifications | ApiFamilies\NotificationDeliveryQueryController@index | NotificationDelivery (tenant-scoped; destination hash never returned) | communications.manage (existing) |
| GET api/v1/notifications/{d} | …@show | same | communications.manage (existing) |

Tests: tests/Feature/Batch16/ApiFamilies/ApiFamilyReadEndpointsTest.php (happy path, 403 and tenant isolation for each route).

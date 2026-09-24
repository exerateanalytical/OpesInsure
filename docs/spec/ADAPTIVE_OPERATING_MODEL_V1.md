# OpesInsure Adaptive Insurance Operating Model — owner specification v1.0 (2026-09-24)

**Supersedes any reading of the Product Rule Engine spec as mandatory.** The rule engine stays, but becomes one optional execution mode per capability.

## Positioning
A CIMA-aligned insurance operating platform that adapts to each insurer's and broker's existing processes and digital maturity: start manual/assisted, progress to configured automation and full API integration, without replacing the operating model on day one. Regulatory consistency without operational rigidity.

## Strict (never flexible)
Tenant isolation · RBAC · audit trails · CIMA classification master data · insurer authorization controls · historical version preservation · payment integrity · document provenance · policy identity · claim identity · commission traceability · maker-checker for sensitive actions · idempotency for financial/integration actions · immutable historical transactions · financial reconciliation.

## Flexible: execution strategy per capability
Capabilities: product catalogue, quotation, rating, underwriting, payment, policy issuance, document generation, endorsements, renewals, claims intake, claims decision, claims settlement, commission, accounting, regulatory reporting, API integration.

| Capability | Modes |
|---|---|
| Quotation | MANUAL · CONFIGURED · REMOTE_API |
| Underwriting | MANUAL · RULE_ENGINE · REMOTE_INSURER · HYBRID (also NOT_REQUIRED) |
| Policy issuance | MANUAL_UPLOAD (carrier or broker) · OPES_GENERATED · INSURER_API · HYBRID |
| Claims | BROKER_ASSISTED · MANUAL_CARRIER · INSURER_PORTAL · API_SYNCHRONIZED · FULLY_DIGITAL |
| Payment | BROKER_COLLECTION · INSURER_COLLECTION · MOBILE_MONEY · BANK · EXTERNAL_PROVIDER |
| Pricing source | MANUAL_PREMIUM · UPLOADED_TARIFF · EXCEL_IMPORT · CONFIGURED_RULES · CARRIER_API |

Execution modes: Manual/Assisted · Platform Configured · Hybrid · Remote API.

## Insurance Company Capability Profile
Each insurer has a profile assigning a mode to every capability (examples in the owner's text: a manual insurer vs a fully API-integrated insurer, both fully supported).

## Integration maturity levels (integration state, not a quality ranking)
1 Registry (identity, CIMA branches, profile, basic catalogue) · 2 Operational (manual quotes, policies, renewals, claims, commissions) · 3 Configured (tariffs, eligibility, rules, documents, commissions, workflows) · 4 Connected (selected APIs) · 5 Integrated (end-to-end sync).

## Simple product onboarding (5 questions)
1. What do you sell? (Motor, Health, Travel, Property, Life, Accident, Marine, Construction…) → CIMA mapping done in the background.
2. How is it priced? (manual premium, upload tariff, import Excel, configure rules, carrier API)
3. How is underwriting done? (not required, manual insurer review, OpesInsure rules, insurer API, hybrid)
4. How is the policy issued? (carrier upload, broker upload, OpesInsure generation, carrier API)
5. How are claims handled? (broker-assisted, insurer portal, manual carrier workflow, OpesInsure claims workflow, carrier API)

## Mode 1 minimum
Company → CIMA branches → products → basic coverage → basic premium/tariff info → documents → broker agreements. Broker flow: create quote → send to insurer → receive response → enter approved premium → upload/receive policy → deliver to customer.

## Excel/CSV import (essential)
Products, tariffs, policy portfolio, customers, vehicles/fleet, claims, renewals, commissions, broker agreements, agents. Flow: upload → map columns → validate → preview → resolve errors → approve → import.

## Carrier original documents
Insurers keep their policy, attestation, cover note, endorsement, receipt, conditions and claim documents; OpesInsure stores, indexes, verifies where possible, delivers; each keeps issuer, policy, type, issue date, version, provenance, audit. Native generation optional later.

## Controlled human override
Previous value, new value, reason, user, authority, timestamp, approval above threshold — never untraceable editing.

## Broker flexibility
Small broker: customers, quotes, policies, renewals, claims, commissions, insurer statements. Large broker adds branches, corporate accounts, CRM, finance, compliance, claims teams, carrier APIs, analytics, accounting, developer APIs. Same platform, maturity-based configuration.

## Adapter architecture
QuoteProvider (Manual / ConfiguredRating / CarrierApi) · UnderwritingProvider (Manual / RuleEngine / CarrierApi / Hybrid) · PolicyIssuer (Manual / Opes / CarrierApi) · ClaimProvider (ManualCarrier / Opes / CarrierApi) · PaymentExecution (BrokerCollection / CarrierCollection / MobileMoney / Bank / ExternalGateway). Workflow stable; execution mechanism swappable.

## CIMA visibility
Authoritative but invisible in everyday sales and customer screens ("Motor Comprehensive", never "CIMA Branch 3 + 10"). Visible in insurer setup, product administration, compliance, regulatory reporting, audit.

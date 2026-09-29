# OpesInsure Document Field & Data Specification v1.0

## Purpose

This specification defines the **minimum required information, field groups, business data, validation rules, security metadata, and lifecycle information** for OpesInsure documents.

It supplements:

- `OpesInsure_220_Documents_and_First_5_Master_Shells_v1.md`
- the canonical 220-document registry;
- the document security-tier specification;
- the product, policy, claims, finance, provider, reinsurance and regulatory domain models.

The objective is to prevent incomplete, decorative, non-operational, or “half-baked” insurance documents.

---

# 1. MANDATORY IMPLEMENTATION INSTRUCTION TO CLAUDE

Claude/developers/design agents must treat this specification as a **minimum completeness standard**, not as a restrictive list.

## 1.1 No half-baked templates

Do **not** create a document merely because it has:

- a logo;
- a title;
- a customer name;
- a policy number;
- a few table rows;
- a QR code;
- and a signature.

That is not an industry-grade insurance document.

For every document, Claude must determine:

1. **what transaction the document proves or communicates;**
2. **who issued it;**
3. **who receives or relies on it;**
4. **which insurance product/class it concerns;**
5. **which policy/quote/claim/payment/provider/reinsurance record it belongs to;**
6. **the document’s effective dates and legal/operational status;**
7. **all financial amounts involved;**
8. **all insured risks/persons/assets involved;**
9. **all coverage/limit/deductible/exclusion information needed for that document;**
10. **all approval/signature/authority information required;**
11. **all verification/security information required by the assigned S1–S5 security tier;**
12. **all references to replaced, superseded, revoked, cancelled, amended or prior documents;**
13. **all required terms, declarations, notices, caveats, and explanatory information normally expected for that document in professional insurance operations.**

Claude must use **insurance-industry reasoning** to fill structural gaps in a template specification.

Claude must **not invent**:

- statutory rates;
- regulator-issued numbering formats;
- insurer-specific wording;
- policy limits;
- exclusions;
- taxes;
- levies;
- commissions;
- legal references;
- regulatory forms;
- signatures;
- licence numbers;
- addresses;
- or any other fact that has not been verified.

When such data is unknown, use:

- configurable field;
- `NULL`;
- `UNVERIFIED`;
- `PENDING_VERIFICATION`;
- or a clearly marked template placeholder.

## 1.2 Completeness over minimalism

A clean design is required, but **clean does not mean missing information**.

If a document requires two pages, three pages, schedules, annexes, or continuation tables to be complete, it must use them.

Do not force a serious policy, claim settlement, provider statement, bordereau, or regulatory document into one page if doing so removes required information.

## 1.3 Data must come from canonical sources

Templates must read from canonical entities.

Examples:

- customer → `party_id`
- organization → `organization_id`
- policy → `policy_id`
- claim → `claim_id`
- payment → `payment_id`
- financial obligation → `financial_obligation_id`
- provider → `provider_id`
- reinsurance treaty → `treaty_id`
- document → `document_id`

Do not recreate free-text copies of master data when canonical IDs exist.

## 1.4 Snapshot rule

Issued documents must preserve the data as it existed when the document was issued.

An issued document must not silently change because:

- customer name changed;
- vehicle data was corrected;
- product wording changed;
- tariff changed;
- branch address changed;
- broker changed;
- provider tariff changed.

Store the issued document snapshot and template version.

---

# 2. UNIVERSAL DOCUMENT FIELD GROUPS

Every document should be assembled from one or more of the following canonical field groups.

## FG-01 — Document Identity

Minimum:

- `document_id`
- `document_uuid`
- `document_type_code`
- `document_number`
- `document_title_en`
- `document_title_fr`
- `document_family`
- `document_security_tier`
- `template_id`
- `template_version`
- `language`
- `status`
- `issue_date`
- `issue_time`
- `generated_at`
- `effective_from` where applicable
- `effective_until` where applicable
- `expiry_date` where applicable
- `page_number`
- `page_count`

## FG-02 — Issuer

- issuer legal name
- issuer trade name where applicable
- insurer/broker/provider/reinsurer type
- organization code
- branch name/code
- registered address
- contact telephone
- contact email
- website
- verified regulatory identifier where applicable
- issuing department
- issuing user/system
- authorized signatory
- signatory role

## FG-03 — Customer / Party

Depending on document:

- customer number
- policyholder name
- insured name
- claimant name
- beneficiary name
- corporate legal name
- date of birth where appropriate
- business registration number where appropriate
- identification type/number where appropriate and privacy-permitted
- postal/physical address
- telephone
- email
- preferred language

## FG-04 — Policy / Contract

- policy number
- product
- plan/package
- insurance class
- insurer
- broker
- agent
- policy issue date
- inception/effective date
- expiry date
- renewal date
- policy status
- currency
- territory
- payment frequency
- previous policy number where relevant
- renewed-from policy where relevant

## FG-05 — Risk / Insured Object

Dynamic by insurance class.

Examples:

### Motor
- registration number
- VIN/chassis number
- make
- model
- model year
- generation
- engine/variant
- body type
- vehicle use
- declared value
- seating/payload where relevant

### Property
- property address
- occupancy
- construction type
- building value
- contents value
- stock value
- security/fire protection

### Health/Life
- member number
- insured person
- date of birth
- relationship
- benefit category
- sum assured

### Marine/Cargo
- goods
- value
- packing
- origin
- destination
- conveyance
- voyage
- shipment reference

## FG-06 — Coverage

- coverage name
- coverage code
- coverage status
- sum insured
- indemnity limit
- sublimit
- deductible/franchise
- waiting period where relevant
- territorial limit
- applicable conditions
- exclusion references
- extension references
- coverage effective period

## FG-07 — Premium / Charges

Where relevant:

- base premium
- coverage premium
- risk loading
- discounts
- net premium
- taxes
- levies
- stamp duties
- service fees
- other charges
- gross premium
- currency
- installment amount
- installment due dates
- outstanding premium
- amount paid

## FG-08 — Payment

- payment reference
- receipt number
- payer
- payee
- payment date/time
- amount
- currency
- payment method
- provider/bank transaction reference
- allocation
- reconciliation status
- cashier/channel
- reversal status
- refund status

## FG-09 — Claim

- claim number
- policy number
- claimant
- loss date/time
- report date/time
- loss location
- cause of loss
- description
- affected risk
- coverage assessment
- reserve
- estimated loss
- assessed loss
- approved amount
- deductible
- settlement amount
- decision
- decision reason
- recovery/subrogation status

## FG-10 — Health Provider

- member
- policy
- provider
- facility
- provider network
- service
- diagnosis/clinical data only where permitted
- authorization number
- requested amount
- approved amount
- member responsibility
- insurer responsibility
- validity period

## FG-11 — Reinsurance / Co-insurance

- cedant
- reinsurer
- broker
- treaty/placement number
- treaty type
- risk
- gross exposure
- retention
- ceded amount
- participation %
- premium
- commission
- layer
- attachment point
- limit
- recovery amount
- settlement amount

## FG-12 — Authorization / Signature

- prepared by
- reviewed by
- approved by
- signatory
- signatory role
- approval date/time
- delegated authority reference where relevant
- digital signature status
- maker-checker status
- seal type

## FG-13 — Verification & Security

According to S1–S5:

- verification token
- QR code
- public verification URL
- short verification code
- SHA-256 hash
- security tier
- watermark
- authentication seal
- microtext profile
- guilloche profile
- UV profile
- hologram serial
- secure-stock serial
- print batch
- revocation status

## FG-14 — Lifecycle / Replacement

- source document
- supersedes document
- superseded by
- replacement document
- duplicate-of
- revocation reason
- cancellation reason
- replacement reason
- effective date of status change

## FG-15 — Compliance / Footer

- template code/version
- confidentiality classification
- registered office
- legal/compliance notice
- complaints/support contact
- regulator/licensing details only when verified
- page X of Y

---

# 3. DETAILED SPECIFICATIONS FOR CRITICAL DOCUMENTS

## DOC-001 — Insurance Quote / Devis d’assurance

Must include:

### Identity
- quote number
- quote status
- version/revision
- issue date
- valid-until date
- currency

### Parties
- prospect/customer
- insurer
- broker
- agent/adviser

### Product
- insurance class
- product
- plan/package
- coverage period proposed

### Risk
- full risk summary appropriate to the insurance class

### Cover
For each selected or proposed coverage:
- coverage
- limit/sum insured
- deductible
- optional/mandatory
- premium contribution where exposed

### Premium
- base premium
- loadings
- discounts
- net premium
- taxes/levies/fees
- gross premium
- installment option if offered

### Underwriting
- assumptions
- outstanding requirements
- referral/conditional status where relevant

### Notices
Must state:
- validity period;
- whether subject to underwriting;
- that quotation does not itself prove active insurance unless legally/product-configured otherwise;
- significant assumptions;
- material exclusions/conditions or references.

### Security
S1/S2 controls.

---

## DOC-003 — Insurance Proposal

Must include:

- proposal number;
- quote reference;
- applicant;
- proposed policyholder;
- proposed insured(s);
- beneficiary information where applicable;
- product/plan;
- insurance class;
- requested effective date;
- risk information;
- underwriting-question answers;
- disclosures;
- previous insurance/history where product requires it;
- claims/loss history where product requires it;
- coverage selections;
- sums insured;
- limits;
- deductibles;
- premium estimate;
- payment preference;
- required documents;
- declaration of truth/completeness;
- consent;
- privacy/data-processing acknowledgements where applicable;
- applicant signature/acceptance;
- submission timestamp.

---

## DOC-016 — Insurance Policy

Must not be a one-page decorative certificate.

At minimum it must identify:

- insurer;
- policyholder;
- insured;
- beneficiary roles where applicable;
- policy number;
- product/class;
- inception;
- expiry;
- territory;
- insured risk(s);
- coverages;
- limits;
- deductibles;
- exclusions;
- special conditions;
- premium;
- taxes/charges;
- payment terms;
- renewal terms;
- cancellation/termination rules;
- notification/contact information;
- linked general/special conditions;
- endorsements incorporated;
- issuer/signatory;
- verification/security controls.

A policy may be composed of:
- policy schedule;
- general conditions;
- special conditions;
- clauses;
- endorsements;
- schedules;
- certificates.

The system must preserve which components collectively constitute the contract.

---

## DOC-017 — Policy Schedule

Must include:

- policy number;
- schedule version;
- policyholder;
- insured;
- product/plan;
- branch;
- broker/agent;
- issue date;
- effective/expiry dates;
- renewal date/basis;
- territory;
- insured risks;
- coverage schedule;
- sums insured;
- limits;
- deductibles;
- premium;
- taxes/levies;
- payment schedule;
- special conditions;
- clause references;
- endorsement references;
- insurer authorization/signature;
- QR/hash/status.

---

## DOC-021 — Cover Note

Must include:

- cover-note number;
- linked proposal/quote/policy;
- insurer;
- policyholder/insured;
- risk;
- coverage being temporarily evidenced;
- limit/sum insured;
- deductible where relevant;
- inception date/time;
- expiration date/time;
- conditions precedent;
- outstanding requirements;
- premium/payment status where required;
- explicit temporary/provisional nature;
- issuer;
- signature/approval;
- verification.

---

## DOC-022 — Insurance Certificate

Must include:

- certificate number;
- policy number;
- insurer;
- insured/policyholder;
- insurance class;
- insured object/risk;
- concise scope of cover;
- effective date;
- expiry date;
- territory;
- limitations/reference to policy terms;
- issuer/signature;
- authentication seal;
- QR/verification;
- current certificate status.

---

## DOC-036 — Motor Insurance Attestation

Must include:

- attestation number;
- policy number;
- insurer;
- policyholder;
- insured where different;
- registration number;
- VIN/chassis number;
- vehicle make;
- model;
- model year where required;
- vehicle category/use;
- effective date/time;
- expiry date/time;
- territory;
- relevant motor-cover identification;
- issue date;
- issuing branch;
- authorized issuer;
- QR;
- short verification code;
- serial/security-stock information when applicable;
- authentication seal;
- status.

No motor attestation may be issued without a real linked policy/coverage state unless explicitly a valid provisional attestation workflow.

---

## DOC-057 — Medical Declaration

Must include, subject to privacy restrictions:

- declaration number;
- applicant/insured;
- policy/proposal reference;
- date of birth;
- relevant medical questions;
- current/past condition questions;
- treatment/medication questions;
- hospitalization/surgery questions;
- disability questions where applicable;
- tobacco/alcohol questions where permitted/relevant;
- declarations;
- consent/authorization;
- date completed;
- signature;
- confidentiality classification.

Medical data must be `MEDICAL_RESTRICTED`.

---

## DOC-060 — Health Membership Card

Must include only what is operationally useful and privacy-safe:

- insurer;
- network/plan;
- member name;
- member number;
- policy/group number;
- dependant relationship where relevant;
- validity dates;
- emergency/provider contact;
- QR/member verification;
- card status;
- optional copay/network indication.

Do not expose sensitive diagnoses or medical history on the card.

---

## DOC-066 — Preauthorization Approval

Must include:

- authorization number;
- request reference;
- member;
- policy;
- insurer;
- provider/facility;
- approved service/procedure;
- service code;
- requested amount;
- approved amount;
- member responsibility;
- insurer responsibility;
- approved quantity/days;
- validity period;
- conditions;
- exclusions/not-covered items;
- authorization status;
- approving officer/engine;
- approval timestamp;
- QR/verification.

It must clearly state that approval is limited to the services/amount/period specified and remains subject to policy terms.

---

## DOC-069 — Guarantee of Payment

Must include:

- guarantee number;
- member;
- insurer;
- policy/group number;
- provider;
- authorized treatment/service;
- guarantee ceiling;
- member share;
- insurer share;
- effective time;
- expiry time;
- conditions;
- non-guaranteed services;
- claim submission instructions;
- contact/escalation point;
- authorized signatory;
- verification.

---

## DOC-076 — Life Insurance Illustration

Must distinguish guaranteed and non-guaranteed values where applicable.

Fields may include:

- applicant;
- proposed insured;
- age/date of birth;
- product;
- term;
- sum assured;
- premium;
- payment frequency;
- benefits;
- projected values;
- surrender values;
- maturity values;
- assumptions;
- charges;
- warnings;
- illustration date;
- validity/assumption basis;
- adviser.

Never present non-guaranteed projections as guaranteed.

---

## DOC-082 — Beneficiary Nomination

Must include:

- policy/proposal;
- policyholder;
- insured;
- beneficiary full name;
- beneficiary ID/reference where permitted;
- relationship;
- date of birth where needed;
- contact where permitted;
- allocation percentage;
- beneficiary type;
- contingent beneficiary where supported;
- effective date;
- policyholder declaration;
- signature;
- witness/notarization only if required/configured;
- total allocation validation = 100% where applicable.

---

## DOC-096 — Master Group Policy

Must include:

- group policy number;
- insurer;
- sponsoring employer/organization;
- eligible member definition;
- covered member categories;
- policy period;
- coverages;
- benefit limits;
- contribution/premium basis;
- waiting periods;
- eligibility rules;
- enrollment rules;
- termination rules;
- dependant rules;
- claims rules;
- member certificate rules;
- corporate contacts;
- signatures;
- verification.

---

## DOC-125 — Professional Liability Certificate

Must include:

- certificate number;
- policy number;
- insured professional/entity;
- profession/activity;
- coverage type;
- limit per claim;
- aggregate limit;
- deductible;
- policy period;
- territorial/jurisdictional scope;
- insurer;
- material limitations/references;
- issue date;
- issuer;
- QR/status.

---

## DOC-133 — Marine Cargo Certificate

Must include:

- certificate number;
- open-policy/facility reference where applicable;
- insured;
- goods description;
- packing;
- quantity;
- invoice/value;
- insured value;
- currency;
- conveyance/vessel/vehicle;
- voyage origin;
- destination;
- shipment date;
- bill of lading/airway bill/transport reference;
- coverage terms/clauses;
- deductible;
- certificate period;
- beneficiary/loss payee if applicable;
- issuer;
- verification.

---

## DOC-138 — Travel Insurance Certificate

Must include:

- certificate number;
- insured traveler;
- passport/ID reference where privacy-permitted;
- policy number;
- destination/territory;
- trip start;
- trip end;
- medical cover limit;
- emergency assistance;
- repatriation cover;
- other key benefits;
- exclusions/reference;
- emergency assistance number;
- issue date;
- insurer;
- QR/verification.

---

## DOC-147 — Policy Endorsement

Must include:

- endorsement number;
- policy number;
- prior policy version;
- endorsement type;
- request/reference;
- effective date/time;
- exact change made;
- before value;
- after value;
- added/removed risk/coverage/party;
- premium increase/reduction;
- tax/fee delta;
- payment/refund requirement;
- revised policy period where applicable;
- terms unaffected statement;
- authorization;
- issued-by;
- QR;
- resulting policy version.

---

## DOC-151 — Renewal Notice

Must include:

- policy number;
- policyholder;
- expiring product/plan;
- current expiry date;
- proposed renewal period;
- renewal premium;
- taxes/fees;
- material coverage changes;
- material exclusions/term changes;
- renewal deadline;
- payment requirements;
- renewal acceptance instructions;
- non-renewal/lapse consequence;
- support contact.

---

## DOC-158 — Cancellation / Termination Notice

Must include:

- notice number;
- policy number;
- policyholder;
- insured;
- cancellation/termination reason code;
- effective termination date/time;
- notice date;
- affected coverages;
- premium status;
- refund due or balance due;
- claims implications where appropriate;
- reinstatement possibility where applicable;
- appeal/contact information where applicable;
- issuer;
- authority;
- QR/status.

---

## DOC-161 — Claim Notification Form

Must include:

### Claim identity
- claim number if already assigned
- notification date/time
- reporting channel

### Policy
- policy number
- insurer
- policyholder
- insured
- product/class

### Loss
- loss date/time
- location
- cause
- detailed narrative
- police/reference number where relevant
- catastrophe/event reference where relevant

### Parties
- claimant
- affected/injured parties
- witnesses
- third parties

### Damage
- insured object
- damage/injury description
- estimated loss if known

### Evidence
- photos;
- videos;
- police report;
- invoices;
- medical records;
- repair estimates;
- other required evidence.

### Declarations
- truth declaration
- fraud warning
- consent
- claimant signature/submission.

Claim form must not imply acceptance of liability or coverage merely because a claim number was created.

---

## DOC-162 — Claim Acknowledgement

Must include:

- claim number;
- policy number;
- claimant;
- reported date;
- loss date;
- assigned claims contact/team;
- current claim status;
- next steps;
- required documents;
- SLA/expected response wording only when configured;
- contact details;
- statement that acknowledgement is not an admission of liability/coverage where applicable.

---

## DOC-169 — Claim Assessment Report

Must include:

- claim;
- policy;
- assessor/adjuster;
- appointment reference;
- inspection date;
- loss date;
- affected risk;
- cause;
- observed damage;
- evidence reviewed;
- photographs/attachments;
- repair/replacement assessment;
- pre-loss value where applicable;
- salvage;
- depreciation where applicable;
- estimated loss;
- recommended settlement;
- coverage observations;
- deductible;
- reservations;
- conflicts/limitations;
- assessor declaration;
- signature/date.

Assessment is recommendation/evidence, not the final insurer claim decision.

---

## DOC-174 — Claim Decision

Must include:

- decision reference;
- claim number;
- policy;
- insured/claimant;
- loss date;
- coverage assessed;
- decision: approved / partially approved / rejected;
- gross assessed loss;
- deductible;
- limits/sublimits;
- prior payments;
- recoveries/offsets where applicable;
- approved amount;
- rejected amount;
- reason codes;
- explanation;
- policy clause/coverage references;
- approving officer;
- delegated authority reference;
- decision timestamp;
- appeal/review mechanism where applicable;
- verification.

---

## DOC-177 — Settlement Offer

Must include:

- settlement reference;
- claim;
- claimant/payee;
- offer amount;
- currency;
- calculation;
- deductible;
- prior/interim payments;
- tax/withholding where applicable;
- release/discharge requirements;
- validity period of offer;
- acceptance instructions;
- banking/payment requirements;
- conditions;
- insurer contact;
- authorization.

---

## DOC-179 — Claim Discharge

Must include:

- claim number;
- policy;
- claimant/payee;
- agreed amount;
- currency;
- nature of settlement;
- payment details/reference where appropriate;
- scope of discharge/release;
- outstanding/reserved matters if partial;
- declaration;
- signature;
- witness/approval if required;
- date.

Do not use a blanket release where the settlement is only partial unless legally and contractually intended.

---

## DOC-181 — Claim Settlement Statement

Must include:

- claim number;
- claimant;
- policy;
- assessed amount;
- approved amount;
- deductible;
- limit application;
- depreciation;
- salvage deduction;
- prior payment;
- recovery/offset;
- taxes/charges if applicable;
- net payable;
- payee;
- payment method/reference;
- payment date/status;
- remaining reserve or claim status.

---

## DOC-186 — Premium Invoice

Must include:

- invoice number;
- policy/proposal;
- billed party;
- insurer/payee;
- billing period;
- premium components;
- taxes;
- levies;
- fees;
- total;
- amount previously paid;
- balance;
- due date;
- payment instructions;
- payment references;
- branch/contact.

---

## DOC-190 — Premium Receipt

Must include:

- receipt number;
- payment reference;
- payer;
- customer;
- policy/proposal/invoice;
- amount received;
- currency;
- amount in words where configured;
- payment date/time;
- payment method;
- mobile-money/bank/card reference;
- allocation to obligations;
- balance;
- reconciliation status;
- cashier/channel;
- `PAID` only when appropriate;
- QR/hash;
- reversal/refund status where applicable.

A receipt is evidence of a payment transaction. It must not automatically assert policy activation unless activation actually occurred.

---

## DOC-194 — Broker Statement

Must include:

- statement number;
- broker;
- insurer;
- reporting period;
- opening balance;
- policies/transactions;
- written premium;
- collected premium;
- outstanding premium;
- cancellations/refunds;
- commissions;
- taxes/levies;
- remittances;
- adjustments;
- closing balance;
- settlement due;
- currency;
- reconciliation status;
- preparation/approval.

---

## DOC-197 — Carrier Settlement Statement

Must include:

- settlement number;
- insurer;
- broker;
- period;
- policies/receipts included;
- gross premium collected;
- taxes/levies;
- commissions retained/payable;
- adjustments;
- refunds;
- prior balance;
- amount due insurer;
- amount paid;
- payment reference;
- reconciliation exceptions;
- approval;
- settlement status.

---

## DOC-201 — Reinsurance Slip

Must include:

- slip number;
- cedant;
- reinsurance broker where applicable;
- reinsurer/participants;
- insured/risk;
- original policy/product;
- period;
- sum insured/exposure;
- original premium;
- retention;
- amount ceded;
- type of reinsurance;
- participation;
- terms;
- exclusions;
- commission;
- brokerage;
- taxes/charges;
- claims cooperation terms;
- governing reference;
- signature/acceptance status.

---

## DOC-206 — Risk Bordereau

Must include row-level data such as:

- bordereau number;
- treaty;
- period;
- cedant;
- reinsurer;
- policy number;
- insured;
- risk identifier;
- location;
- insurance class;
- inception/expiry;
- gross sum insured;
- retention;
- ceded amount;
- reinsurer share;
- premium;
- status.

Totals must reconcile.

---

## DOC-208 — Claims Bordereau

Must include:

- bordereau number;
- treaty;
- reporting period;
- claim number;
- policy;
- date of loss;
- report date;
- gross incurred;
- paid;
- outstanding reserve;
- insurer retention;
- recoverable;
- recovery received;
- claim status;
- catastrophe/event identifier if relevant.

Totals must reconcile with underlying claim/reinsurance ledgers.

---

## DOC-215 — Provider Contract

Must include:

- contract number;
- insurer;
- provider legal entity;
- facilities covered;
- network;
- effective dates;
- renewal/termination;
- service scope;
- tariff schedule reference;
- billing rules;
- eligibility process;
- preauthorization rules;
- claim submission rules;
- supporting documents;
- payment terms;
- dispute process;
- audit rights;
- fraud/abuse obligations;
- confidentiality/data protection;
- credentialing requirements;
- signatures;
- annexes.

---

## DOC-219 — Regulatory Declaration / Return

Must include:

- return type;
- regulator;
- reporting entity;
- reporting period;
- submission period;
- preparer;
- reviewer;
- approver;
- reporting currency;
- required regulatory line items;
- totals;
- source-system lineage;
- reconciliation status;
- certification/declaration;
- submission reference;
- amendment/version status.

Regulatory fields/formulas must come from verified current regulatory requirements, not assumptions.

---

# 4. FIELD REQUIREMENTS FOR ALL 220 DOCUMENT TYPES

The lists below state the **document-specific minimum additions**. They are additive to applicable universal field groups.

---

## A. Pre-Contract, Sales & Disclosure

### DOC-001 Insurance Quote
FG-01, FG-02, FG-03, FG-05, FG-06, FG-07, FG-13, plus quote validity, underwriting assumptions and quote status.

### DOC-002 Quote Comparison
Comparison ID, customer, compared insurers/products, comparable coverages, limits, deductibles, premiums, taxes/fees, key exclusions, quote validity dates, recommendation field only if user/authorized adviser explicitly provides one.

### DOC-003 Insurance Proposal
Applicant, proposed insured/policyholder, quote reference, risk declarations, coverage selections, underwriting answers, declarations, consent, signature, submission date.

### DOC-004 Insurance Application Form
Applicant identity, contact, product, risk details, requested cover, prior insurance, loss history where relevant, declarations, supporting documents, consent, signature.

### DOC-005 Product Information Sheet
Product name/code, insurer, intended customer, cover summary, exclusions summary, eligibility, premium basis, key obligations, claims/contact process, version/effective date.

### DOC-006 Coverage Summary
Product/policy reference, each coverage, limit, deductible, waiting period, territory, exclusions reference, effective dates.

### DOC-007 Premium Illustration
Insured/applicant, product, assumptions, premium schedule, benefits/values, guaranteed/non-guaranteed distinction where relevant, charges, validity date.

### DOC-008 Risk Declaration
Declarant, risk object/person, material risk facts, previous losses, risk conditions, declaration date, truth declaration, signature.

### DOC-009 Risk Questionnaire
Product, risk, question ID/text, answer, conditional follow-up answers, attachments, respondent, completion timestamp, signature/declaration.

### DOC-010 Additional Information Request
Reference transaction, recipient, missing information/documents, reason, response deadline, submission method, case contact.

### DOC-011 Underwriting Requirements Notice
Proposal/quote, requirements, status of each requirement, due date, consequence of non-completion, underwriter/contact.

### DOC-012 Conditional Offer
Offer reference, proposal, conditions precedent, modified terms, premium, limits/deductibles, expiry, acceptance mechanism.

### DOC-013 Revised Offer
Prior offer reference, revision number, changed terms, old/new premium where relevant, revised cover, validity, reason.

### DOC-014 Offer Acceptance Confirmation
Offer/proposal reference, accepted terms, customer, acceptance channel, timestamp, consent/signature evidence, next steps.

### DOC-015 Electronic Consent Record
Party, transaction/document consented to, exact consent text/version, channel, device/session evidence where appropriate, timestamp, authentication method.

---

## B. Core Policy & Contract

### DOC-016 Insurance Policy
FG-01–07, FG-12–15 as applicable; complete contractual composition and incorporated schedules/conditions.

### DOC-017 Policy Schedule
Policy identity, parties, risk, coverage, premium, period, special conditions, endorsements, verification.

### DOC-018 General Conditions
Insurer, product/version, definitions, coverage framework, exclusions, duties, claims conditions, premium obligations, cancellation/renewal rules, dispute/complaint process, effective version.

### DOC-019 Special Conditions
Policy/product, special conditions, deviations/additions to general conditions, applicability, precedence rule, effective version.

### DOC-020 Specific Clauses Schedule
Policy, clause codes/titles, exact clause text/reference, affected coverage/risk, effective dates.

### DOC-021 Cover Note
Temporary-cover identity, risk, cover, limits, period, conditions, outstanding requirements, verification.

### DOC-022 Insurance Certificate
Certificate/policy, insured, risk, class, period, cover statement, issuer, verification.

### DOC-023 Proof of Cover
Policy, insured, risk, coverage status, effective/expiry, verification timestamp/status.

### DOC-024 Policy Summary
Policy, insured, product, period, key coverages, key exclusions, premium, contact/claims instructions.

### DOC-025 Benefits Schedule
Policy/product, benefit codes, descriptions, limits, frequency, waiting periods, member shares, conditions.

### DOC-026 Coverage Schedule
Policy/risk, coverages, limits, deductibles, premium, effective dates.

### DOC-027 Exclusions Schedule
Policy/product, exclusion code/text/reference, coverage affected, conditions/exceptions.

### DOC-028 Deductibles Schedule
Policy, coverage/risk, deductible type, amount/percentage, min/max, application basis.

### DOC-029 Insured Assets Schedule
Policy, asset ID, description, location, value, sum insured, coverage, effective dates.

### DOC-030 Insured Persons Schedule
Policy, member/person ID, name, relationship/category, benefit level, effective dates, status.

### DOC-031 Premium Schedule
Policy, installment number, due date, premium, taxes/fees, total, paid/outstanding, status.

### DOC-032 Payment Schedule
Policy/account, due dates, required amounts, payment method/instructions, status.

### DOC-033 Policy Issue Confirmation
Policy, proposal, issue date, inception, documents issued, payment status, next steps, verification.

### DOC-034 Duplicate Policy
Original policy/document reference, duplicate number, reason, issue date, current validity/status.

### DOC-035 Policy Replacement Notice
Old policy/document, replacement document, reason, effective date, validity impact, verification.

---

## C. Motor

### DOC-036 Motor Insurance Attestation
Attestation/policy, insured, vehicle identity, cover period, motor class/use, issuer, security serial, QR/status.

### DOC-037 Motor Insurance Certificate
Certificate/policy, insured, vehicle, cover scope, period, territory, insurer, security/verification.

### DOC-038 Provisional Motor Attestation
Provisional number, proposal/policy reference, vehicle, insured, temporary cover start/end, conditions/outstanding requirements, issuer.

### DOC-039 Vehicle Schedule
Policy, vehicle ID, registration, VIN, make/model/year, use, value, cover, premium, status.

### DOC-040 Fleet Vehicle Schedule
Fleet/policy, all vehicles, branch/location, driver/use category, value, cover, premium, effective dates, totals.

### DOC-041 Motor Risk Questionnaire
Vehicle, owner/user, driver profile, use, parking/security, mileage, prior claims, modifications, finance interest.

### DOC-042 Vehicle Inspection Request
Vehicle, policy/proposal, inspection type, reason, location, assigned inspector, due date, required photos/checks.

### DOC-043 Vehicle Inspection Report
Vehicle, inspector, date/location, odometer, condition, damage, photos, accessories, VIN/plate verification, recommendation.

### DOC-044 Vehicle Valuation Report
Vehicle, valuation date, methodology/source, condition, market/replacement value, assessor, assumptions, report validity.

### DOC-045 Roadside Assistance Certificate
Policy/member, vehicle, assistance package, validity, territory, service contacts, limits, QR.

### DOC-046 Motor Assistance Card
Member/policy, vehicle plate, assistance number, emergency numbers, validity, QR.

### DOC-047 Driver Schedule
Policy/fleet, driver ID, licence information where appropriate, category, assigned vehicle, effective dates, status.

### DOC-048 Authorized Driver Endorsement
Policy, driver, licence/category, affected vehicle(s), effective date, premium impact, conditions.

### DOC-049 Vehicle Replacement Endorsement
Policy, old vehicle, new vehicle, effective date, value changes, premium delta, revised coverage.

### DOC-050 Territorial Extension Certificate
Policy/vehicle, existing territory, extended territory, period, conditions, premium/fee, verification.

### DOC-051 Motor Coverage Extension Certificate
Policy/vehicle, added cover, limits/deductibles, period, premium, conditions.

### DOC-052 Fleet Certificate
Fleet policy, organization, fleet identifier, number of vehicles, period, aggregate cover summary, verification.

### DOC-053 Motor Cancellation Certificate
Policy/vehicle, cancellation reason, effective date/time, premium/refund status, current cover status.

### DOC-054 Motor Reinstatement Notice
Policy/vehicle, suspension/cancellation reference, reinstatement date/time, conditions fulfilled, payment status.

### DOC-055 Replacement Motor Certificate
Original certificate, replacement serial, reason, policy/vehicle, issue date, original status.

---

## D. Health & Medical

### DOC-056 Health Enrollment Form
Applicant/member, employer/group if applicable, dependants, plan, effective date, contact, consent, declarations.

### DOC-057 Medical Declaration
Restricted health questionnaire, insured, proposal, declarations, consent, signature.

### DOC-058 Dependant Enrollment Form
Primary member, dependant identity, relationship, DOB, requested effective date, eligibility evidence.

### DOC-059 Member Certificate
Member/policy/group, plan, membership period, benefit category, network, status, verification.

### DOC-060 Health Membership Card
Member name/number, plan/network, policy/group, validity, emergency/provider contact, QR/status.

### DOC-061 Digital Health Card
Same as DOC-060 plus digital token/device-safe verification data.

### DOC-062 Health Benefit Schedule
Plan/policy, service categories, annual/per-event limits, copays, coinsurance, waiting periods, exclusions.

### DOC-063 Provider Network Directory
Network, provider/facility, specialty, location/contact, network status, effective date.

### DOC-064 Eligibility Confirmation
Member, policy, provider, check timestamp, eligibility result, plan/network, remaining benefit where appropriate.

### DOC-065 Preauthorization Request
Member, policy, provider, requested service, diagnosis/clinical justification where permitted, amount, date, attachments.

### DOC-066 Preauthorization Approval
Authorization, approved service/amount/period, member/provider, conditions, payer shares, verification.

### DOC-067 Partial Preauthorization Approval
Same as approval plus requested vs approved scope and rejected/deferred items/reasons.

### DOC-068 Preauthorization Rejection
Request, member/provider, rejected service/amount, reason, review/escalation information.

### DOC-069 Guarantee of Payment
Member/provider, authorized services, ceiling, shares, validity, conditions, claim instructions, verification.

### DOC-070 Hospital Admission Authorization
Member, hospital, admission date, service/ward if appropriate, approved period/amount, conditions, authorization number.

### DOC-071 Hospital Stay Extension Authorization
Original authorization, extension dates, additional approved services/amounts, reason, approval.

### DOC-072 Explanation of Benefits
Member, claim/service date, provider, billed amount, allowed amount, insurer paid, member responsibility, denial reasons.

### DOC-073 Member Reimbursement Statement
Member, claim, expenses submitted, eligible amounts, deductible/copay, approved reimbursement, payment reference.

### DOC-074 Benefit Exhaustion Notice
Member, benefit category, original limit, consumed amount, remaining/exhausted amount, date, implications.

### DOC-075 Health Coverage Termination Certificate
Member/policy, termination date, reason/category, coverage-ending status, continuation information where applicable.

---

## E. Life, Savings, Retirement & Beneficiaries

### DOC-076 Life Insurance Illustration
Applicant/insured, age, product, term, sum assured, premium, projected/guaranteed values, assumptions, warnings.

### DOC-077 Life Contract Summary
Contract, insured, policyholder, key benefits, term, premium, charges, exclusions, surrender/maturity summary, beneficiary rules.

### DOC-078 Life Proposal
Applicant/insured, beneficiary details, medical/financial declarations, product/term/sum assured, premium, signature.

### DOC-079 Life Medical Questionnaire
Insured, medical questions, declarations, consent, signature, restricted classification.

### DOC-080 Medical Examination Request
Insured, proposal, requested examinations/tests, provider, deadline, instructions, privacy notice.

### DOC-081 Financial Needs Declaration
Applicant, income/assets/liabilities where required, coverage objective, requested sum assured, declaration.

### DOC-082 Beneficiary Nomination
Policy/proposal, beneficiaries, relationship, allocation %, priority/type, effective date, signature.

### DOC-083 Beneficiary Allocation Schedule
Policy, beneficiary list, percentages/shares, contingent status, effective dates, total validation.

### DOC-084 Beneficiary Change Request
Policy, current/new beneficiaries, requested changes, effective request date, policyholder authorization.

### DOC-085 Beneficiary Change Confirmation
Policy, accepted beneficiary schedule, effective date, previous version, verification.

### DOC-086 Life Policy Schedule
Policy, parties, sum assured, benefits, premium, term, dates, beneficiaries reference, special terms.

### DOC-087 Life Benefit Schedule
Policy/plan, benefit events, amounts/formulas, waiting periods, exclusions, maturity/death/disability benefits.

### DOC-088 Contribution Schedule
Policy, contribution/premium frequency, due dates, amounts, escalation/indexation rules where applicable.

### DOC-089 Annual Life Statement
Policy, opening values, contributions, charges, interest/investment performance where applicable, benefits, surrender value, closing value.

### DOC-090 Surrender Request
Policy, policyholder, surrender type full/partial, requested amount, bank/payment details, declaration, signature.

### DOC-091 Surrender Value Statement
Policy, calculation date, gross value, charges/adjustments, loans/advances, tax where applicable, net surrender value.

### DOC-092 Policy Advance Application
Policy, policyholder, available value, requested advance, purpose if required, repayment preference, signature.

### DOC-093 Policy Advance Agreement
Policy, advance amount, rate/charges if applicable, repayment terms, security against policy, consequences, signatures.

### DOC-094 Maturity Notice
Policy, maturity date, expected benefit, requirements, beneficiary/payee, payment instructions.

### DOC-095 Maturity Benefit Statement
Policy, maturity benefit calculation, deductions/advances, net benefit, payee, payment status/reference.

---

## F. Group & Corporate

### DOC-096 Master Group Policy
Sponsor, insurer, group policy, eligibility, members, cover, contributions, terms, member documentation.

### DOC-097 Group Membership Form
Group/policy, employee/member, dependants, benefit selection, effective date, consent/signature.

### DOC-098 Employee Census
Employer/group, employee identifiers, categories, dates, salary/benefit basis where applicable, coverage status.

### DOC-099 Dependant Census
Primary member, dependant, relationship, DOB, eligibility/effective dates.

### DOC-100 Group Member Certificate
Member, group/policy, benefit category, coverage period, key benefits, verification.

### DOC-101 Individual Benefit Certificate
Member, policy, selected individual benefits, limits/sums assured, effective dates.

### DOC-102 Employee Addition Notice
Group, employee, effective date, category, benefits, additional premium.

### DOC-103 Employee Removal Notice
Group, employee, termination date, reason, premium adjustment.

### DOC-104 Group Movement Schedule
Period, adds, removals, changes, effective dates, premium impacts, totals.

### DOC-105 Group Premium Statement
Group, period, headcount/basis, premium by category, taxes, adjustments, total due/paid.

### DOC-106 Group Renewal Census
Group, renewal date, active members/dependants, changes, benefit category, renewal basis.

### DOC-107 Corporate Benefit Schedule
Group/company, benefit categories, eligibility, limits, deductibles/copays, waiting periods.

### DOC-108 Corporate Policy Summary
Company, policy, product, period, key benefits, premium, contacts, obligations.

### DOC-109 Corporate Insurance Certificate
Company, policy, class, period, covered operations/assets summary, verification.

### DOC-110 Group Coverage Termination Notice
Group/policy/member(s), termination date, reason, affected cover, final premium/status.

---

## G. Property, Liability, Engineering & Cyber

### DOC-111 Property Risk Questionnaire
Property/business identity, location, occupancy, construction, values, fire/security controls, history, declarations.

### DOC-112 Property Schedule
Policy, property locations, values, occupancy, construction, cover, limits, premium.

### DOC-113 Contents Schedule
Location, contents categories/items, values, sums insured, special limits.

### DOC-114 Stock Schedule
Location, stock type, average/max value, seasonality, cover, limits.

### DOC-115 Property Valuation Report
Property, valuation date, basis, building/contents values, methodology, valuer, assumptions.

### DOC-116 Property Risk Survey
Location, surveyor, hazards, construction, occupancy, protections, deficiencies, recommendations, photos.

### DOC-117 Fire Safety Survey
Fire protection systems, alarms, extinguishers, hydrants, exits, housekeeping, hazards, recommendations.

### DOC-118 Risk Improvement Notice
Policy/risk, improvement requirement, severity, due date, evidence needed, consequences.

### DOC-119 Building Insurance Certificate
Policy/building, owner/insured, address, cover, value/limit, period, verification.

### DOC-120 Business Multirisk Schedule
Business, locations, property/BI/liability/etc coverages, limits, deductibles, premium.

### DOC-121 Business Interruption Schedule
Business, gross profit/revenue basis, indemnity period, waiting period, limit, dependencies.

### DOC-122 Machinery Schedule
Machine/equipment ID, description, serial, location, year/value, cover, limit.

### DOC-123 Engineering Survey Report
Project/equipment, engineer/surveyor, scope, technical findings, hazards, values, recommendations.

### DOC-124 Construction Liability Certificate
Project/contractor, policy, work scope/location, liability limits, period, principals/additional insureds where applicable.

### DOC-125 Professional Liability Certificate
Professional/entity, activity, limits, deductible, retroactive date where applicable, period, territory.

### DOC-126 Public Liability Certificate
Insured, operations, liability limit, deductible, territory, period, verification.

### DOC-127 Employer Liability Certificate
Employer, employee scope, limits, period, occupation/activity, territory.

### DOC-128 Cyber Risk Questionnaire
Organization, systems, users, data, controls, backups, MFA, incident history, vendors, declarations.

### DOC-129 Cybersecurity Controls Declaration
Organization, security control attestations, control owner, evidence refs, date, signature.

### DOC-130 Cyber Insurance Certificate
Insured, policy, cover categories, limits, retention, period, territory, verification.

---

## H. Marine, Travel, Agriculture & Specialty

### DOC-131 Cargo Insurance Declaration
Open-policy reference, insured, goods, value, origin/destination, conveyance, shipment date, coverage.

### DOC-132 Marine Cargo Policy
Insured, goods/interest, voyages/territory, valuation basis, clauses, limits, premium, declarations.

### DOC-133 Marine Cargo Certificate
Certificate, shipment, goods, value, voyage, conveyance, cover, beneficiary/loss payee, verification.

### DOC-134 Shipment Insurance Certificate
Shipment ID, sender/insured, goods, value, origin/destination, dates, coverage, carrier, verification.

### DOC-135 Shipment Declaration
Open cover, shipment details, goods/value, route, carrier, date, declaration.

### DOC-136 Goods Schedule
Goods/items, quantity, invoice/reference, value, packaging, location/shipment.

### DOC-137 Marine Survey Report
Shipment/vessel/cargo, surveyor, inspection details, condition, loss/damage, cause observations, valuation, evidence.

### DOC-138 Travel Insurance Certificate
Traveler, policy, destination, dates, key limits, assistance contacts, verification.

### DOC-139 Visa/Travel Coverage Certificate
Traveler, passport/reference, territory, dates, medical/repatriation cover, certificate wording, verification.

### DOC-140 Travel Assistance Information
Policy/certificate, emergency contacts, services, territory, procedures, exclusions/limitations.

### DOC-141 Farm Risk Declaration
Farmer/entity, farm location, acreage, crops/livestock, practices, values, hazards, history.

### DOC-142 Crop Schedule
Farm/field, crop, variety, area, planting/harvest dates, expected yield/value, sum insured.

### DOC-143 Crop Insurance Certificate
Insured, farm/crop, area, insured peril, sum insured, period/season, verification.

### DOC-144 Livestock Schedule
Farm, animal category, count, identifiers where applicable, age/value, sum insured.

### DOC-145 Livestock Insurance Certificate
Insured, livestock class/count, location, cover, sum insured, period, verification.

---

## I. Servicing, Endorsements & Renewal

### DOC-146 Endorsement Request
Policy, requester, requested change, reason, requested effective date, supporting evidence.

### DOC-147 Policy Endorsement
Policy/version, exact changes, premium delta, effective date, resulting version, authorization.

### DOC-148 Revised Policy Schedule
Policy, new version, changed fields, effective dates, full current schedule, verification.

### DOC-149 Additional Premium Notice
Policy/endorsement, reason, additional premium, taxes/fees, due date, payment instructions.

### DOC-150 Premium Reduction Notice
Policy/endorsement, reason, reduction amount, credit/refund treatment, effective date.

### DOC-151 Renewal Notice
Policy, expiry, renewal period, premium, changed terms, payment deadline, action required.

### DOC-152 Renewal Quote
Renewal reference, expiring policy, proposed cover/limits, premium, changes, validity.

### DOC-153 Renewal Confirmation
Old/new policy, renewal period, accepted terms, payment/status, documents issued.

### DOC-154 Non-Renewal Notice
Policy, expiry, non-renewal effective date, reason/category where appropriate, customer action/contact.

### DOC-155 Suspension Notice
Policy, suspension reason, effective time, affected coverage, reinstatement requirements.

### DOC-156 Reinstatement Notice
Policy, reinstatement date/time, conditions satisfied, premium/payment status, current cover.

### DOC-157 Cancellation Request
Policy, requester, reason, requested date, refund/payment instructions, declaration/signature.

### DOC-158 Cancellation / Termination Notice
Policy, reason, effective date, premium/refund balance, coverage status, verification.

### DOC-159 Policy Expiry Notice
Policy, expiry date/time, coverage ending, renewal status/options, contact.

### DOC-160 Policy Status Confirmation
Policy, insured, product, current status, current effective dates, issued timestamp, verification.

---

## J. Claims, Assessment, Settlement & Recovery

### DOC-161 Claim Notification Form
Claim/policy, loss details, claimant/parties, damage/injury, evidence, declarations.

### DOC-162 Claim Acknowledgement
Claim reference, policy, claimant, receipt date, contact, next steps, requirements.

### DOC-163 Claim Reference Confirmation
Claim number, policy, insured/claimant, loss date, report date, assigned contact.

### DOC-164 Claim Requirements List
Claim, required evidence/documents, conditional requirements, status, due dates/submission method.

### DOC-165 Additional Evidence Request
Claim, missing/insufficient evidence, reason, deadline, submission instructions.

### DOC-166 Claim Investigation Notice
Claim, investigation scope, reason/category, investigator, rights/obligations, contact.

### DOC-167 Expert / Adjuster Appointment
Claim, appointed expert, scope, authority, contact, deadlines, reporting instructions.

### DOC-168 Inspection Appointment Notice
Claim/risk, inspection date/time/location, parties required, documents/items to present.

### DOC-169 Claim Assessment Report
Claim, assessor, evidence, findings, valuation, recommendation, limitations.

### DOC-170 Claim Valuation Statement
Claim, damaged items/injuries, gross loss, depreciation, salvage, deductible, net assessed loss.

### DOC-171 Repair Authorization
Claim, vehicle/property/equipment, repairer, authorized work, ceiling, exclusions, validity, authorization.

### DOC-172 Medical Assessment Request
Claim/member, medical question/scope, appointed clinician, requested reports/tests, confidentiality.

### DOC-173 Medical Assessment Report
Restricted claim/medical data, assessor, findings, causation/function information as permitted, conclusions.

### DOC-174 Claim Decision
Claim, decision, approved/rejected amounts, reasons, clause references, authority, appeal/review.

### DOC-175 Partial Approval Notice
Claim, approved component, rejected/deferred component, amounts, reasons, next steps.

### DOC-176 Claim Rejection Letter
Claim, rejected amount/scope, clear reasons, policy/coverage references, review/appeal route.

### DOC-177 Settlement Offer
Claim, offer calculation, amount, conditions, validity, acceptance procedure.

### DOC-178 Settlement Acceptance
Claim/offer, accepted amount/conditions, claimant acceptance, bank/payment details, signature/timestamp.

### DOC-179 Claim Discharge
Claim, amount, release scope, payee, settlement nature, signatures.

### DOC-180 Claim Payment Advice
Claim, payee, payment amount, date, method/reference, settlement type, status.

### DOC-181 Claim Settlement Statement
Claim financial calculation from gross loss to net settlement and payment/recovery status.

### DOC-182 Claim Closure Notice
Claim, closure date, reason, final decision/payment, recovery/appeal status where relevant.

### DOC-183 Claim Appeal
Claim/decision, appellant, grounds, requested remedy, evidence, submission date.

### DOC-184 Appeal Decision
Appeal/claim, reviewed decision, outcome, reasons, amount changes, final/next-review status.

### DOC-185 Subrogation / Recovery Notice
Claim, recovery basis, target party, insurer rights, amount sought, evidence/reference, contact.

---

## K. Finance, Billing, Commission & Settlement

### DOC-186 Premium Invoice
Invoice/policy, billed party, line items, taxes/fees, total, due date, payment instructions.

### DOC-187 Premium Notice
Policy, premium due, installment, due date, outstanding balance, payment instructions.

### DOC-188 Debit Note
Account/policy, debit reason, amount, tax treatment, date, resulting balance.

### DOC-189 Credit Note
Account/policy, original invoice/debit reference, credit reason, amount, resulting balance.

### DOC-190 Premium Receipt
Payment/receipt, payer, policy/invoice allocations, method/reference, amount, status, QR.

### DOC-191 Payment Receipt
Payment transaction, payer/payee, purpose, amount, date, method, external reference, allocation/status.

### DOC-192 Refund Advice
Refund number, original payment, beneficiary, reason, amount, method/reference, approval/payment status.

### DOC-193 Customer Account Statement
Customer/account, period, opening balance, debits, credits, payments, refunds, closing balance.

### DOC-194 Broker Statement
Broker/carrier, period, premium, collections, commissions, refunds, remittances, balance.

### DOC-195 Agent Commission Statement
Agent, period, policies, commission basis/rate, earned, reversals/clawbacks, paid/outstanding.

### DOC-196 Broker Commission Statement
Broker, period, policy transactions, commission basis/rate, adjustments, tax, payable/paid.

### DOC-197 Carrier Settlement Statement
Carrier/broker, period, premium, commissions, taxes, adjustments, net settlement, reconciliation.

### DOC-198 Provider Settlement Statement
Provider, period, approved provider claims, adjustments, member shares, deductions, payments, balance.

### DOC-199 Tax & Levy Breakdown
Transaction/policy, tax/levy types, bases, rates only from verified configuration, amounts, totals.

### DOC-200 Reconciliation Statement
Account/channel, period, system total, external total, matched/unmatched items, exceptions, final balance.

---

## L. Reinsurance, Co-insurance, Provider, Compliance & Regulatory

### DOC-201 Reinsurance Slip
Risk/policy, cedant, reinsurer, treaty/facultative terms, exposure, retention, cession, premium/commission, acceptance.

### DOC-202 Facultative Placement Request
Risk, original policy/proposal, exposure, requested capacity, retention, terms, documents, response deadline.

### DOC-203 Facultative Quote
Placement, reinsurer, offered share, premium/rate, commission, conditions, validity, exclusions.

### DOC-204 Reinsurance Confirmation
Placement/treaty, bound participants/shares, effective dates, premium, conditions, authorization.

### DOC-205 Treaty Summary
Treaty, cedant, reinsurers, period, classes, structure, retention, capacity/layers, premium/commission terms.

### DOC-206 Risk Bordereau
Treaty/period and row-level ceded risk data with reconciled totals.

### DOC-207 Premium Bordereau
Treaty/period, policy/risk, gross/ceded premium, commissions, taxes, balances, totals.

### DOC-208 Claims Bordereau
Treaty/period, claim, loss date, gross paid/reserve/incurred, recoverable, recovered, balances.

### DOC-209 Reinsurance Recovery Request
Claim/treaty, loss, ceded share, gross claim, retention, recoverable calculation, evidence, amount requested.

### DOC-210 Reinsurance Settlement Statement
Reinsurer/cedant, period, premiums, commissions, claims/recoveries, adjustments, net balance, payment status.

### DOC-211 Co-insurance Placement
Risk/policy, lead insurer, participants sought, shares, terms, premium, capacity, conditions.

### DOC-212 Co-insurance Participation Confirmation
Arrangement, participant, accepted share, premium/claim share, effective dates, conditions.

### DOC-213 Co-insurance Premium Allocation
Policy/arrangement, gross premium, participant shares, commissions/charges, amounts due/paid.

### DOC-214 Co-insurance Claim Allocation
Claim/arrangement, gross approved claim, participant shares, amounts due/paid, recovery allocation.

### DOC-215 Provider Contract
Insurer/provider/network, facilities, services, tariffs, billing, preauthorization, settlement, audits, data protection, term.

### DOC-216 Provider Tariff Schedule
Contract/provider, service code/name, agreed price, member share, insurer share, limits, effective dates.

### DOC-217 KYC Information Request
Party/case, required KYC data/documents, reason/category, deadline, secure submission route.

### DOC-218 KYC Approval / Remediation Notice
Party/case, status/outcome, remaining actions, review date, restrictions if any, contact.

### DOC-219 Regulatory Declaration / Return
Regulator, reporting entity/period, verified form/fields, source lineage, reconciliation, certification, submission status.

### DOC-220 Regulatory Audit / Verification Report
Entity, audit scope/period, reviewers, evidence, findings, severity/classification, required actions, deadlines, responses, closure status.

---

# 5. DOCUMENT FIELD VALIDATION RULES

## 5.1 Identifier validation

A secure document cannot be issued without:
- canonical document number;
- source transaction/entity;
- issuer;
- issue timestamp;
- template version.

## 5.2 Date validation

Examples:
- expiry > inception;
- authorization expiry >= authorization start;
- loss date cannot be silently changed after claim assessment;
- endorsement effective date must produce a new temporal policy state;
- document issue date and effective date are not assumed to be identical.

## 5.3 Financial validation

- never use float;
- currency always explicit;
- premium totals must reconcile;
- receipt amount must reconcile to payment;
- claim settlement must reconcile to decision/payment;
- bordereau totals must reconcile to underlying ledgers.

## 5.4 Percentage validation

Examples:
- beneficiary allocations = configured required total, normally 100%;
- co-insurance shares = 100%;
- reinsurance participation must not exceed placed capacity;
- commission rates cannot be inferred.

## 5.5 Status validation

Document text must reflect actual underlying status.

Examples:
- receipt cannot show PAID if payment is merely PENDING;
- certificate cannot show VALID when policy is cancelled;
- preauthorization cannot show APPROVED while decision is pending;
- claim settlement document cannot show PAID before payment succeeds;
- revoked document must remain verifiable as REVOKED.

---

# 6. TEMPLATE COMPLETENESS GATE

Claude must not mark a template complete until the following questions can all be answered.

## Business identity
- What exactly is this document?
- What transaction creates it?
- Who issues it?
- Who receives it?
- What source entity does it belong to?

## Data
- Are all relevant parties shown?
- Is the insured risk shown?
- Are dates complete?
- Are currency and financial amounts complete?
- Are coverages/limits/deductibles complete where relevant?
- Are reference numbers complete?
- Are reasons/explanations complete for decisions/notices?

## Legal/operational
- Is the document making a statement of coverage, payment, liability, authorization or status?
- Does the underlying system state actually support that statement?
- Are applicable declarations, caveats and conditions included?
- Are signatory/authority requirements satisfied?

## Security
- Is the correct S1–S5 security tier applied?
- Is the QR/token/hash present where required?
- Are document number and template version present?
- Is status visible?
- Are replacement/revocation relationships supported?

## UX / design
- Is the document readable?
- Are long names and large tables handled?
- Does it support multiple pages?
- Are totals easy to locate?
- Is critical information visually prioritized?
- Does the layout still work in French and English?

## Testing
- happy path;
- missing optional field;
- long customer/company name;
- multi-risk policy;
- multi-currency where supported;
- revised/duplicate document;
- cancelled/revoked document;
- print rendering;
- mobile PDF rendering.

---

# 7. REQUIRED CLAUDE IMPLEMENTATION BEHAVIOR

Use this instruction verbatim or incorporate it into the project system prompt:

> **Do not generate half-baked insurance documents, certificates, receipts, schedules, statements, forms, or notices. Treat the field lists in the OpesInsure document specification as minimum requirements. Before creating any template, reason from the insurance transaction, insurance class, lifecycle stage, issuer, recipient, risk, financial effects, policy state, claims state, security tier, and applicable product configuration. Include every additional field, section, schedule, warning, declaration, signature, verification element, status indicator, and continuation page reasonably required by professional insurance-industry practice. Do not sacrifice operational completeness to make a template look simple or fit on one page. Do not invent regulatory facts, statutory rates, insurer wording, legal references, licence numbers, taxes, limits, exclusions, commissions, or other unverified values. Represent unknown information as configurable fields or PENDING_VERIFICATION. Every issued document must be traceable to its canonical source entities, template version, issuer, status, hash/verification data where required, and immutable issuance snapshot. A visually polished but operationally incomplete document is a failed implementation.**

---

# 8. FINAL RULE

For every document:

`Required Universal Fields`
+
`Document-Specific Fields`
+
`Insurance-Class Fields`
+
`Product Configuration`
+
`Transaction State`
+
`Financial State`
+
`Security Tier`
+
`Issuer/Recipient Requirements`
+
`Applicable Verified Regulatory Requirements`
=
**Complete OpesInsure Document**


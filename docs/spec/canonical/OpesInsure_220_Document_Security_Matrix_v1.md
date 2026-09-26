# OpesInsure 220-Document Security Matrix v1.0

## Purpose

This specification assigns a concrete security profile to every canonical OpesInsure document type.

It supplements:

- `OpesInsure_220_Documents_and_First_5_Master_Shells_v1.md`
- `OpesInsure_220_Document_Field_Data_Specification_v1.md`

The matrix removes ambiguity about which security controls apply to which document.

---

# 1. Security Control Legend

## Core Controls

- **QR** — QR verification token linking to the authoritative verification endpoint.
- **HASH** — SHA-256 document hash stored against the issued document.
- **WM** — visible or dynamic watermark.
- **SEAL** — authentication/corporate/functional seal.
- **MICRO** — microtext security strip or perimeter microtext.
- **GUIL** — guilloche/fine-line security pattern.
- **ACOPY** — anti-copy background/pattern.
- **SIG** — signer identity and/or cryptographic/digital signature.
- **MCHK** — maker-checker approval before issuance.
- **UV** — UV-reactive feature on controlled physical output.
- **HOLO** — serialized hologram/tamper-evident foil on controlled physical output.
- **STOCK** — controlled physical stock with serial/batch tracking.
- **REVOKE** — revocation/supersession/replacement support.
- **PUBVERIFY** — privacy-safe public verification portal.
- **AUDIT** — immutable issuance/audit trail.
- **CONF** — confidentiality classification.
- **ACCESS** — access level/role restriction.

## Confidentiality Classes

- **PUBLIC_VERIFY** — safe for public verification with limited data.
- **CUSTOMER_PRIVATE** — customer/private transaction document.
- **INSURER_CONFIDENTIAL** — operational/confidential.
- **MEDICAL_RESTRICTED** — sensitive health information.
- **FINANCIAL_RESTRICTED** — sensitive financial/settlement data.
- **REGULATORY_RESTRICTED** — compliance/regulatory.
- **INTERNAL_RESTRICTED** — internal operational use.

## Access Profiles

- **A1 Public/Recipient**
- **A2 Customer + Authorized Intermediary**
- **A3 Insurer/Broker Operations**
- **A4 Restricted Claims/Finance/Medical**
- **A5 Compliance/Regulatory/Admin Restricted**

---

# 2. Tier Baselines

## S1 — Basic Controlled
Required:
- HASH recommended
- WM optional
- AUDIT mandatory
- CONF mandatory
- ACCESS mandatory

## S2 — Standard Transaction
Required:
- QR
- HASH
- WM
- AUDIT
- REVOKE where lifecycle permits
- CONF
- ACCESS

## S3 — High-Trust Insurance Proof
Required:
- QR
- HASH
- WM
- SEAL
- MICRO
- GUIL
- SIG
- REVOKE
- PUBVERIFY where privacy-safe
- AUDIT
- CONF
- ACCESS

## S4 — High-Value / Claims / Legal
Required:
- all S3
- ACOPY
- MCHK
- stronger approval/audit chain
- stronger access restrictions

## S5 — Controlled Physical Security
Required:
- all S4
- UV
- HOLO where operationally supported
- STOCK
- print batch
- custody/spoilage controls

---

# 3. Exact Security Matrix

## Category A — Pre-Contract, Sales & Disclosure

| ID | Document | Tier | QR | HASH | WM | SEAL | MICRO | GUIL | ACOPY | SIG | MCHK | UV | HOLO | STOCK | REVOKE | PUBVERIFY | CONF | ACCESS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-001 | Insurance Quote | S1/S2 | Config | Yes | Light | No | No | Light | No | Optional | No | No | No | No | Yes | Optional | CUSTOMER_PRIVATE | A2 |
| DOC-002 | Quote Comparison | S1 | No | Yes | Light | No | No | No | No | No | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-003 | Insurance Proposal | S2 | Yes | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-004 | Insurance Application Form | S2 | Yes | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-005 | Product Information Sheet | S1 | Optional | Yes | Light | No | No | No | No | No | No | No | No | No | Versioned | Optional | PUBLIC_VERIFY | A1 |
| DOC-006 | Coverage Summary | S1/S2 | Config | Yes | Light | No | No | Light | No | Optional | No | No | No | No | Yes | Optional | CUSTOMER_PRIVATE | A2 |
| DOC-007 | Premium Illustration | S1/S2 | Config | Yes | Light | No | No | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-008 | Risk Declaration | S2 | Yes | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-009 | Risk Questionnaire | S2 | Yes | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-010 | Additional Information Request | S1 | Optional | Yes | Light | No | No | No | No | No | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-011 | Underwriting Requirements Notice | S1/S2 | Config | Yes | Light | No | No | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-012 | Conditional Offer | S2 | Yes | Yes | Yes | Optional | Optional | Light | No | Yes | Config | No | No | No | Yes | Optional | CUSTOMER_PRIVATE | A2/A3 |
| DOC-013 | Revised Offer | S2 | Yes | Yes | Yes | Optional | Optional | Light | No | Yes | Config | No | No | No | Yes | Optional | CUSTOMER_PRIVATE | A2/A3 |
| DOC-014 | Offer Acceptance Confirmation | S2 | Yes | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-015 | Electronic Consent Record | S2 | Optional | Yes | No | No | No | No | No | Cryptographic Evidence | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A3/A5 |

---

## Category B — Core Policy & Contract

| ID | Document | Tier | QR | HASH | WM | SEAL | MICRO | GUIL | ACOPY | SIG | MCHK | UV | HOLO | STOCK | REVOKE | PUBVERIFY | CONF | ACCESS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-016 | Insurance Policy | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Optional | Yes | Yes | Optional | No | Optional | Yes | Limited | CUSTOMER_PRIVATE | A2/A3 |
| DOC-017 | Policy Schedule | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | No | Optional | Yes | Yes | CUSTOMER_PRIVATE | A2 |
| DOC-018 | General Conditions | S2 | Yes | Yes | Light | No | Optional | Light | No | Optional | Yes for publish | No | No | No | Versioned | Optional | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |
| DOC-019 | Special Conditions | S2/S3 | Yes | Yes | Yes | Optional | Optional | Light | Optional | Yes | Yes | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2/A3 |
| DOC-020 | Specific Clauses Schedule | S2/S3 | Yes | Yes | Yes | Optional | Optional | Light | Optional | Yes | Yes | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2/A3 |
| DOC-021 | Cover Note | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |
| DOC-022 | Insurance Certificate | S3/S5 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional/Yes S5 | Optional/Yes S5 | Optional/Yes S5 | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-023 | Proof of Cover | S3 | Yes | Yes | Dynamic | Yes | Optional | Light | Optional | Yes | Config | No | No | No | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-024 | Policy Summary | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | Optional | CUSTOMER_PRIVATE | A2 |
| DOC-025 | Benefits Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-026 | Coverage Schedule | S2/S3 | Yes | Yes | Yes | Optional | Optional | Light | Optional | Yes | Config | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-027 | Exclusions Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-028 | Deductibles Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-029 | Insured Assets Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-030 | Insured Persons Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-031 | Premium Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-032 | Payment Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-033 | Policy Issue Confirmation | S2 | Yes | Yes | Yes | Optional | Optional | Light | No | Yes | Config | No | No | No | Yes | Optional | CUSTOMER_PRIVATE | A2 |
| DOC-034 | Duplicate Policy | S3 | Yes | Yes | DUPLICATE | Yes | Yes | Yes | Yes | Yes | Yes | Optional | No | Optional | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-035 | Policy Replacement Notice | S3 | Yes | Yes | REPLACED | Yes | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2/A3 |

---

## Category C — Motor

| ID | Document | Tier | QR | HASH | WM | SEAL | MICRO | GUIL | ACOPY | SIG | MCHK | UV | HOLO | STOCK | REVOKE | PUBVERIFY | CONF | ACCESS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-036 | Motor Insurance Attestation | S4/S5 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Yes S5 | Yes S5/Config | Yes S5 | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-037 | Motor Insurance Certificate | S4/S5 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Yes S5 | Yes S5/Config | Yes S5 | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-038 | Provisional Motor Attestation | S3 | Yes | Yes | PROVISIONAL | Yes | Yes | Yes | Yes | Yes | Yes | Optional | No | Optional | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-039 | Vehicle Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-040 | Fleet Vehicle Schedule | S2/S3 | Yes | Yes | Yes | Optional | Optional | Light | Optional | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-041 | Motor Risk Questionnaire | S2 | Yes | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-042 | Vehicle Inspection Request | S1/S2 | Config | Yes | Light | No | No | No | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-043 | Vehicle Inspection Report | S3 | Yes | Yes | Yes | Optional | Optional | Light | Optional | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A3/A4 |
| DOC-044 | Vehicle Valuation Report | S3 | Yes | Yes | Yes | Optional | Optional | Light | Optional | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A3/A4 |
| DOC-045 | Roadside Assistance Certificate | S3 | Yes | Yes | Dynamic | Yes | Optional | Light | Optional | Yes | Config | Optional | Optional | Optional | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-046 | Motor Assistance Card | S3/S5 | Yes | Yes | Dynamic | Yes | Optional | Light | Yes | Optional | Config | Optional | Optional | Optional | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-047 | Driver Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-048 | Authorized Driver Endorsement | S3 | Yes | Yes | Dynamic | Yes | Optional | Light | Optional | Yes | Yes | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-049 | Vehicle Replacement Endorsement | S3 | Yes | Yes | Dynamic | Yes | Optional | Light | Optional | Yes | Yes | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-050 | Territorial Extension Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Light | Optional | Yes | Yes | Optional | No | Optional | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-051 | Motor Coverage Extension Certificate | S3 | Yes | Yes | Dynamic | Yes | Optional | Light | Optional | Yes | Yes | Optional | No | Optional | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-052 | Fleet Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Light | Optional | Yes | Yes | Optional | No | Optional | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-053 | Motor Cancellation Certificate | S3 | Yes | Yes | CANCELLED | Yes | Yes | Light | Yes | Yes | Yes | No | No | No | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-054 | Motor Reinstatement Notice | S2/S3 | Yes | Yes | REINSTATED | Optional | Optional | Light | Optional | Yes | Yes | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-055 | Replacement Motor Certificate | S4/S5 | Yes | Yes | DUPLICATE/REPLACEMENT | Yes | Yes | Yes | Yes | Yes | Yes | Yes S5 | Config S5 | Yes S5 | Yes | Yes | PUBLIC_VERIFY | A1 |

---

## Category D — Health & Medical

| ID | Document | Tier | QR | HASH | WM | SEAL | MICRO | GUIL | ACOPY | SIG | MCHK | UV | HOLO | STOCK | REVOKE | PUBVERIFY | CONF | ACCESS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-056 | Health Enrollment Form | S2 | Yes | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-057 | Medical Declaration | S3 | No public QR | Yes | Yes | No | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | MEDICAL_RESTRICTED | A4 |
| DOC-058 | Dependant Enrollment Form | S2 | Yes | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-059 | Member Certificate | S3 | Yes | Yes | Dynamic | Yes | Optional | Light | Optional | Yes | Config | Optional | No | Optional | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-060 | Health Membership Card | S4/S5 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Optional | Config | Yes S5 | Config S5 | Yes S5 | Yes | Yes limited | CUSTOMER_PRIVATE | A2 |
| DOC-061 | Digital Health Card | S3 | Yes | Yes | Dynamic | Yes | Optional | Light | No | Tokenized | Config | No | No | No | Yes | Yes limited | CUSTOMER_PRIVATE | A2 |
| DOC-062 | Health Benefit Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-063 | Provider Network Directory | S1 | Optional | Yes | Light | No | No | No | No | No | No | No | No | No | Versioned | Optional | PUBLIC_VERIFY | A1 |
| DOC-064 | Eligibility Confirmation | S2 | Yes | Yes | Yes | No | Optional | Light | No | System | Config | No | No | No | Yes | Limited | MEDICAL_RESTRICTED | A2/A4 |
| DOC-065 | Preauthorization Request | S3 | Yes private | Yes | Yes | No | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | MEDICAL_RESTRICTED | A4 |
| DOC-066 | Preauthorization Approval | S4 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | Limited | MEDICAL_RESTRICTED | A4 |
| DOC-067 | Partial Preauthorization Approval | S4 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | Limited | MEDICAL_RESTRICTED | A4 |
| DOC-068 | Preauthorization Rejection | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | MEDICAL_RESTRICTED | A4 |
| DOC-069 | Guarantee of Payment | S4 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Limited | MEDICAL_RESTRICTED | A4 |
| DOC-070 | Hospital Admission Authorization | S4 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | Limited | MEDICAL_RESTRICTED | A4 |
| DOC-071 | Hospital Stay Extension Authorization | S4 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | Limited | MEDICAL_RESTRICTED | A4 |
| DOC-072 | Explanation of Benefits | S2/S3 | Yes private | Yes | Yes | Optional | Optional | Light | Optional | System | Config | No | No | No | Yes | No | MEDICAL_RESTRICTED | A2/A4 |
| DOC-073 | Member Reimbursement Statement | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes/System | Config | No | No | No | Yes | No | MEDICAL_RESTRICTED/FINANCIAL_RESTRICTED | A4 |
| DOC-074 | Benefit Exhaustion Notice | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | MEDICAL_RESTRICTED | A2/A4 |
| DOC-075 | Health Coverage Termination Certificate | S3 | Yes | Yes | TERMINATED | Yes | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2 |

---

## Category E — Life, Savings, Retirement & Beneficiaries

| ID | Document | Tier | QR | HASH | WM | SEAL | MICRO | GUIL | ACOPY | SIG | MCHK | UV | HOLO | STOCK | REVOKE | PUBVERIFY | CONF | ACCESS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-076 | Life Insurance Illustration | S1/S2 | Config | Yes | Light | No | No | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-077 | Life Contract Summary | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-078 | Life Proposal | S2 | Yes | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-079 | Life Medical Questionnaire | S3 | No public QR | Yes | Yes | No | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | MEDICAL_RESTRICTED | A4 |
| DOC-080 | Medical Examination Request | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | MEDICAL_RESTRICTED | A4 |
| DOC-081 | Financial Needs Declaration | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-082 | Beneficiary Nomination | S3 | Yes private | Yes | Dynamic | Optional | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-083 | Beneficiary Allocation Schedule | S3 | Yes private | Yes | Dynamic | Optional | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-084 | Beneficiary Change Request | S3 | Yes private | Yes | Yes | No | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-085 | Beneficiary Change Confirmation | S3 | Yes private | Yes | Dynamic | Yes | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-086 | Life Policy Schedule | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | No | Optional | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-087 | Life Benefit Schedule | S2/S3 | Yes | Yes | Yes | Optional | Optional | Light | Optional | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-088 | Contribution Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-089 | Annual Life Statement | S3 | Yes private | Yes | Dynamic | Optional | Optional | Light | Yes | System | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-090 | Surrender Request | S3 | Yes private | Yes | Yes | No | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-091 | Surrender Value Statement | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-092 | Policy Advance Application | S3 | Yes private | Yes | Yes | No | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-093 | Policy Advance Agreement | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-094 | Maturity Notice | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Optional | Yes/System | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-095 | Maturity Benefit Statement | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |

---

## Category F — Group & Corporate Insurance

| ID | Document | Tier | QR | HASH | WM | SEAL | MICRO | GUIL | ACOPY | SIG | MCHK | UV | HOLO | STOCK | REVOKE | PUBVERIFY | CONF | ACCESS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-096 | Master Group Policy | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | No | Optional | Yes | Limited | CUSTOMER_PRIVATE | A2/A3 |
| DOC-097 | Group Membership Form | S2 | Yes | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-098 | Employee Census | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A3/A4 |
| DOC-099 | Dependant Census | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A3/A4 |
| DOC-100 | Group Member Certificate | S3 | Yes | Yes | Dynamic | Yes | Optional | Light | Optional | Yes | Config | Optional | No | Optional | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-101 | Individual Benefit Certificate | S3 | Yes | Yes | Dynamic | Yes | Optional | Light | Optional | Yes | Config | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-102 | Employee Addition Notice | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A3 |
| DOC-103 | Employee Removal Notice | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A3 |
| DOC-104 | Group Movement Schedule | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A3/A4 |
| DOC-105 | Group Premium Statement | S3 | Yes private | Yes | Dynamic | Optional | Optional | Light | Yes | Yes/System | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A3/A4 |
| DOC-106 | Group Renewal Census | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A3 |
| DOC-107 | Corporate Benefit Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-108 | Corporate Policy Summary | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-109 | Corporate Insurance Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes limited | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |
| DOC-110 | Group Coverage Termination Notice | S3 | Yes private | Yes | TERMINATED | Yes | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |

---

## Category G — Property, Liability, Engineering & Cyber

| ID | Document | Tier | QR | HASH | WM | SEAL | MICRO | GUIL | ACOPY | SIG | MCHK | UV | HOLO | STOCK | REVOKE | PUBVERIFY | CONF | ACCESS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-111 | Property Risk Questionnaire | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-112 | Property Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-113 | Contents Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-114 | Stock Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-115 | Property Valuation Report | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A3/A4 |
| DOC-116 | Property Risk Survey | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A3/A4 |
| DOC-117 | Fire Safety Survey | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A3/A4 |
| DOC-118 | Risk Improvement Notice | S2/S3 | Yes private | Yes | Yes | Optional | Optional | Light | Optional | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-119 | Building Insurance Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes limited | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |
| DOC-120 | Business Multirisk Schedule | S2/S3 | Yes | Yes | Yes | Optional | Optional | Light | Optional | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-121 | Business Interruption Schedule | S2/S3 | Yes | Yes | Yes | Optional | Optional | Light | Optional | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-122 | Machinery Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-123 | Engineering Survey Report | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A3/A4 |
| DOC-124 | Construction Liability Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes limited | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |
| DOC-125 | Professional Liability Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes limited | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |
| DOC-126 | Public Liability Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes limited | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |
| DOC-127 | Employer Liability Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes limited | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |
| DOC-128 | Cyber Risk Questionnaire | S2 | Yes private | Yes | Yes | No | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A3/A5 |
| DOC-129 | Cybersecurity Controls Declaration | S2/S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A3/A5 |
| DOC-130 | Cyber Insurance Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes limited | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |

---

## Category H — Marine, Travel, Agriculture & Specialty

| ID | Document | Tier | QR | HASH | WM | SEAL | MICRO | GUIL | ACOPY | SIG | MCHK | UV | HOLO | STOCK | REVOKE | PUBVERIFY | CONF | ACCESS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-131 | Cargo Insurance Declaration | S2 | Yes | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-132 | Marine Cargo Policy | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | No | Optional | Yes | Limited | CUSTOMER_PRIVATE | A2/A3 |
| DOC-133 | Marine Cargo Certificate | S3/S5 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional/Yes S5 | Optional/Yes S5 | Optional/Yes S5 | Yes | Yes | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |
| DOC-134 | Shipment Insurance Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes limited | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |
| DOC-135 | Shipment Declaration | S2 | Yes | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-136 | Goods Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-137 | Marine Survey Report | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A3/A4 |
| DOC-138 | Travel Insurance Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-139 | Visa/Travel Coverage Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes | PUBLIC_VERIFY | A1 |
| DOC-140 | Travel Assistance Information | S1 | Optional | Yes | Light | No | No | No | No | No | No | No | No | No | Versioned | Optional | PUBLIC_VERIFY | A1 |
| DOC-141 | Farm Risk Declaration | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-142 | Crop Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-143 | Crop Insurance Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes limited | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |
| DOC-144 | Livestock Schedule | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-145 | Livestock Insurance Certificate | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Yes limited | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |

---

## Category I — Policy Servicing, Endorsements & Renewal

| ID | Document | Tier | QR | HASH | WM | SEAL | MICRO | GUIL | ACOPY | SIG | MCHK | UV | HOLO | STOCK | REVOKE | PUBVERIFY | CONF | ACCESS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-146 | Endorsement Request | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-147 | Policy Endorsement | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | No | Optional | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-148 | Revised Policy Schedule | S3 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | No | Optional | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-149 | Additional Premium Notice | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-150 | Premium Reduction Notice | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-151 | Renewal Notice | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | Optional | CUSTOMER_PRIVATE | A2 |
| DOC-152 | Renewal Quote | S1/S2 | Config | Yes | Light | No | No | Light | No | Optional | No | No | No | No | Yes | Optional | CUSTOMER_PRIVATE | A2 |
| DOC-153 | Renewal Confirmation | S2/S3 | Yes | Yes | Yes | Optional | Optional | Light | Optional | Yes | Config | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-154 | Non-Renewal Notice | S3 | Yes | Yes | NON-RENEWAL | Yes | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2 |
| DOC-155 | Suspension Notice | S3 | Yes | Yes | SUSPENDED | Yes | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-156 | Reinstatement Notice | S3 | Yes | Yes | REINSTATED | Yes | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-157 | Cancellation Request | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A3 |
| DOC-158 | Cancellation / Termination Notice | S3 | Yes | Yes | CANCELLED | Yes | Yes | Light | Yes | Yes | Yes | No | No | No | Yes | Limited | CUSTOMER_PRIVATE | A2 |
| DOC-159 | Policy Expiry Notice | S2 | Yes | Yes | EXPIRED | No | Optional | Light | No | Optional | Config | No | No | No | Yes | Optional | CUSTOMER_PRIVATE | A2 |
| DOC-160 | Policy Status Confirmation | S3 | Yes | Yes | Dynamic | Yes | Optional | Light | Yes | Yes | Config | No | No | No | Yes | Yes limited | PUBLIC_VERIFY/CUSTOMER_PRIVATE | A1/A2 |

---

## Category J — Claims, Assessment, Settlement & Recovery

| ID | Document | Tier | QR | HASH | WM | SEAL | MICRO | GUIL | ACOPY | SIG | MCHK | UV | HOLO | STOCK | REVOKE | PUBVERIFY | CONF | ACCESS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-161 | Claim Notification Form | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-162 | Claim Acknowledgement | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-163 | Claim Reference Confirmation | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-164 | Claim Requirements List | S1 | Optional | Yes | Light | No | No | No | No | No | No | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-165 | Additional Evidence Request | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-166 | Claim Investigation Notice | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A4 |
| DOC-167 | Expert / Adjuster Appointment | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A4 |
| DOC-168 | Inspection Appointment Notice | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-169 | Claim Assessment Report | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A4 |
| DOC-170 | Claim Valuation Statement | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-171 | Repair Authorization | S4 | Yes | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | Optional | Optional | Optional | Yes | Limited | FINANCIAL_RESTRICTED | A4 |
| DOC-172 | Medical Assessment Request | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | MEDICAL_RESTRICTED | A4 |
| DOC-173 | Medical Assessment Report | S4 | No public QR | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | MEDICAL_RESTRICTED | A4 |
| DOC-174 | Claim Decision | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | Limited | FINANCIAL_RESTRICTED | A4 |
| DOC-175 | Partial Approval Notice | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | Limited | FINANCIAL_RESTRICTED | A4 |
| DOC-176 | Claim Rejection Letter | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | Limited | INSURER_CONFIDENTIAL | A4 |
| DOC-177 | Settlement Offer | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-178 | Settlement Acceptance | S4 | Yes private | Yes | Dynamic | Optional | Yes | Yes | Yes | Yes | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-179 | Claim Discharge | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-180 | Claim Payment Advice | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | System/Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-181 | Claim Settlement Statement | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | System/Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-182 | Claim Closure Notice | S3 | Yes private | Yes | CLOSED | Optional | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-183 | Claim Appeal | S3 | Yes private | Yes | Yes | No | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | CUSTOMER_PRIVATE | A2/A4 |
| DOC-184 | Appeal Decision | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A4 |
| DOC-185 | Subrogation / Recovery Notice | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |

---

## Category K — Finance, Billing, Commission & Settlement

| ID | Document | Tier | QR | HASH | WM | SEAL | MICRO | GUIL | ACOPY | SIG | MCHK | UV | HOLO | STOCK | REVOKE | PUBVERIFY | CONF | ACCESS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-186 | Premium Invoice | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | Limited | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-187 | Premium Notice | S2 | Yes | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | Limited | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-188 | Debit Note | S2/S3 | Yes private | Yes | Yes | Optional | Optional | Light | Optional | Yes | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-189 | Credit Note | S2/S3 | Yes private | Yes | Yes | Optional | Optional | Light | Optional | Yes | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-190 | Premium Receipt | S3 | Yes | Yes | Dynamic | Optional/Finance | Yes | Light | Yes | System/Yes | Config | Optional | Optional | Optional | Yes | Yes limited | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-191 | Payment Receipt | S3 | Yes | Yes | Dynamic | Optional/Finance | Yes | Light | Yes | System/Yes | Config | Optional | Optional | Optional | Yes | Yes limited | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-192 | Refund Advice | S3 | Yes private | Yes | Dynamic | Optional | Optional | Light | Yes | Yes/System | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-193 | Customer Account Statement | S2 | Yes private | Yes | Yes | No | Optional | Light | No | System | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A2/A4 |
| DOC-194 | Broker Statement | S3 | Yes private | Yes | Dynamic | Optional | Optional | Light | Yes | Yes/System | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A3/A4 |
| DOC-195 | Agent Commission Statement | S3 | Yes private | Yes | Dynamic | Optional | Optional | Light | Yes | System/Yes | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A3/A4 |
| DOC-196 | Broker Commission Statement | S3 | Yes private | Yes | Dynamic | Optional | Optional | Light | Yes | System/Yes | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A3/A4 |
| DOC-197 | Carrier Settlement Statement | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes/System | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-198 | Provider Settlement Statement | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes/System | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-199 | Tax & Levy Breakdown | S2 | Yes private | Yes | Yes | No | Optional | Light | No | System | Config | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-200 | Reconciliation Statement | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes/System | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |

---

## Category L — Reinsurance, Co-insurance, Provider, Compliance & Regulatory

| ID | Document | Tier | QR | HASH | WM | SEAL | MICRO | GUIL | ACOPY | SIG | MCHK | UV | HOLO | STOCK | REVOKE | PUBVERIFY | CONF | ACCESS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| DOC-201 | Reinsurance Slip | S3 | Yes private | Yes | Dynamic | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A4 |
| DOC-202 | Facultative Placement Request | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A4 |
| DOC-203 | Facultative Quote | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Config | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A4 |
| DOC-204 | Reinsurance Confirmation | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A4 |
| DOC-205 | Treaty Summary | S3 | Yes private | Yes | Dynamic | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A4 |
| DOC-206 | Risk Bordereau | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | System/Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-207 | Premium Bordereau | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | System/Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-208 | Claims Bordereau | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | System/Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-209 | Reinsurance Recovery Request | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-210 | Reinsurance Settlement Statement | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes/System | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-211 | Co-insurance Placement | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A4 |
| DOC-212 | Co-insurance Participation Confirmation | S3 | Yes private | Yes | Dynamic | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A4 |
| DOC-213 | Co-insurance Premium Allocation | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | System/Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-214 | Co-insurance Claim Allocation | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | System/Yes | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-215 | Provider Contract | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | No | INSURER_CONFIDENTIAL | A4 |
| DOC-216 | Provider Tariff Schedule | S3 | Yes private | Yes | Yes | Optional | Optional | Light | Yes | Yes/System | Yes | No | No | No | Yes | No | FINANCIAL_RESTRICTED | A4 |
| DOC-217 | KYC Information Request | S2 | Yes private | Yes | Yes | No | Optional | Light | No | Optional | Config | No | No | No | Yes | No | REGULATORY_RESTRICTED | A5 |
| DOC-218 | KYC Approval / Remediation Notice | S3 | Yes private | Yes | Dynamic | Optional | Optional | Light | Yes | Yes | Yes | No | No | No | Yes | No | REGULATORY_RESTRICTED | A5 |
| DOC-219 | Regulatory Declaration / Return | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes/System | Yes | No | No | No | Yes | No | REGULATORY_RESTRICTED | A5 |
| DOC-220 | Regulatory Audit / Verification Report | S4 | Yes private | Yes | Dynamic | Yes | Yes | Yes | Yes | Yes | Yes | No | No | No | Yes | No | REGULATORY_RESTRICTED | A5 |

---

# 4. Physical Security Profiles

## PS-01 — Standard Secure Print
Use for optional controlled originals.

Includes:
- 300 DPI minimum;
- vector guilloche;
- microtext;
- anti-copy line field;
- print batch ID;
- physical issuance audit.

## PS-02 — UV Secure Print
Adds:
- UV-reactive logo;
- UV serial fragment;
- UV line pattern.

Use only where the printer and stock support real UV features.

## PS-03 — Holographic Secure Print
Adds:
- serialized hologram/tamper-evident foil;
- hologram inventory;
- assignment to document UUID;
- spoilage/destruction log.

## PS-04 — Controlled Stock
Adds:
- pre-numbered stock serial;
- stock batch;
- received/issued/spoiled/destroyed inventory;
- custodian;
- branch allocation.

Recommended primarily for:
- DOC-036;
- DOC-037;
- DOC-055;
- selected DOC-022;
- selected DOC-060;
- selected DOC-133;
- selected DOC-138/139 where an insurer chooses controlled physical certificates.

---

# 5. Dynamic Watermark Profiles

## WM-PUBLIC-PROOF
For public-verifiable certificates:
- issuer emblem;
- document-number fragment;
- low-opacity rosette.

## WM-PRIVATE
For customer-private documents:
- issuer emblem;
- masked customer/policy reference.

## WM-FINANCE
For finance:
- receipt/ledger motif;
- payment-reference fragment.

## WM-CLAIMS
For claims:
- claim-number fragment;
- claims shield mark.

## WM-MEDICAL
For health:
- restricted medical mark;
- member-reference fragment;
- never diagnosis text.

## WM-STATUS
Overlays:
- DRAFT;
- COPY;
- DUPLICATE;
- DEMONSTRATION;
- REVOKED;
- SUPERSEDED;
- CANCELLED;
- EXPIRED;
- VOID;
- REVERSED.

---

# 6. Seal Profiles

- **SEAL-01 CORPORATE** — official issuer identity.
- **SEAL-02 AUTHENTICATION** — secure document authenticity.
- **SEAL-03 FINANCE** — paid/financial validation.
- **SEAL-04 CLAIMS** — claims authorization.
- **SEAL-05 PROVIDER** — provider/preauthorization guarantee.
- **SEAL-06 BROKER VERIFIED** — broker-issued/verified operations.
- **SEAL-07 DUPLICATE** — replacement/duplicate.
- **SEAL-08 REVOKED** — revocation status overlay.

A seal is only valid when the corresponding backend authority and issuance state exists.

---

# 7. Public Verification Rules

Public verification must expose only privacy-safe fields.

Recommended public output:
- document type;
- document number;
- issuer;
- status;
- issue date;
- effective/expiry dates where relevant;
- masked insured/policyholder;
- masked policy/certificate reference;
- risk summary only when safe;
- replacement/revocation status.

Do not expose publicly:
- medical details;
- claim financial details;
- beneficiary allocations;
- bank/payment details;
- KYC data;
- reinsurer commercial terms;
- internal investigation findings.

---

# 8. Revocation and Replacement Rules

Every S2–S5 document that can materially affect rights, coverage, payment, or proof should support:

- original document ID;
- status;
- superseded-by;
- replacement-of;
- duplicate-of;
- revoked-at;
- revoked-by;
- revocation reason;
- replacement reason;
- verification result.

A revoked document must continue to verify as `REVOKED`.

A replacement must never erase the original history.

---

# 9. Security Issuance Gate

Before secure issuance, system must verify:

1. source transaction exists;
2. source status permits issuance;
3. issuer is authorized;
4. template version is active;
5. required fields are complete;
6. maker-checker completed where required;
7. document number reserved;
8. hash generated;
9. verification token generated;
10. seal/signature authority is valid;
11. secure stock/hologram serial assigned where required;
12. final PDF rendered;
13. final hash verified;
14. audit event persisted;
15. document marked ISSUED.

---

# 10. Claude / Developer Security Instruction

> **Do not downgrade document security for visual simplicity or implementation convenience. Apply the exact security profile assigned to each canonical document. Treat the matrix as the minimum control set. If a document contains high-value financial, medical, claims, legal, regulatory, or proof-of-cover information, use the stricter applicable profile. Do not fabricate a hologram, UV mark, security paper, seal, signature, or regulator security feature as decorative artwork and call it secure. Physical controls only count when backed by actual controlled printing, serial inventory, custody, spoilage, and issuance records. Digital authenticity must ultimately depend on the authoritative OpesInsure document registry, issuance status, verification token, cryptographic hash, version history, and audit trail. A beautiful but unverifiable certificate is a failed implementation.**

---

# 11. Locked Security Principles

1. All 220 documents have a defined baseline security profile.
2. Security tier can be increased by insurer configuration, but not lowered below this matrix without approved governance.
3. QR verification is not the only security feature; it is one layer.
4. Backend document status is authoritative.
5. Issued documents are immutable.
6. Revoked documents remain verifiable.
7. Sensitive information must not leak through public verification.
8. Physical security features require physical operational controls.
9. Every seal, hologram, secure stock batch, and signature must be traceable.
10. Security assets are governed institutional assets, not decoration.

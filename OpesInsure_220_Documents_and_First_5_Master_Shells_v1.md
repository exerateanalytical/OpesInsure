# OpesInsure Document Template & Security Master Specification v1.0

## Purpose

This specification defines:

1. the **exact 220 canonical document types** to be supported by OpesInsure; and
2. the **master shell specifications for the first five document templates**.

The document system is based on:

`Master Shell + Security Tier + Document Family Components + Dynamic Data + Verification + Lifecycle Status = Final Document`

No production document should be designed independently from this system.

---

# PART I — DOCUMENT SECURITY TIERS

## S1 — Basic Controlled Document

Use for quotations, informational letters, routine notices, and low-risk statements.

Required:
- canonical document number;
- template version;
- issuer identity;
- issue timestamp;
- footer compliance block;
- audit record.

Optional:
- QR verification;
- standard watermark.

## S2 — Standard Transaction Document

Use for receipts, schedules, endorsements, renewal notices, and premium notices.

Required:
- canonical numbering;
- QR verification;
- issuer identity;
- template version;
- document hash;
- standard watermark;
- audit history.

## S3 — High-Trust Insurance Proof

Use for policy certificates, cover notes, attestations, member certificates, and provider guarantees.

Required:
- QR verification;
- controlled numbering;
- authentication seal;
- microtext;
- guilloche/fine-line pattern;
- dynamic watermark;
- hash;
- signature/approval block;
- status verification.

Optional for physical originals:
- UV element.

## S4 — High-Value Financial / Claims / Legal Document

Use for claim decisions, settlement letters, discharge documents, and high-value finance documents.

Required:
- all S3 controls;
- maker-checker approval;
- signer identity;
- approval timestamp;
- stronger dynamic security pattern;
- immutable audit chain.

## S5 — Controlled Physical Security Document

Use only where real physical security printing is operationally supported.

Required:
- all S4 controls;
- secure paper/controlled stock;
- serial stock inventory;
- UV-reactive elements;
- holographic/tamper-evident element where justified;
- print batch number;
- issuance custody trail;
- spoiled-stock reconciliation.

> UV, holograms and secure-stock controls must not be simulated as mere artwork. They only count as security controls when backed by actual controlled printing/issuance operations.

---

# PART II — EXACT 220 CANONICAL DOCUMENT TYPES

## Category A — Pre-Contract, Sales & Disclosure (DOC-001–DOC-015)

| ID | English | French | Typical Security |
|---|---|---|---|
| DOC-001 | Insurance Quote | Devis d’assurance | S1 |
| DOC-002 | Quote Comparison | Comparatif des devis | S1 |
| DOC-003 | Insurance Proposal | Proposition d’assurance | S2 |
| DOC-004 | Insurance Application Form | Formulaire de souscription | S2 |
| DOC-005 | Product Information Sheet | Fiche d’information produit | S1 |
| DOC-006 | Coverage Summary | Résumé des garanties | S1 |
| DOC-007 | Premium Illustration | Illustration de prime | S1 |
| DOC-008 | Risk Declaration | Déclaration du risque | S2 |
| DOC-009 | Risk Questionnaire | Questionnaire de risque | S2 |
| DOC-010 | Additional Information Request | Demande d’informations complémentaires | S1 |
| DOC-011 | Underwriting Requirements Notice | Avis d’exigences de souscription | S1 |
| DOC-012 | Conditional Offer | Offre conditionnelle | S2 |
| DOC-013 | Revised Offer | Offre révisée | S2 |
| DOC-014 | Offer Acceptance Confirmation | Confirmation d’acceptation de l’offre | S2 |
| DOC-015 | Electronic Consent Record | Preuve de consentement électronique | S2 |

**Category total: 15**

---

## Category B — Core Policy & Contract Documents (DOC-016–DOC-035)

| ID | English | French | Security |
|---|---|---|---|
| DOC-016 | Insurance Policy | Police d’assurance | S3 |
| DOC-017 | Policy Schedule | Conditions particulières | S3 |
| DOC-018 | General Conditions | Conditions générales | S2 |
| DOC-019 | Special Conditions | Conditions spéciales | S2 |
| DOC-020 | Specific Clauses Schedule | Tableau des clauses particulières | S2 |
| DOC-021 | Cover Note | Note de couverture | S3 |
| DOC-022 | Insurance Certificate | Certificat d’assurance | S3 |
| DOC-023 | Proof of Cover | Justificatif de couverture | S3 |
| DOC-024 | Policy Summary | Résumé de police | S2 |
| DOC-025 | Benefits Schedule | Tableau des prestations | S2 |
| DOC-026 | Coverage Schedule | Tableau des garanties | S2 |
| DOC-027 | Exclusions Schedule | Tableau des exclusions | S2 |
| DOC-028 | Deductibles Schedule | Tableau des franchises | S2 |
| DOC-029 | Insured Assets Schedule | État des biens assurés | S2 |
| DOC-030 | Insured Persons Schedule | État des personnes assurées | S2 |
| DOC-031 | Premium Schedule | Échéancier de prime | S2 |
| DOC-032 | Payment Schedule | Échéancier de paiement | S2 |
| DOC-033 | Policy Issue Confirmation | Confirmation d’émission de police | S2 |
| DOC-034 | Duplicate Policy | Duplicata de police | S3 |
| DOC-035 | Policy Replacement Notice | Avis de remplacement de police | S3 |

**Category total: 20**

---

## Category C — Motor Insurance (DOC-036–DOC-055)

| ID | English | French | Security |
|---|---|---|---|
| DOC-036 | Motor Insurance Attestation | Attestation d’assurance automobile | S4/S5 |
| DOC-037 | Motor Insurance Certificate | Certificat d’assurance automobile | S4/S5 |
| DOC-038 | Provisional Motor Attestation | Attestation provisoire automobile | S3 |
| DOC-039 | Vehicle Schedule | État des véhicules | S2 |
| DOC-040 | Fleet Vehicle Schedule | État du parc automobile | S2 |
| DOC-041 | Motor Risk Questionnaire | Questionnaire de risque automobile | S2 |
| DOC-042 | Vehicle Inspection Request | Demande d’inspection du véhicule | S1 |
| DOC-043 | Vehicle Inspection Report | Rapport d’inspection automobile | S3 |
| DOC-044 | Vehicle Valuation Report | Rapport d’évaluation du véhicule | S3 |
| DOC-045 | Roadside Assistance Certificate | Certificat d’assistance routière | S3 |
| DOC-046 | Motor Assistance Card | Carte d’assistance automobile | S3 |
| DOC-047 | Driver Schedule | Liste des conducteurs | S2 |
| DOC-048 | Authorized Driver Endorsement | Avenant conducteur autorisé | S3 |
| DOC-049 | Vehicle Replacement Endorsement | Avenant de remplacement de véhicule | S3 |
| DOC-050 | Territorial Extension Certificate | Certificat d’extension territoriale | S3 |
| DOC-051 | Motor Coverage Extension Certificate | Certificat d’extension de garantie automobile | S3 |
| DOC-052 | Fleet Certificate | Certificat de flotte | S3 |
| DOC-053 | Motor Cancellation Certificate | Certificat de résiliation automobile | S3 |
| DOC-054 | Motor Reinstatement Notice | Avis de remise en vigueur automobile | S2 |
| DOC-055 | Replacement Motor Certificate | Duplicata de certificat automobile | S4 |

**Category total: 20**

---

## Category D — Health & Medical Insurance (DOC-056–DOC-075)

| ID | English | French | Security |
|---|---|---|---|
| DOC-056 | Health Enrollment Form | Formulaire d’adhésion santé | S2 |
| DOC-057 | Medical Declaration | Déclaration médicale | S3 |
| DOC-058 | Dependant Enrollment Form | Formulaire d’adhésion des ayants droit | S2 |
| DOC-059 | Member Certificate | Certificat d’adhésion | S3 |
| DOC-060 | Health Membership Card | Carte d’assuré santé | S4 |
| DOC-061 | Digital Health Card | Carte santé numérique | S3 |
| DOC-062 | Health Benefit Schedule | Tableau des prestations santé | S2 |
| DOC-063 | Provider Network Directory | Répertoire du réseau de soins | S1 |
| DOC-064 | Eligibility Confirmation | Confirmation d’éligibilité | S2 |
| DOC-065 | Preauthorization Request | Demande de prise en charge préalable | S3 |
| DOC-066 | Preauthorization Approval | Autorisation de prise en charge | S4 |
| DOC-067 | Partial Preauthorization Approval | Autorisation partielle de prise en charge | S4 |
| DOC-068 | Preauthorization Rejection | Refus de prise en charge | S3 |
| DOC-069 | Guarantee of Payment | Lettre de garantie / Prise en charge | S4 |
| DOC-070 | Hospital Admission Authorization | Autorisation d’hospitalisation | S4 |
| DOC-071 | Hospital Stay Extension Authorization | Autorisation de prolongation d’hospitalisation | S4 |
| DOC-072 | Explanation of Benefits | Relevé des prestations | S2 |
| DOC-073 | Member Reimbursement Statement | Décompte de remboursement | S3 |
| DOC-074 | Benefit Exhaustion Notice | Avis d’épuisement de garantie | S2 |
| DOC-075 | Health Coverage Termination Certificate | Certificat de cessation de couverture santé | S3 |

**Category total: 20**

---

## Category E — Life, Savings, Retirement & Beneficiaries (DOC-076–DOC-095)

| ID | English | French | Security |
|---|---|---|---|
| DOC-076 | Life Insurance Illustration | Illustration d’assurance vie | S1 |
| DOC-077 | Life Contract Summary | Encadré récapitulatif du contrat vie | S2 |
| DOC-078 | Life Proposal | Proposition d’assurance vie | S2 |
| DOC-079 | Life Medical Questionnaire | Questionnaire médical vie | S3 |
| DOC-080 | Medical Examination Request | Demande d’examen médical | S2 |
| DOC-081 | Financial Needs Declaration | Déclaration des besoins financiers | S2 |
| DOC-082 | Beneficiary Nomination | Désignation de bénéficiaire | S3 |
| DOC-083 | Beneficiary Allocation Schedule | Répartition des bénéficiaires | S3 |
| DOC-084 | Beneficiary Change Request | Demande de modification de bénéficiaire | S3 |
| DOC-085 | Beneficiary Change Confirmation | Confirmation de modification de bénéficiaire | S3 |
| DOC-086 | Life Policy Schedule | Conditions particulières vie | S3 |
| DOC-087 | Life Benefit Schedule | Tableau des prestations vie | S2 |
| DOC-088 | Contribution Schedule | Échéancier des cotisations | S2 |
| DOC-089 | Annual Life Statement | Relevé annuel assurance vie | S3 |
| DOC-090 | Surrender Request | Demande de rachat | S3 |
| DOC-091 | Surrender Value Statement | Relevé de valeur de rachat | S4 |
| DOC-092 | Policy Advance Application | Demande d’avance sur police | S3 |
| DOC-093 | Policy Advance Agreement | Convention d’avance sur police | S4 |
| DOC-094 | Maturity Notice | Avis d’échéance du contrat vie | S3 |
| DOC-095 | Maturity Benefit Statement | Décompte de prestation à l’échéance | S4 |

**Category total: 20**

---

## Category F — Group & Corporate Insurance (DOC-096–DOC-110)

| ID | English | French | Security |
|---|---|---|---|
| DOC-096 | Master Group Policy | Police groupe | S3 |
| DOC-097 | Group Membership Form | Bulletin d’adhésion groupe | S2 |
| DOC-098 | Employee Census | État nominatif des employés | S2 |
| DOC-099 | Dependant Census | État des ayants droit | S2 |
| DOC-100 | Group Member Certificate | Certificat d’adhésion groupe | S3 |
| DOC-101 | Individual Benefit Certificate | Certificat individuel de prestations | S3 |
| DOC-102 | Employee Addition Notice | Avis d’ajout d’employé | S2 |
| DOC-103 | Employee Removal Notice | Avis de retrait d’employé | S2 |
| DOC-104 | Group Movement Schedule | État des mouvements du groupe | S2 |
| DOC-105 | Group Premium Statement | État des primes groupe | S3 |
| DOC-106 | Group Renewal Census | État de renouvellement du groupe | S2 |
| DOC-107 | Corporate Benefit Schedule | Tableau des garanties entreprise | S2 |
| DOC-108 | Corporate Policy Summary | Résumé de police entreprise | S2 |
| DOC-109 | Corporate Insurance Certificate | Certificat d’assurance entreprise | S3 |
| DOC-110 | Group Coverage Termination Notice | Avis de cessation de couverture groupe | S3 |

**Category total: 15**

---

## Category G — Property, Liability, Engineering & Cyber (DOC-111–DOC-130)

| ID | English | French | Security |
|---|---|---|---|
| DOC-111 | Property Risk Questionnaire | Questionnaire de risque immobilier | S2 |
| DOC-112 | Property Schedule | État des biens immobiliers | S2 |
| DOC-113 | Contents Schedule | État du contenu assuré | S2 |
| DOC-114 | Stock Schedule | État des stocks | S2 |
| DOC-115 | Property Valuation Report | Rapport d’évaluation immobilière | S3 |
| DOC-116 | Property Risk Survey | Rapport de visite de risque | S3 |
| DOC-117 | Fire Safety Survey | Rapport de sécurité incendie | S3 |
| DOC-118 | Risk Improvement Notice | Recommandations d’amélioration du risque | S2 |
| DOC-119 | Building Insurance Certificate | Certificat d’assurance bâtiment | S3 |
| DOC-120 | Business Multirisk Schedule | État multirisque entreprise | S2 |
| DOC-121 | Business Interruption Schedule | Tableau pertes d’exploitation | S2 |
| DOC-122 | Machinery Schedule | État des machines | S2 |
| DOC-123 | Engineering Survey Report | Rapport d’expertise technique | S3 |
| DOC-124 | Construction Liability Certificate | Certificat RC chantier | S3 |
| DOC-125 | Professional Liability Certificate | Attestation RC professionnelle | S3 |
| DOC-126 | Public Liability Certificate | Attestation responsabilité civile exploitation | S3 |
| DOC-127 | Employer Liability Certificate | Attestation responsabilité employeur | S3 |
| DOC-128 | Cyber Risk Questionnaire | Questionnaire cyber-risque | S2 |
| DOC-129 | Cybersecurity Controls Declaration | Déclaration des mesures cybersécurité | S2 |
| DOC-130 | Cyber Insurance Certificate | Certificat d’assurance cyber | S3 |

**Category total: 20**

---

## Category H — Marine, Travel, Agriculture & Specialty (DOC-131–DOC-145)

| ID | English | French | Security |
|---|---|---|---|
| DOC-131 | Cargo Insurance Declaration | Déclaration d’assurance marchandises | S2 |
| DOC-132 | Marine Cargo Policy | Police facultés | S3 |
| DOC-133 | Marine Cargo Certificate | Certificat d’assurance marchandises | S3 |
| DOC-134 | Shipment Insurance Certificate | Certificat d’assurance expédition | S3 |
| DOC-135 | Shipment Declaration | Déclaration d’expédition | S2 |
| DOC-136 | Goods Schedule | État des marchandises | S2 |
| DOC-137 | Marine Survey Report | Rapport d’expertise maritime | S3 |
| DOC-138 | Travel Insurance Certificate | Certificat d’assurance voyage | S3 |
| DOC-139 | Visa/Travel Coverage Certificate | Certificat de couverture voyage/visa | S3 |
| DOC-140 | Travel Assistance Information | Informations d’assistance voyage | S1 |
| DOC-141 | Farm Risk Declaration | Déclaration de risque agricole | S2 |
| DOC-142 | Crop Schedule | État des cultures | S2 |
| DOC-143 | Crop Insurance Certificate | Certificat d’assurance récolte | S3 |
| DOC-144 | Livestock Schedule | État du cheptel | S2 |
| DOC-145 | Livestock Insurance Certificate | Certificat d’assurance bétail | S3 |

**Category total: 15**

---

## Category I — Policy Servicing, Endorsements & Renewal (DOC-146–DOC-160)

| ID | English | French | Security |
|---|---|---|---|
| DOC-146 | Endorsement Request | Demande d’avenant | S2 |
| DOC-147 | Policy Endorsement | Avenant | S3 |
| DOC-148 | Revised Policy Schedule | Conditions particulières révisées | S3 |
| DOC-149 | Additional Premium Notice | Avis de prime complémentaire | S2 |
| DOC-150 | Premium Reduction Notice | Avis de réduction de prime | S2 |
| DOC-151 | Renewal Notice | Avis d’échéance | S2 |
| DOC-152 | Renewal Quote | Devis de renouvellement | S1 |
| DOC-153 | Renewal Confirmation | Confirmation de renouvellement | S2 |
| DOC-154 | Non-Renewal Notice | Avis de non-renouvellement | S3 |
| DOC-155 | Suspension Notice | Avis de suspension des garanties | S3 |
| DOC-156 | Reinstatement Notice | Avis de remise en vigueur | S3 |
| DOC-157 | Cancellation Request | Demande de résiliation | S2 |
| DOC-158 | Cancellation / Termination Notice | Avis de résiliation | S3 |
| DOC-159 | Policy Expiry Notice | Avis d’expiration | S2 |
| DOC-160 | Policy Status Confirmation | Attestation de situation du contrat | S3 |

**Category total: 15**

---

## Category J — Claims, Assessment, Settlement & Recovery (DOC-161–DOC-185)

| ID | English | French | Security |
|---|---|---|---|
| DOC-161 | Claim Notification Form | Déclaration de sinistre | S2 |
| DOC-162 | Claim Acknowledgement | Accusé de réception de sinistre | S2 |
| DOC-163 | Claim Reference Confirmation | Confirmation du numéro de sinistre | S2 |
| DOC-164 | Claim Requirements List | Liste des pièces à fournir | S1 |
| DOC-165 | Additional Evidence Request | Demande de pièces complémentaires | S2 |
| DOC-166 | Claim Investigation Notice | Avis d’enquête sinistre | S3 |
| DOC-167 | Expert / Adjuster Appointment | Mandat d’expertise | S3 |
| DOC-168 | Inspection Appointment Notice | Convocation à expertise | S2 |
| DOC-169 | Claim Assessment Report | Rapport d’expertise sinistre | S3 |
| DOC-170 | Claim Valuation Statement | Évaluation du sinistre | S3 |
| DOC-171 | Repair Authorization | Autorisation de réparation | S4 |
| DOC-172 | Medical Assessment Request | Demande d’expertise médicale | S3 |
| DOC-173 | Medical Assessment Report | Rapport d’expertise médicale | S4 |
| DOC-174 | Claim Decision | Décision sur sinistre | S4 |
| DOC-175 | Partial Approval Notice | Notification d’acceptation partielle | S4 |
| DOC-176 | Claim Rejection Letter | Lettre de rejet | S4 |
| DOC-177 | Settlement Offer | Offre d’indemnisation | S4 |
| DOC-178 | Settlement Acceptance | Acceptation d’indemnisation | S4 |
| DOC-179 | Claim Discharge | Quittance / Décharge de règlement | S4 |
| DOC-180 | Claim Payment Advice | Avis de paiement sinistre | S4 |
| DOC-181 | Claim Settlement Statement | Décompte d’indemnisation | S4 |
| DOC-182 | Claim Closure Notice | Avis de clôture du sinistre | S3 |
| DOC-183 | Claim Appeal | Recours contre décision | S3 |
| DOC-184 | Appeal Decision | Décision sur recours | S4 |
| DOC-185 | Subrogation / Recovery Notice | Avis de subrogation / recours | S4 |

**Category total: 25**

---

## Category K — Finance, Billing, Commission & Settlement (DOC-186–DOC-200)

| ID | English | French | Security |
|---|---|---|---|
| DOC-186 | Premium Invoice | Facture de prime | S2 |
| DOC-187 | Premium Notice | Avis de prime | S2 |
| DOC-188 | Debit Note | Note de débit | S2 |
| DOC-189 | Credit Note | Note de crédit | S2 |
| DOC-190 | Premium Receipt | Quittance de prime | S3 |
| DOC-191 | Payment Receipt | Reçu de paiement | S3 |
| DOC-192 | Refund Advice | Avis de remboursement | S3 |
| DOC-193 | Customer Account Statement | Relevé de compte client | S2 |
| DOC-194 | Broker Statement | Relevé courtier | S3 |
| DOC-195 | Agent Commission Statement | Relevé de commission agent | S3 |
| DOC-196 | Broker Commission Statement | Relevé de commission courtier | S3 |
| DOC-197 | Carrier Settlement Statement | Relevé de règlement assureur | S4 |
| DOC-198 | Provider Settlement Statement | Relevé de règlement prestataire | S4 |
| DOC-199 | Tax & Levy Breakdown | Décompte taxes et prélèvements | S2 |
| DOC-200 | Reconciliation Statement | État de rapprochement | S4 |

**Category total: 15**

---

## Category L — Reinsurance, Co-insurance, Provider, Compliance & Regulatory (DOC-201–DOC-220)

| ID | English | French | Security |
|---|---|---|---|
| DOC-201 | Reinsurance Slip | Slip de réassurance | S3 |
| DOC-202 | Facultative Placement Request | Demande de placement facultatif | S3 |
| DOC-203 | Facultative Quote | Cotation facultative | S3 |
| DOC-204 | Reinsurance Confirmation | Confirmation de réassurance | S4 |
| DOC-205 | Treaty Summary | Résumé de traité de réassurance | S3 |
| DOC-206 | Risk Bordereau | Bordereau de risques | S4 |
| DOC-207 | Premium Bordereau | Bordereau de primes | S4 |
| DOC-208 | Claims Bordereau | Bordereau de sinistres | S4 |
| DOC-209 | Reinsurance Recovery Request | Demande de recours en réassurance | S4 |
| DOC-210 | Reinsurance Settlement Statement | Relevé de règlement réassurance | S4 |
| DOC-211 | Co-insurance Placement | Placement en coassurance | S3 |
| DOC-212 | Co-insurance Participation Confirmation | Confirmation de participation en coassurance | S3 |
| DOC-213 | Co-insurance Premium Allocation | Répartition de prime en coassurance | S4 |
| DOC-214 | Co-insurance Claim Allocation | Répartition de sinistre en coassurance | S4 |
| DOC-215 | Provider Contract | Convention prestataire | S4 |
| DOC-216 | Provider Tariff Schedule | Grille tarifaire prestataire | S3 |
| DOC-217 | KYC Information Request | Demande d’informations KYC | S2 |
| DOC-218 | KYC Approval / Remediation Notice | Avis d’approbation / régularisation KYC | S3 |
| DOC-219 | Regulatory Declaration / Return | Déclaration / état réglementaire | S4 |
| DOC-220 | Regulatory Audit / Verification Report | Rapport d’audit / vérification réglementaire | S4 |

**Category total: 20**

---

# DOCUMENT COUNT VALIDATION

| Category | Count |
|---|---:|
| A — Pre-Contract | 15 |
| B — Core Policy | 20 |
| C — Motor | 20 |
| D — Health | 20 |
| E — Life | 20 |
| F — Group/Corporate | 15 |
| G — Property/Liability/Engineering/Cyber | 20 |
| H — Marine/Travel/Agriculture/Specialty | 15 |
| I — Servicing/Renewal | 15 |
| J — Claims | 25 |
| K — Finance | 15 |
| L — Reinsurance/Co-insurance/Provider/Regulatory | 20 |
| **TOTAL** | **220** |

---

# PART III — MASTER SHELL SYSTEM

All templates derive from a shared document anatomy.

## Canonical A4 Zones

### Zone A — Header Security Band
Height: **24–30 mm**

Contains:
- issuer logo;
- issuer legal name;
- document family icon;
- document title;
- security motif;
- document classification;
- optional microtext strip.

### Zone B — Document Identity Block
Height: **22–32 mm**

Contains:
- document number;
- policy/claim/reference number;
- issue date;
- effective date;
- expiry date;
- branch;
- template version;
- status.

### Zone C — Party / Risk Summary
Variable height.

Contains:
- policyholder;
- insured;
- beneficiary where applicable;
- insured object;
- vehicle/property/member details.

### Zone D — Main Transaction Content
Main body.

Contains:
- premium;
- coverage;
- limits;
- declarations;
- schedules;
- claim/finance information;
- terms.

### Zone E — Authorization / Signature
Contains:
- issuer;
- role;
- approval;
- signature;
- seal;
- timestamp.

### Zone F — Verification Block
Contains:
- QR;
- short verification code;
- verification instructions;
- hash fragment;
- status validation note.

### Zone G — Compliance Footer
Height: **15–22 mm**

Contains:
- registered address;
- contact;
- page number;
- template code/version;
- legal/compliance note;
- confidentiality classification.

---

# PART IV — MASTER SHELL 01: INSURANCE QUOTATION

## Shell Code
`TPL-SHELL-QUOTE-001`

## Canonical Document
`DOC-001`

## Page Format
- A4 portrait
- 210 × 297 mm
- 3 mm bleed when commercially printed
- 14–16 mm safe margin
- digital PDF master
- 300 DPI equivalent for raster security assets

## Security Tier
**S1 by default**
**S2 when insurer wants verifiable quotations**

## Visual Character
- commercial;
- clear;
- professional;
- less formal than policy/certificate;
- premium amount immediately visible.

## Header
Left:
- insurer logo;
- insurer legal name.

Center:
- **INSURANCE QUOTATION / DEVIS D’ASSURANCE**

Right:
- quotation number;
- status badge: `DRAFT`, `QUOTED`, `EXPIRED`, `ACCEPTED`.

Background:
- subtle security-line pattern;
- no heavy guilloche.

## Identity Block
Fields:
- Quote Number
- Customer
- Product
- Plan
- Insurer
- Broker/Agent
- Issue Date
- Valid Until
- Currency

## Hero Summary Panel
Large, clean summary:
- Gross Premium
- Payment Frequency
- Sum Insured / Main Limit
- Deductible
- Quote Validity

## Risk Summary
Dynamic by product.

Motor example:
- Make
- Model
- Year
- Registration
- Use
- Declared Value

## Coverage Table
Columns:
- Coverage
- Limit
- Deductible
- Premium Contribution
- Included/Optional

## Premium Breakdown
- base premium;
- loadings;
- discounts;
- taxes;
- levies;
- fees;
- gross premium.

## Important Notices
Must clearly state:
- quotation validity;
- subject to underwriting where applicable;
- quote is not automatically proof of cover;
- exclusions/conditions reference.

## Verification
Optional S1.
Required for S2.

If enabled:
- 22–25 mm QR;
- short verification code;
- portal verification instruction.

## Watermark
Subtle issuer emblem or `QUOTATION`.

Do not use:
- hologram;
- UV;
- heavy certificate frame.

## Icons
- quotation/document icon;
- shield for coverage;
- currency/receipt icon for premium;
- clock/calendar icon for validity.

## Footer
- insurer legal details;
- broker details where relevant;
- quote template version;
- page number.

---

# PART V — MASTER SHELL 02: POLICY SCHEDULE

## Shell Code
`TPL-SHELL-POLICY-SCHEDULE-001`

## Canonical Document
`DOC-017`

## Page Format
- A4 portrait
- multi-page permitted
- stable page header/footer
- page numbering `Page X of Y`

## Security Tier
**S3**

## Visual Character
- official;
- structured;
- restrained;
- high-trust;
- easy to audit.

## Header Security Band
Contains:
- insurer logo;
- security guilloche strip;
- document title:
  **POLICY SCHEDULE / CONDITIONS PARTICULIÈRES**
- policy-family icon;
- policy number;
- issue status.

Microtext:
- repeated insurer name or verification phrase.

## Central Watermark
Large low-opacity:
- insurer emblem;
- optional dynamic policy number fragment.

## Identity Block
Required:
- Policy Number
- Product
- Product Version
- Policyholder
- Insured
- Broker/Agent
- Issue Date
- Effective From
- Effective Until
- Currency
- Branch

## Coverage Period Panel
Highly visible:
- Start date/time
- End date/time
- Renewal basis
- Territory

## Insured Risk Section
Changes based on product:
- motor vehicle;
- property;
- person;
- cargo;
- business;
- project.

## Coverage Schedule
Columns:
- Coverage
- Sum Insured / Limit
- Deductible
- Premium
- Conditions/Reference

## Exclusions / Special Conditions
Compact structured section.

Do not reproduce entire general conditions unless configured.

## Premium Block
- net premium;
- taxes/levies;
- fees;
- gross premium;
- installment plan;
- payment status where appropriate.

## Authorization Block
Contains:
- authorized issuer name;
- role;
- digital signature/approval;
- corporate authentication seal;
- issue timestamp.

## Verification Block
Mandatory:
- QR;
- short verification code;
- document number;
- hash fragment;
- `VALID / REVOKED / SUPERSEDED` verification result available online.

## Fine-Line Security
Required:
- guilloche header/border accents;
- microtext;
- anti-copy line field behind verification block.

## Physical Security
Optional:
- UV emblem if printed as controlled original.

Hologram:
- generally unnecessary unless insurer chooses S5 physical stock.

## Status Overlays
- `DRAFT`
- `COPY`
- `DUPLICATE`
- `REVOKED`
- `SUPERSEDED`
- `DEMONSTRATION`

`REVOKED` and `SUPERSEDED` must be highly visible.

---

# PART VI — MASTER SHELL 03: POLICY CERTIFICATE

## Shell Code
`TPL-SHELL-POLICY-CERTIFICATE-001`

## Canonical Document
`DOC-022`

## Format
- A4 portrait by default
- certificate-style centered composition
- one-page target where possible

## Security Tier
**S3**
Can become **S5** for controlled physical certificates.

## Visual Character
- authoritative;
- formal;
- certificate-like;
- cleaner and less data-heavy than policy schedule.

## Border
Multi-line fine security border.

Use:
- guilloche corners;
- subtle rosette elements;
- microtext perimeter.

## Header
- issuer logo;
- legal issuer name;
- **CERTIFICATE OF INSURANCE / CERTIFICAT D’ASSURANCE**
- certificate number.

## Certificate Statement
Large formal declaration.

Structure:
- certifies that;
- named insured/policyholder;
- described risk/object;
- is insured;
- under policy number;
- for stated period;
- subject to policy terms and conditions.

## Core Data Panel
Required:
- Certificate Number
- Policy Number
- Insured/Policyholder
- Product/Class
- Insured Object
- Effective Date
- Expiry Date
- Territory

## Central Security Watermark
- insurer emblem;
- certificate rosette;
- dynamic number fragment optional.

## Authentication Seal
Prominent but not oversized.

Must be a dedicated authentication seal, not simply a decorative logo.

## QR Verification Block
Mandatory.

Position:
- lower-right or centered lower zone.

Must include:
- QR;
- short code;
- verification instruction.

## Signature
- authorized signatory;
- title;
- issue timestamp;
- digital signature indicator where applicable.

## Microtext
Recommended around:
- border;
- seal ring;
- verification strip.

## UV
Optional on controlled printed originals:
- hidden issuer emblem;
- certificate number fragment.

## Hologram
Only if S5 physical issuance exists:
- tamper-evident serialized hologram;
- stock inventory required.

## Anti-Copy
Use:
- very fine background line field;
- anti-copy rosette;
- security phrase.

## Status
Possible:
- VALID
- EXPIRED
- REVOKED
- REPLACED
- DUPLICATE
- DEMONSTRATION

---

# PART VII — MASTER SHELL 04: MOTOR INSURANCE ATTESTATION

## Shell Code
`TPL-SHELL-MOTOR-ATTESTATION-001`

## Canonical Document
`DOC-036`

## Format
Primary digital record:
- A4 portrait or regulator-approved physical layout.

If physical controlled certificate stock is used, dimensions must follow the actual approved format used by the issuing insurer/regulatory environment.

## Security Tier
**S4 minimum**
**S5 for controlled physical originals**

## Visual Character
- highly controlled;
- instantly recognizable;
- high-security;
- minimal ambiguity.

## Header
- insurer identity;
- motor shield icon;
- title:
  **MOTOR INSURANCE ATTESTATION / ATTESTATION D’ASSURANCE AUTOMOBILE**
- attestation serial number.

## Vehicle Identity Block
Prominent fields:
- Registration Number
- VIN/Chassis Number
- Make
- Model
- Model Year
- Vehicle Type
- Usage
- Engine/Variant where required

Registration number should be one of the visually strongest fields.

## Insured Block
- Policyholder
- Insured where different
- Customer reference
- Policy number

## Validity Block
Very prominent:
- Effective From
- Effective Until
- Issue Date
- Territorial validity

## Cover Statement
Concise confirmation of cover.

Avoid overcrowding.

## Security Features — Digital
Required:
- QR verification;
- short verification code;
- document hash;
- controlled serial;
- dynamic watermark;
- issuer seal;
- signature/authorization;
- revocation capability.

## Security Features — Physical S5
Recommended where operationally implemented:
- UV-reactive security lines;
- UV issuer emblem;
- hidden serial repeat;
- tamper-evident hologram or foil element;
- guilloche background;
- rosette;
- microtext;
- anti-copy pattern;
- controlled security paper;
- stock serial;
- print batch ID.

## Hologram Position
If used:
- lower-left or lower-center;
- must not cover variable text;
- hologram serial linked to issued document record.

## UV Pattern
Suggested:
- repeating vehicle shield motif;
- issuer code;
- partial attestation serial.

## Watermark
Dynamic:
- insurer emblem;
- policy/attestation-number fragment.

## Seal
Authentication seal required.

Possible additional:
- branch issue seal where operationally justified.

## Verification Block
Must state clearly:
- `SCAN TO VERIFY`;
- verification URL;
- short code;
- validity determined by live portal status.

## Anti-Fraud Rule
A photocopy or screenshot does not independently prove current validity; verification status is authoritative.

## Status Overlays
- EXPIRED
- CANCELLED
- REVOKED
- REPLACED
- DUPLICATE
- DEMONSTRATION

---

# PART VIII — MASTER SHELL 05: PREMIUM RECEIPT

## Shell Code
`TPL-SHELL-PREMIUM-RECEIPT-001`

## Canonical Document
`DOC-190`

## Format
Primary:
- A4 portrait.

Alternative:
- A5;
- POS/thermal variant for cashier operation.

## Security Tier
**S3**

## Visual Character
- transactional;
- clean;
- finance-oriented;
- unmistakably paid/unpaid.

## Header
- insurer/broker logo;
- title:
  **PREMIUM RECEIPT / QUITTANCE DE PRIME**
- receipt number;
- `PAID` status indicator only after reconciled payment.

## Receipt Identity Block
- Receipt Number
- Payment Reference
- Policy Number
- Invoice / Premium Notice Number
- Customer
- Payer
- Date/Time
- Branch
- Cashier / Channel

## Amount Panel
Highly prominent:
- Amount Received
- Currency
- Amount in Words
- Payment Method
- Allocation Status

## Premium Allocation Table
Columns:
- Obligation / Policy
- Period
- Amount Due
- Amount Applied
- Balance

This supports one payment allocated across multiple obligations.

## Payment Method Data
Examples:
- cash;
- MTN MoMo;
- Orange Money;
- bank transfer;
- card;
- cheque.

Include external transaction reference when applicable.

## Financial Status
Allowed:
- PAID
- PARTIALLY PAID
- REVERSED
- REFUNDED
- PARTIALLY REFUNDED
- VOID

Never display `PAID` solely because a payment was initiated.

## QR Verification
Mandatory:
- receipt verification token;
- amount/status display must be privacy-controlled.

## Security Pattern
- subtle anti-copy background behind amount section;
- microtext strip;
- dynamic receipt watermark;
- unique document number.

## Seal
Finance authentication seal optional visually, but backend issuer authentication is mandatory.

## Signature
For digital:
- system-issued / authorized cashier identity.

For cash counter:
- cashier name/code;
- optional signature.

## Reversal Handling
Original receipt must remain retrievable.

If reversed:
- verification portal shows `REVERSED`;
- receipt receives visible status overlay;
- reversal document/reference must be linked.

## Refund Handling
Receipt remains immutable.

Refund creates a separate refund record/document.

## Footer
- issuer legal details;
- payment support/contact;
- template version;
- audit/reference notice.

---

# PART IX — SHARED SECURITY ARTIFACT SPECIFICATIONS

## Guilloche System

Create reusable families:
- GUIL-01 Header Wave
- GUIL-02 Corner Loop
- GUIL-03 Certificate Rosette
- GUIL-04 Verification Backplate
- GUIL-05 Motor Security Field

Rules:
- vector;
- scalable;
- fine-line;
- never rasterized unnecessarily;
- no generic clip-art.

## Watermark System

Create:
- WM-01 Corporate Emblem
- WM-02 Policy Shield
- WM-03 Motor Shield
- WM-04 Health Mark
- WM-05 Finance Mark
- WM-06 Claims Mark

Status:
- WM-STATUS-DRAFT
- WM-STATUS-COPY
- WM-STATUS-DUPLICATE
- WM-STATUS-DEMONSTRATION
- WM-STATUS-REVOKED
- WM-STATUS-SUPERSEDED

## Seal System

Create:
- SEAL-01 Corporate
- SEAL-02 Authentication
- SEAL-03 Finance Paid
- SEAL-04 Claims Authorization
- SEAL-05 Provider Authorization
- SEAL-06 Broker Verified
- SEAL-07 Duplicate
- SEAL-08 Revoked

## Icon System

Create single reusable icons for:
- policy;
- motor;
- health;
- life;
- property;
- business;
- travel;
- marine;
- construction;
- claim;
- renewal;
- endorsement;
- receipt;
- finance;
- provider;
- reinsurance;
- verification;
- security.

Every icon should exist as:
- SVG master;
- 16 px;
- 20 px;
- 24 px;
- 32 px;
- print vector.

---

# PART X — PHYSICAL SECURITY GOVERNANCE

## Secure Stock

For S5:
- stock serial number;
- supplier;
- batch number;
- quantity received;
- quantity issued;
- quantity spoiled;
- quantity destroyed;
- quantity remaining.

## Hologram Inventory

Track:
- hologram batch;
- hologram serial;
- assigned document;
- issued by;
- issued date;
- void/destroyed status.

## Spoilage

Spoiled certificates cannot simply be discarded.

Record:
- document/stock serial;
- spoilage reason;
- officer;
- checker;
- destruction confirmation.

---

# PART XI — DIGITAL SECURITY GOVERNANCE

Each issued secure document stores:

- document UUID;
- canonical document type;
- template ID;
- template version;
- issuer;
- issue timestamp;
- effective dates;
- SHA-256 hash;
- verification token;
- status;
- signer;
- approval event;
- replacement relationship;
- revocation relationship.

The verification service must never trust only the PDF contents. It verifies against the authoritative document registry.

---

# PART XII — DESIGN ACCEPTANCE CHECKLIST

## Layout
- [ ] Correct page size
- [ ] Safe margins
- [ ] Clear hierarchy
- [ ] Legible typography
- [ ] No overcrowding
- [ ] Long-name handling tested
- [ ] Multi-page behavior tested

## Business
- [ ] Required fields present
- [ ] Correct lifecycle stage
- [ ] Correct issuer
- [ ] Correct recipient
- [ ] Correct product mapping

## Security
- [ ] Correct security tier
- [ ] QR valid
- [ ] Numbering valid
- [ ] Hash generated
- [ ] Watermark correct
- [ ] Seal correct
- [ ] Status overlays tested
- [ ] Revocation works
- [ ] Replacement works

## Print
- [ ] 300 DPI assets
- [ ] Vector security lines
- [ ] Grayscale readability
- [ ] Printer margin test
- [ ] UV/hologram controls only where physically implemented

## Digital
- [ ] PDF stable
- [ ] Mobile readable
- [ ] Verification link functional
- [ ] Accessibility considered
- [ ] Search/select text preserved where appropriate

---

# PART XIII — LOCKED DESIGN PRINCIPLES

1. **Exactly 220 canonical document types form the v1 registry.**
2. **Every document is generated from an approved master shell or family shell.**
3. **Security is risk-based: S1–S5.**
4. **QR + backend status is the primary digital authenticity mechanism.**
5. **UV and holograms apply only to genuinely controlled physical outputs.**
6. **Issued documents are immutable; corrections create replacements.**
7. **Revoked documents remain verifiable as revoked.**
8. **Security artwork is reusable institutional design infrastructure, not decorative imagery.**
9. **Every security asset must have its own master file and identifier.**
10. **No document template may invent security features outside this specification without a controlled change request.**

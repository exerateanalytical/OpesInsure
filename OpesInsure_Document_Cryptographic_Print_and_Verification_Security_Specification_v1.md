# OpesInsure Document Cryptographic, Print & Verification Security Specification v1.0

## Purpose

This specification closes the remaining gaps in OpesInsure document security.

It governs:

- cryptographic signing;
- PKI;
- key custody;
- PDF integrity;
- timestamping;
- canonical hashing;
- verification tokens;
- QR anti-cloning controls;
- offline verification;
- secure digital delivery;
- physical print security;
- UV/hologram controls;
- forensic features;
- chain of custody;
- immutable storage;
- monitoring;
- compromise response;
- vendor security;
- environment separation;
- template supply-chain security;
- security testing.

This specification applies to all 220 canonical document types and supplements the existing S1–S5 security matrix.

---

# 1. SECURITY OBJECTIVE

Every important OpesInsure document must be able to answer:

1. Who issued it?
2. Was the issuer authorized?
3. Has the document changed after issuance?
4. Was it valid at the stated time?
5. Is it still valid now?
6. Has it been revoked, replaced, cancelled, expired, or superseded?
7. Does the visible content match the authoritative platform record?
8. Is the verification response privacy-safe?
9. If physical, is the stock/hologram/serial authentic?
10. Can the entire issuance history be reconstructed during an audit or dispute?

---

# 2. CRYPTOGRAPHIC TRUST MODEL

## 2.1 Assurance Levels

### C1 — Registry Integrity
For S1/S2 where digital signature is unnecessary.

Controls:
- SHA-256 hash;
- authoritative registry;
- immutable issuance record;
- QR/token verification.

### C2 — Organizational Digital Signature
For S3.

Controls:
- C1;
- organization signing certificate;
- PDF digital signature;
- trusted timestamp where configured.

### C3 — High-Assurance Signature
For S4.

Controls:
- C2;
- certificate-based signing;
- protected signing key;
- trusted timestamp;
- maker-checker approval;
- revocation information;
- long-term validation information.

### C4 — High-Assurance + Controlled Physical Original
For S5.

Controls:
- C3;
- secure print stock;
- UV/hologram/serial controls;
- physical chain of custody.

---

# 3. PDF SIGNATURE PROFILE

## 3.1 Preferred Standard

For signed PDFs, OpesInsure should use a PAdES-compatible digital-signature profile.

Recommended levels:

- routine signed documents: PAdES Baseline B/T equivalent;
- high-value documents: long-term validation profile comparable to PAdES LT;
- long-term archival/legal evidence: profile comparable to PAdES LTA when operationally justified.

Do not use decorative signature images as the sole authenticity mechanism for high-trust documents.

## 3.2 PDF/A

Where long-term archival is required, use a compatible PDF/A profile.

Recommended:
- PDF/A-2 or later compatible implementation where digital signature requirements allow;
- preserve embedded fonts;
- preserve output intent/color profile;
- prohibit dependencies on external resources;
- validate produced PDFs before archival.

## 3.3 Digital Signature Validation

Validation must check:

- PDF byte integrity;
- signing certificate validity;
- chain to trusted CA where applicable;
- signing time;
- trusted timestamp;
- revocation status;
- document modification after signing;
- signature coverage of the intended byte range.

---

# 4. CANONICAL DOCUMENT FINALIZATION SEQUENCE

The implementation order is locked:

1. Load approved template version.
2. Resolve canonical source entities.
3. Freeze issuance snapshot.
4. Render deterministic document content.
5. Validate required fields.
6. Apply visible security graphics.
7. Generate document number.
8. Generate verification token.
9. Generate QR.
10. Render unsigned final PDF.
11. Canonicalize/finalize PDF representation.
12. Calculate SHA-256.
13. Digitally sign where required.
14. Apply trusted timestamp where required.
15. Embed validation material where required.
16. Recalculate/store final-file checksum separately if signing changes bytes.
17. Persist document registry entry.
18. Persist signer/approval metadata.
19. Persist audit event.
20. Store immutable object.
21. Mark document `ISSUED`.
22. Make verification endpoint active.

Do not alter issued bytes after final signing.

If content changes, generate a new document/version.

---

# 5. HASH MODEL

Store at minimum:

- `content_hash_sha256`
- `final_file_hash_sha256`
- `template_hash`
- `snapshot_hash`
- `signature_container_hash` where useful

Purpose:

### Content Hash
Represents the canonical business content.

### Final File Hash
Represents the exact issued file.

### Template Hash
Detects unexpected template substitution.

### Snapshot Hash
Protects the canonical issuance input data.

A verifier may therefore distinguish:

- modified file;
- altered template;
- altered source snapshot;
- legitimately replaced document.

---

# 6. PKI & CERTIFICATE MANAGEMENT

## 6.1 Certificate Types

Use separate certificates/keys for:

- document signing;
- API/webhook signing;
- TLS;
- internal service signing;
- optional user/signatory signing.

Never reuse TLS private keys as document-signing keys.

## 6.2 Signing Identity

Signing certificate subject should identify the authorized organization/service.

Store:

- certificate serial;
- issuer;
- subject;
- valid from;
- valid until;
- fingerprint;
- key identifier;
- certificate status.

## 6.3 CA Model

Supported trust models:

- trusted external certificate authority;
- enterprise/private PKI for internal documents;
- regulator-approved/trusted provider where legally required.

Trust policy must be configurable by jurisdiction and insurer.

---

# 7. KEY MANAGEMENT

## 7.1 Key Protection

Production signing private keys must not be stored in application source code, `.env` files, database plaintext, or filesystem plaintext.

Preferred protection:

1. HSM;
2. managed cloud KMS/HSM;
3. certified cryptographic module;
4. controlled software keystore only as lower-assurance fallback.

## 7.2 Key Roles

Separate keys by:

- environment;
- organization;
- function;
- risk class where appropriate.

Production key must never be used in:

- development;
- test;
- staging;
- demo.

## 7.3 Key Lifecycle

Must support:

- generation;
- activation;
- rotation;
- suspension;
- revocation;
- destruction;
- archive of public verification data;
- compromise response.

## 7.4 Dual Control

For critical signing-key operations:

- no single administrator should be able to generate/export/replace critical keys without controlled authorization;
- use maker-checker or split control.

## 7.5 Key Rotation

Rotation policy should define:

- maximum usage period;
- scheduled rotation;
- emergency rotation;
- certificate-expiry rotation;
- compromise rotation.

Old public certificates must remain available for validating previously issued documents.

---

# 8. TRUSTED TIMESTAMPING

High-assurance documents should support an RFC 3161-compatible timestamp authority model.

Timestamp should prove that the signature/document existed at a defined trusted time.

Use for:

- policies where enhanced legal evidence is required;
- S4 documents;
- high-value settlements;
- regulatory returns;
- reinsurance confirmations;
- long-term archival signatures.

Store:

- TSA provider;
- timestamp token;
- timestamp serial;
- timestamp time;
- timestamp validation status.

---

# 9. VERIFICATION TOKEN SECURITY

## 9.1 Token Requirements

Public verification tokens must:

- be cryptographically random;
- not contain predictable policy/document IDs;
- have sufficient entropy;
- be non-sequential;
- resist brute-force enumeration.

Recommended:
- at least 128 bits of random entropy.

## 9.2 Separate Public Token

Do not expose:

- internal UUID;
- database ID;
- customer ID;
- raw policy ID.

Use a dedicated verification token.

## 9.3 Rate Limiting

Verification endpoint must implement:

- IP/device rate limiting;
- abnormal-volume detection;
- bot protections where appropriate;
- temporary blocking;
- security logging.

## 9.4 Token Lifetime

For permanent proof documents:
- verification token may remain valid throughout document archival lifetime.

For temporary authorization:
- token may have an expiry matching authorization validity.

Expired tokens should return a meaningful status, not simply 404.

---

# 10. QR ANTI-CLONING STRATEGY

A QR code can always be photographed or copied.

Therefore:

**QR presence is not proof of authenticity.**

The QR only provides a route to the authoritative registry.

Verification must compare displayed/entered document details against authoritative data.

Verification result must show:

- status;
- issuer;
- document number;
- issue date;
- effective period;
- selected masked identifying fields.

If the QR from one document is pasted onto another document, the visible details should fail comparison.

---

# 11. QR CONTENT

QR should encode only:

- HTTPS verification URL;
- random verification token;
- optional compact signed payload for offline verification.

Do not encode full:

- customer identity;
- medical information;
- claims details;
- bank information;
- full policy content.

---

# 12. SHORT-CODE FALLBACK

Every public-verifiable document should also carry a human-readable short verification code.

Example structure:

`Q7K9-X2LM-P8`

Requirements:

- case-insensitive;
- ambiguity-resistant alphabet;
- check digit/checksum;
- rate-limited verification;
- not sequential.

This permits verification when QR fails.

---

# 13. OFFLINE VERIFICATION

## 13.1 Use Cases

Useful for:

- roadside checks;
- inspectors;
- field agents;
- locations with weak connectivity.

## 13.2 Offline Verification Modes

### Mode O1 — Cached Registry
Authorized verifier app stores a signed, time-limited cache of valid documents.

### Mode O2 — Signed QR Payload
QR may include a compact signed payload:

- document type;
- masked document number;
- issuer;
- validity dates;
- risk identifier fragment;
- issuance timestamp;
- payload expiry;
- signature.

The offline verifier validates the digital signature.

### Mode O3 — Provisional Verification
If cache/signature cannot conclusively verify revocation status:

display:

`OFFLINE — SIGNATURE VALID, CURRENT REVOCATION STATUS NOT CONFIRMED`

Never display full `VALID` when current status cannot be checked.

## 13.3 Cache Expiry

Offline cache must have:

- generation timestamp;
- expiry;
- signature;
- issuer.

---

# 14. FIELD-CONSISTENCY TAMPER CHECK

Verification must not merely answer whether a token exists.

It should detect mismatches between:

- token;
- document number;
- policy number;
- insured/risk;
- dates;
- amount where appropriate;
- serial/hologram where appropriate.

This reduces QR transplantation attacks.

---

# 15. SECURE DOWNLOAD

Sensitive document download must support:

- authenticated authorization;
- short-lived signed download URL;
- access logging;
- anti-IDOR protection;
- tenant isolation;
- permission/scope verification.

For highly sensitive documents optionally require:

- recent re-authentication;
- MFA;
- secure viewer instead of direct attachment.

---

# 16. WATERMARK-ON-DOWNLOAD

Optional for sensitive documents.

Dynamic delivery watermark may include:

- recipient name;
- account/user reference;
- download timestamp;
- masked session/reference.

Use for:

- medical documents;
- internal investigation reports;
- regulatory reports;
- high-value settlement documents.

Do not alter the canonical signed original.

Instead:
- preserve canonical original;
- generate a separately tracked viewing/download copy.

---

# 17. EMAIL, SMS & WHATSAPP DELIVERY

## 17.1 Delivery Classification

### Low sensitivity
May send PDF attachment.

### Moderate/high sensitivity
Prefer secure link.

### Medical/regulatory/high financial sensitivity
Use secure authenticated portal link.

## 17.2 Secure Link

Must support:

- expiry;
- recipient binding where practical;
- download limits where configured;
- revocation;
- access logging.

## 17.3 OTP

Optional for sensitive external retrieval:

- send OTP through a separate verified channel;
- short validity;
- rate limiting.

## 17.4 Email Attachment Rule

Never rely on PDF password protection as the primary security mechanism.

---

# 18. MOBILE WALLET / DIGITAL CREDENTIAL ROADMAP

Architecture should permit future support for:

- Apple/Google wallet passes;
- digitally signed insurance cards;
- NFC tags;
- verifiable credentials.

These are optional extensions.

Canonical platform registry remains authoritative.

---

# 19. PRINT ENGINEERING SPECIFICATION

## 19.1 Base Print

Minimum:

- 300 DPI for raster elements;
- vector guilloche;
- vector microtext where possible;
- controlled CMYK color profile;
- print-safe margins;
- registration marks for security print vendor where required.

## 19.2 Guilloche

Recommended engineering parameters must be printer-tested.

Characteristics:

- multiple interwoven curves;
- variable frequency;
- no simple repeating stock pattern;
- different profile by document family;
- vector origin;
- line widths appropriate to actual print process.

Do not lock an unrealistically tiny line width until tested with the production printer.

## 19.3 Microtext

Must be:

- readable under magnification on genuine print;
- degraded when low-quality photocopied/scanned;
- validated against chosen print process.

Do not specify a universal font size without print testing.

## 19.4 Anti-Copy Pattern

Use controlled patterns designed to:

- moiré;
- lose detail;
- reveal copy behavior;
- degrade under resampling.

This is supplementary, not definitive proof.

---

# 20. UV SECURITY

## 20.1 UV Features

Possible:

- UV emblem;
- hidden serial;
- fine-line pattern;
- repeated issuer name;
- security fibers.

## 20.2 Production Validation

The production security profile must define:

- actual ink type;
- excitation/inspection wavelength supported by vendor;
- emitted color;
- location;
- quality threshold.

Do not claim UV security until vendor testing confirms it.

## 20.3 UV Profiles

Examples:

- `UV-MOTOR-01`
- `UV-CERTIFICATE-01`
- `UV-HEALTHCARD-01`

---

# 21. HOLOGRAM / FOIL SECURITY

## 21.1 Types

Supported physical options:

- destructible holographic label;
- tamper-evident foil;
- hot-stamped hologram;
- serialized security foil.

## 21.2 Serial Linkage

Every serialized hologram must link to:

- document UUID;
- document number;
- stock batch;
- hologram serial;
- issue date;
- issuer;
- branch;
- status.

## 21.3 Anti-Cloning

For highest-risk stock consider:

- custom optical design;
- microtext in hologram;
- kinetic effect;
- hidden image;
- unique serial;
- supplier-controlled master.

Do not use generic retail hologram stickers as high-security proof.

---

# 22. FORENSIC PHYSICAL FEATURES

Optional S5 controls:

- covert UV micro-mark;
- microscopic identifier;
- machine-readable hidden serial;
- forensic ink/taggant where commercially justified;
- latent image;
- custom security fibers.

Use only where:
- verification equipment/process exists;
- vendor supports it;
- chain of custody exists.

---

# 23. SECURE PAPER

For S5 paper certificates, secure stock specification may include:

- custom watermark;
- fibers;
- UV fibers;
- chemical sensitivity;
- controlled color;
- pre-printed stock serial.

The exact stock specification must be approved per printer/vendor and insurer.

---

# 24. PHYSICAL CHAIN OF CUSTODY

States:

`ORDERED → RECEIVED → QA_ACCEPTED → WAREHOUSE → ALLOCATED_TO_BRANCH → ISSUED_TO_OFFICER → ASSIGNED_TO_DOCUMENT → DELIVERED → RETURNED/VOID/SPOILED/DESTROYED`

Track:

- stock type;
- serial range;
- quantity;
- custodian;
- location;
- timestamp;
- transfer;
- receiving officer;
- discrepancy.

No uncontrolled blank secure stock.

---

# 25. SPOILED / DAMAGED STOCK

Required workflow:

1. Mark serial `SPOILED`.
2. Capture reason.
3. Capture physical evidence where required.
4. Checker confirms.
5. Secure destruction.
6. Destruction date/officer.
7. Reconcile stock ledger.

Never reuse spoiled serials.

---

# 26. DOCUMENT SCAN / PHOTO VERIFICATION

When a user uploads a scan/photo of an issued certificate:

System may:

- read QR;
- detect document number;
- compare visible key fields;
- compare layout/template generation;
- compare risk/insured/date values;
- identify obvious mismatch.

Result categories:

- `REGISTRY_MATCH`
- `REGISTRY_MATCH_VISUAL_MISMATCH`
- `TOKEN_INVALID`
- `DOCUMENT_REVOKED`
- `DOCUMENT_REPLACED`
- `UNVERIFIABLE`
- `POTENTIAL_TAMPERING`

Automated detection is an indicator, not a legal fraud determination.

---

# 27. DATA MASKING BY VERIFICATION TYPE

## Public Verify

Show only required proof fields.

Example:

- policyholder: `JU*** NS***`
- policy number: masked or partial
- registration number: configurable partial/full depending use case
- document status
- insurer
- validity.

## Authenticated Customer Verify

May show full customer document details.

## Staff Verify

Role/sensitivity dependent.

## Regulator/Auditor Verify

May show enhanced metadata subject to permissions.

---

# 28. RETENTION & LEGAL HOLD

Every document type must map to a configurable retention class.

Fields:

- retention_class;
- retention_start_event;
- minimum_retention_period;
- archive_period;
- delete_after if legally permitted;
- legal_hold;
- regulatory_hold;
- dispute_hold.

Documents subject to legal hold cannot be deleted by normal retention jobs.

Do not hard-code jurisdictional retention years without verification.

---

# 29. IMMUTABLE STORAGE / WORM

For S3–S5 issued originals, use immutable/object-lock capable storage where possible.

Controls:

- versioning;
- retention lock;
- deletion protection;
- independent backup;
- hash verification;
- replication.

Application users must not be able to overwrite issued original objects.

Replacement creates a new object.

---

# 30. DOCUMENT REGISTRY DISASTER RECOVERY

Registry DR must protect:

- document metadata;
- status;
- token;
- hash;
- signatures;
- public certificates;
- revocation data;
- template version;
- snapshot;
- audit history.

Recovery test must verify:

1. restored document file;
2. hash matches;
3. signature validates;
4. QR/token resolves;
5. revocation state preserved.

---

# 31. MASS / BATCH ISSUANCE SECURITY

Use for:

- group certificates;
- fleet certificates;
- bordereaux;
- annual statements;
- bulk renewals.

Each batch requires:

- batch ID;
- batch type;
- document count;
- issuer;
- start/end time;
- template version;
- batch manifest;
- individual document hashes;
- failure count;
- success count;
- approval;
- batch hash.

Partial failure must not silently mark the entire batch successful.

---

# 32. BATCH MANIFEST

Manifest contains:

- batch ID;
- created timestamp;
- issuing organization;
- document IDs;
- document numbers;
- hashes;
- template versions;
- statuses;
- signature profile.

Manifest itself should be hashed and optionally digitally signed.

---

# 33. SECURITY MONITORING

Create document-security alerts for:

- repeated invalid token lookups;
- sequential token probing;
- same certificate verified abnormally often;
- one QR verified from geographically improbable patterns;
- excessive document downloads;
- excessive duplicate/replacement issuance;
- abnormal stock spoilage;
- duplicate hologram serial;
- reused secure-stock serial;
- mass revocations;
- unusual after-hours issuance;
- unauthorized template publication;
- signature-validation failures.

Alerts feed security/fraud case management.

---

# 34. FRAUD INTELLIGENCE LINKAGE

Track:

- suspected counterfeit document number;
- copied QR;
- compromised serial range;
- compromised stock batch;
- suspicious issuer/user;
- suspicious branch;
- stolen hologram range;
- tampered PDF;
- verification abuse.

Statuses:

- `INDICATOR`
- `UNDER_REVIEW`
- `CONFIRMED_COMPROMISE`
- `FALSE_POSITIVE`
- `RESOLVED`

Do not automatically label a customer or person as fraudulent.

---

# 35. COMPROMISE RESPONSE

## 35.1 Signing Key Compromise

Actions:

1. Disable signing key.
2. Revoke certificate.
3. Activate emergency replacement key.
4. identify affected issuance period;
5. validate historical documents;
6. mark affected documents for enhanced verification if necessary;
7. notify required governance parties;
8. document incident;
9. preserve audit evidence.

## 35.2 Verification Token Secret Compromise

- rotate secret/derivation system;
- preserve old validation where safe;
- regenerate tokens only through controlled replacement if necessary;
- monitor abuse.

## 35.3 Stolen Blank Stock

- identify serial range;
- mark `STOLEN/COMPROMISED`;
- block validation;
- notify branches/security;
- increase monitoring.

## 35.4 Stolen Holograms

Same control:
- compromised serial range;
- disable assignment;
- alert verification system.

---

# 36. THIRD-PARTY PRINT VENDOR SECURITY

Vendor onboarding must address:

- confidentiality agreement;
- facility security;
- employee access;
- stock custody;
- waste/spoilage handling;
- master plate/file handling;
- digital asset protection;
- subcontracting restrictions;
- audit rights;
- incident notification;
- transport security;
- destruction certificate;
- returned stock reconciliation.

Security-artwork master files must not be distributed more widely than required.

---

# 37. SECURE TRANSPORT OF PHYSICAL STOCK

Use:

- sealed packaging;
- manifest;
- serial range;
- sender;
- receiver;
- dispatch timestamp;
- delivery timestamp;
- tamper check;
- discrepancy report.

For high-value stock:
- dual-person receipt where appropriate.

---

# 38. TIME INTEGRITY

Use authoritative server time.

Requirements:

- synchronized infrastructure time;
- timezone retained/displayed correctly;
- issuance timestamp in UTC internally;
- local timezone display;
- trusted TSA time for high assurance.

Users must not manually type cryptographic signing timestamps.

---

# 39. ENVIRONMENT MARKING

Non-production documents must be impossible to confuse with production.

## Development
Large:
`DEVELOPMENT — NOT VALID`

## Test/UAT
Large:
`UAT — NOT VALID`

## Demo
Large:
`DEMONSTRATION / DÉMONSTRATION — NOT VALID INSURANCE`

## Sandbox API
Large:
`SANDBOX`

Non-production QR verification must resolve only to non-production verifier.

Production signing keys cannot exist in non-production environments.

---

# 40. TEMPLATE SUPPLY-CHAIN SECURITY

Protect:

- template source;
- logos;
- seals;
- security art;
- scripts;
- fonts;
- rendering libraries;
- QR libraries;
- PDF signing libraries.

Controls:

- version control;
- hashes;
- code review;
- dependency scanning;
- malware scanning;
- maker-checker publication;
- signed release artifacts;
- restricted production template deployment.

No template may be edited directly in production filesystem.

---

# 41. ASSET SECURITY

Security assets have IDs:

- guilloche ID;
- watermark ID;
- seal ID;
- UV profile ID;
- hologram profile ID;
- border ID.

Track:

- asset version;
- owner;
- hash;
- approval;
- effective dates;
- retired status.

---

# 42. BILINGUAL INTEGRITY

English and French output must derive from the same canonical data.

Requirements:

- same document ID;
- same policy/claim/payment state;
- same amounts;
- same dates;
- same coverage limits;
- same document status.

Translation differences must not create different commercial/legal values.

If separate EN/FR PDFs are issued, link them as language variants of the same issuance event.

---

# 43. ACCESS REVOCATION

Document existence does not guarantee continuing user access.

On:

- employment termination;
- role removal;
- broker suspension;
- provider termination;
- customer relationship change;

future authenticated retrieval must re-evaluate access.

Previously downloaded files cannot be remotely erased, which is why sensitive downloads should be controlled and logged.

---

# 44. DOCUMENT VERSION DIFF

Replacement/revised documents should expose authorized comparison:

- changed fields;
- old value;
- new value;
- effective date;
- change reason;
- approving actor.

Use for:

- policy schedule revision;
- endorsement;
- provider tariff change;
- reinsurance treaty amendment;
- regulatory-return amendment.

---

# 45. DIGITAL VERIFICATION API

Recommended endpoint:

`GET /public/verify-document/{token}`

Returns privacy-safe:

- verification_result;
- document_type;
- masked_document_number;
- issuer;
- issue_date;
- valid_from;
- valid_until;
- status;
- masked subject/risk;
- replaced_by if applicable;
- revoked_at if applicable.

Internal endpoint may return richer validation data under authorization.

---

# 46. VERIFICATION RESULT CODES

Use canonical codes:

- `VALID`
- `VALID_OFFLINE_SIGNATURE_ONLY`
- `EXPIRED`
- `CANCELLED`
- `REVOKED`
- `SUPERSEDED`
- `REPLACED`
- `NOT_YET_EFFECTIVE`
- `INVALID_TOKEN`
- `HASH_MISMATCH`
- `SIGNATURE_INVALID`
- `SIGNATURE_CERTIFICATE_REVOKED`
- `STOCK_SERIAL_COMPROMISED`
- `HOLOGRAM_SERIAL_COMPROMISED`
- `POTENTIAL_TAMPERING`
- `DEMO_VALID`
- `UNVERIFIABLE`

Do not return simply “invalid” when a safer and more precise status exists.

---

# 47. SECURITY TEST MATRIX

## Cryptographic Tests

- altered byte invalidates signature;
- wrong certificate rejected;
- expired signer handled correctly;
- revoked signing certificate recognized;
- timestamp validation;
- chain validation;
- old key still validates historical documents;
- compromised key blocks new issuance.

## Hash Tests

- visible field changed → hash mismatch;
- QR replaced → field/token mismatch;
- template substituted → template hash mismatch;
- snapshot altered → snapshot hash mismatch.

## Token Tests

- random invalid token;
- sequential probing;
- brute-force threshold;
- expired token;
- revoked document token;
- copied QR.

## Authorization Tests

- cross-tenant retrieval;
- IDOR;
- unauthorized medical document;
- unauthorized claim document;
- unauthorized finance document;
- former employee access;
- suspended broker access.

## PDF Tests

- content alteration after signature;
- page inserted;
- page removed;
- signature field replaced;
- attachment injected where applicable;
- metadata altered;
- rendering across major PDF viewers;
- archival validation.

## Print Tests

- photocopy degradation;
- scan/resample;
- UV inspection;
- hologram adhesion/tamper behavior;
- microtext magnification;
- guilloche reproduction;
- stock serial match.

## Physical Inventory Tests

- duplicate stock serial;
- spoiled serial reuse attempt;
- missing stock range;
- stolen range;
- duplicate hologram assignment;
- unauthorized branch issuance.

## Lifecycle Tests

- valid → revoked;
- valid → superseded;
- duplicate issuance;
- replacement issuance;
- cancelled policy;
- expired policy;
- payment reversed;
- claim decision replaced;
- provider authorization expired.

## Privacy Tests

- public verification leaks medical data;
- public verification leaks full customer identity;
- public verification leaks payment details;
- logs leak tokens/secrets;
- QR exposes sensitive raw data.

## Batch Tests

- partial batch failure;
- duplicate document in batch;
- batch manifest mismatch;
- batch hash mismatch;
- retry safety.

## Recovery Tests

- restore registry;
- restore files;
- validate hash;
- validate signature;
- verification portal after DR;
- revoked status preserved.

---

# 48. PENETRATION / ADVERSARIAL DOCUMENT TESTS

Red-team scenarios should include:

1. Copy genuine QR to forged PDF.
2. Change premium amount but keep QR.
3. Change insured name.
4. Change expiry date.
5. Reuse genuine motor attestation serial.
6. Forge revoked document.
7. Clone hologram number.
8. Guess verification tokens.
9. Access another tenant’s document.
10. Re-issue a certificate without authority.
11. Modify template in production.
12. Steal signing credential.
13. Submit fake stock serial.
14. Abuse bulk-download endpoint.
15. Upload altered scanned certificate.

Expected behavior must be documented for each scenario.

---

# 49. DOCUMENT SECURITY OPERATIONS DASHBOARD

Monitor:

- documents issued;
- documents revoked;
- replacements;
- duplicates;
- signature failures;
- verification failures;
- token attacks;
- public verification volume;
- compromised stock;
- hologram anomalies;
- stock balances;
- after-hours issuance;
- top issuing users/branches;
- security incidents.

---

# 50. SECURITY GOVERNANCE ROLES

At minimum:

- Document Product Owner
- Compliance Owner
- Information Security Owner
- Template Design Owner
- PKI/Key Custodian
- Document Operations Manager
- Secure Stock Custodian
- Finance/Claims Approver where relevant
- Internal Audit Reviewer

No single role should have unrestricted control over:

- template creation;
- approval;
- signing keys;
- secure stock;
- issuance;
- revocation.

---

# 51. PRODUCTION ACCEPTANCE GATE

No S3–S5 document can go live until:

- template approved;
- field completeness passed;
- hash logic tested;
- QR/token tested;
- verifier tested;
- signature tested;
- certificate/key custody tested;
- revocation tested;
- replacement tested;
- access-control tested;
- retention configured;
- DR tested;
- public privacy review complete;
- print-security QA complete if physical;
- stock/hologram workflow complete if applicable.

---

# 52. CLAUDE / ENGINEERING INSTRUCTION

> Do not implement document security as decorative UI. Security must be cryptographic, operational, verifiable, auditable, and lifecycle-aware. QR codes, watermarks, seals, holograms, UV graphics, digital signatures and secure stock each solve different problems and must never be treated as interchangeable. A copied QR code must not authenticate a forged document; verification must compare against the authoritative registry. High-assurance PDFs must be cryptographically signed using protected keys and trusted timestamps where required. Private keys must never be stored in source code, plaintext configuration, or ordinary application storage. Issued originals are immutable. Replacements create new documents. Revoked documents remain verifiable as revoked. Physical security features count only when actual secure printing, serial inventory, custody, spoilage, and destruction controls exist. Public verification must never leak medical, financial, KYC, beneficiary, or confidential claims information. If implementation convenience conflicts with the security specification, the security specification wins.

---

# 53. LOCKED SECURITY PRINCIPLES

1. Registry state is authoritative.
2. QR is a pointer, not proof.
3. Hash protects integrity.
4. Digital signature proves signing identity/integrity.
5. Trusted timestamp strengthens time evidence.
6. Signing keys require protected custody.
7. Issued originals are immutable.
8. Revocation never deletes history.
9. Public verification is privacy-minimized.
10. Sensitive downloads are access-controlled.
11. Offline verification must disclose stale revocation status.
12. Physical security requires physical operational controls.
13. Secure stock and holograms are inventory assets.
14. Non-production documents are unmistakably non-valid.
15. Bilingual outputs share the same canonical issuance data.
16. Batch issuance has manifests and hashes.
17. Compromise response is predefined.
18. Templates/security assets are controlled supply-chain artifacts.
19. Security monitoring feeds case/fraud review.
20. S3–S5 go-live requires security acceptance testing.

# Document Spec Gap Audit — Canonical Implementation Specification v1

Scope: `document_system`, `document_implementation_policy` and `cryptographic_print_verification_security` in
`OpesInsure_Canonical_Implementation_Specification_v1.json`, checked against the existing document catalogue
(`app/Application/DocumentCatalogue`, `document_types` with 364 types) and the document engine
(`app/Application/Documents/Engine`). Agent cs1, 2026-09-25.

Status legend: **EXISTS** (was already there) · **BUILT** (added in this change) · **PARTIAL** · **MISSING** ·
**CONFIG_REQUIRED** (needs owner-provided secrets, contracts or hardware) · **PENDING_VERIFICATION** (the JSON is
ambiguous; the owner needs to decide).

## 0. Binding inputs and conflicts found

| Item | Finding | Status |
|---|---|---|
| Manifest critical invariants (6) | Seeded as `document_spec_dictionary` kind `INVARIANT`. Enforced as follows: stable IDs (spec_id key); immutable snapshots (DB trigger); no security downgrade (DB trigger and seeder rule); QR is not proof (the registry decides); no invented data (placeholders); backend modules not rewritten (the engine was extended). | BUILT |
| **ID reconciliation** | The catalogue's `DOC-###` numbering (from the owner register `database/data/document_register_220_2026.json`) is **not the spec's numbering**: 214 of 220 IDs name a different document (for example spec DOC-036 is the Motor Attestation, but catalogue DOC-036 is the Premium Schedule). Neither numbering was changed, so both stay stable. The spec records live under their own `spec_id`, and each catalogue type points to its spec record through `document_types.canonical_spec_id`. | **PENDING_VERIFICATION: owner must choose which numbering is canonical for external use** |
| Mapping | Records are matched on exact normalized EN/FR names only, with no fuzzy guessing. 203 of 220 mapped (206 catalogue types). **17 spec records have no catalogue type:** DOC-014, 015, 138, 139, 140, 169, 179, 185, 196, 199, 200, 209, 213, 214, 218, 219, 220. Some are near-synonyms (for example "Offer Acceptance", "Discharge", "KYC Approval") and a few have no catalogue type at all (the travel certificates). | PENDING_VERIFICATION |
| `field_group_refs` | Only DOC-001 and DOC-016 have explicit refs. The conversion also dropped DOC-016's ranges ("FG-01–07, FG-12–15"), which we checked against the Markdown field specification and re-expanded from the JSON's own text. For the other 218 records the groups are derived from prose keywords (`DERIVED_KEYWORD`); these are recorded but **not enforced**. | PARTIAL / PENDING_VERIFICATION |
| Security matrix Markdown §4–§11 | Physical profiles PS-01..04, watermark profiles, seal profiles, public-verification rules and the issuance gate are in the Markdown but **missing from the JSON**. They were used only as consistent reference: the watermark profiles, SEAL-02 and the public disclosure rules. | PENDING_VERIFICATION (conversion omission) |
| Dual tiers (`S1/S2`, `S2/S3`, `S3/S5`, `S4/S5`) | Read as floor and ceiling, following the shell text ("S1 by default, S2 when insurer wants" and "S4 minimum, S5 for controlled physical"). The floor is never lowered. | BUILT (interpretation noted) |
| Dual confidentiality (`PUBLIC_VERIFY/CUSTOMER_PRIVATE`) | Both classes are kept. The stored class is the most restrictive one. | BUILT |
| Catalogue vs spec confidentiality | The stored `security_level` only ever moves to a **more** restrictive value. Where the spec is less restrictive, the catalogue value is kept, and the seeder reports these cases (`less_restrictive_spec_levels_kept`). | BUILT |

## 1. Per-document records (220)

| Element | Before | Now |
|---|---|---|
| 220 records with names, category and typical tier | Partial: the catalogue used a different numbering | BUILT: `document_canonical_specs` (220 rows, stable `spec_id`, idempotent upsert, protected from deletion) |
| Security profile per document (19 columns) | MISSING: only `security_level` existed | BUILT: raw profile plus normalized controls on the spec row and on each mapped `document_types` row (`security_tier`, `security_tier_ceiling`, `security_controls`, `confidentiality_class`, `access_profiles`, `master_shell_code`) |
| Field requirements per document | MISSING | BUILT: groups with a derivation method, and minimum additions stored |
| Detailed field specs (36 critical docs, 684 bullets) | MISSING | PARTIAL: every bullet is classified. 180 are ENFORCED (mapped to a canonical key and unconditional), 28 are security controls, 31 are NO_CANONICAL_SOURCE (territory, branch, broker/agent, clauses, payment schedule, allocation…), 3 are CONDITIONAL, and **442 are UNMAPPED (PENDING_VERIFICATION)** because no canonical data model exists yet (quote assumptions, claim reserves, reinsurance terms, provider data…). |

## 2. Field groups FG-01..FG-15

All 15 groups are seeded (`document_spec_dictionary` kind `FIELD_GROUP`) with their enforced minimum keys
(`CanonicalFieldDictionary::GROUP_KEYS`). Values are resolved only from canonical entities: party, policy,
carrier, product, quote risk facts or subject, offer, payment, claim and policy transaction.

| Group | Enforced minimum | Gap |
|---|---|---|
| FG-01 Identity | number, type, title, template version, issue time, tier | page X/Y rendered, not validated |
| FG-02 Issuer | legal name | registered address, contact and regulatory ID have no verified source (PENDING_VERIFICATION placeholder) |
| FG-03 Party | party name | DOB and ID are privacy-gated and not printed |
| FG-04 Policy | number, insurer, effective from/until, currency | territory, branch, renewal basis: no source |
| FG-05 Risk | risk summary; the registration number for vehicle subjects | property, marine and health subgroups are shown only from the facts that exist |
| FG-06 Coverage | coverage lines | sublimits, waiting periods, exclusion refs: no source |
| FG-07 Premium | gross premium, currency; taxes where the detailed spec asks | loadings and discounts: no source |
| FG-08 Payment | reference, amount and date of a SUCCEEDED payment | allocation and balance: no source |
| FG-09 Claim | claim number | reserve, assessment and decision reason: no engine source |
| FG-10 / FG-11 | provider / treaty keys defined | **MISSING: no engine trigger issues provider or reinsurance documents** |
| FG-12 | treated as controls (signature and maker-checker status) | named signatory is only the issuance-profile signatory |
| FG-13 | code, token | EXISTS/BUILT |
| FG-14 | links by the status service (supersedes, superseded_by, replaced) | EXISTS |
| FG-15 | template reference, confidentiality | registered office and complaints contact are CONFIG_REQUIRED |

**Enforcement.** A required key whose source exists but is empty blocks that pack item before a number is
allocated (`BLOCKED_MISSING_FIELDS`, with the reason and the list of missing keys). Nothing is rendered blank.
Keys with no canonical source print as a marked `PENDING_VERIFICATION` placeholder, as policy §1.1 allows.
Setting `DOCUMENT_FIELD_ENFORCEMENT=record` switches to record-only mode.

## 3. Security tiers S1–S5 and controls

| Control (legend) | Status |
|---|---|
| Canonical and controlled numbering | EXISTS (gap-free allocator). A number is consumed only after validation passes. |
| QR to the authoritative verifier | BUILT. The QR carries only the verifier URL and a random token of at least 128 bits (SHA-256 stored); no identity. |
| Short verification code | BUILT. Checksummed, case-insensitive, ambiguity-free alphabet, printed as `OVXX-XXXX-XXXX`. Old codes still verify. |
| SHA-256 hash | EXISTS (file) and BUILT: `content_hash_sha256`, `snapshot_hash`, template hash. A hash fragment is printed in Zone F. |
| Watermark (light, dynamic, status) | BUILT. The watermark follows the confidentiality profile (public proof, private, finance, medical, claims) and includes a status word for WM-STATUS docs. |
| Guilloche / fine line | BUILT. Vector header wave and rosette, seeded per family and number. Print validation is CONFIG_REQUIRED. |
| Microtext | BUILT (header and footer strips). Size must be validated on the printer (CONFIG_REQUIRED). |
| Anti-copy | BUILT (fine-line field behind the verification block). Supplementary only. |
| Authentication seal | BUILT: SEAL-02, generated and bound to the registry record. **Corporate seal artwork: CONFIG_REQUIRED (none fabricated).** |
| Signature / approval block | BUILT: Zone E shows issuer, role, authorization ref, signatory, digital signature status and UTC timestamp. |
| Cryptographic signature | BUILT: Ed25519 detached signature over file, content, snapshot and template hashes, with the key from env or an external file (`DOCUMENT_SIGNING_KEY[_PATH]`, `_KEY_ID`, `_KEY_ENV`). A key bound to another environment is refused. Retired public keys are published at `GET /api/v1/public/document-signing-keys`. **No key is provisioned, so the control is CONFIG_REQUIRED.** |
| PAdES-embedded signature, X.509 chain, HSM/KMS | MISSING / CONFIG_REQUIRED (needs a PAdES library and a CA or HSM) |
| RFC 3161 trusted timestamp (S4) | CONFIG_REQUIRED (`DOCUMENT_TSA_URL`, client not built) |
| Maker-checker (S4) | PARTIAL. Recorded as APPLIED when the source transaction has an approver who is not the requester. Otherwise CONFIG_REQUIRED, because POLICY_ISSUED carries no maker-checker evidence. |
| Revocation, replacement and status verification | EXISTS (maker-checker `DocumentStatusService`). Verification returns the canonical codes. |
| UV, hologram, secure stock (S5) | **CONFIG_REQUIRED.** No secure-print operation, stock inventory, custody or spoilage workflow exists (crypto §20–25, §36–37 MISSING). |
| Audit | EXISTS; generation and blocked events are audited |
| Confidentiality and access | BUILT: the class and A1–A5 profiles are frozen per document. `DocumentAccessPolicy` also denies customers when a document has no A1/A2 profile, and A5-only documents require `documents.regulatory.read`. |
| Security acceptance gate (§51) | PARTIAL. `DOCUMENT_ENFORCE_CONTROLS=true` blocks S3+ issuance while signature or maker-checker are CONFIG_REQUIRED. It is **off** until the owner provisions the key. |

## 4. A4 zones and master shells

| Element | Status |
|---|---|
| Zones A–G (header band, identity, party/risk, content, authorization, verification, footer with page X/Y) | BUILT: `resources/views/pdf/engine-shell.blade.php` with view model `DocumentShellView`. Multi-page is supported through fixed header and footer. |
| TPL-SHELL-QUOTE / POLICY-SCHEDULE / POLICY-CERTIFICATE / MOTOR-ATTESTATION / PREMIUM-RECEIPT | BUILT as compositions of the zoned layout (hero premium or amount panel, certificate statement, prominent vehicle block, notices). Shell codes are seeded and linked to types. |
| Logos and icon system (SVG masters 16–32 px) | MISSING / CONFIG_REQUIRED (no approved artwork) |
| Quotes (`QuoteDocumentRenderer`) and legacy `CertificateService` / `PolicyDocumentService` PDFs | **Not moved onto the shell** (outside this territory). They keep `pdf.engine-document` and the older views. |

## 5. Crypto, print and verification sections (§1–§53)

| § | Status |
|---|---|
| 4 finalization sequence | BUILT in order: validate → number → token → snapshot → content hash → render → file hash → sign → persist → audit |
| 5 hash model | BUILT (content, file, template, snapshot) |
| 9 token security | BUILT (128-bit random, hash only, rate limit 20/min, lookups logged). Abnormal-volume alerts (§33) MISSING. |
| 10/14 anti-cloning and field consistency | BUILT (`?document_number=` mismatch returns POTENTIAL_TAMPERING; file, snapshot and signature are re-checked on each lookup) |
| 11 QR content | BUILT |
| 12 short code | BUILT |
| 13 offline verification | PARTIAL (public keys endpoint only; no signed QR payload or cache) |
| 15/16 secure download, watermark-on-download | MISSING (existing authenticated download only) |
| 27 masking | BUILT (PUBLIC_PROOF / MINIMAL / RESTRICTED disclosure) |
| 28 retention and legal hold | MISSING |
| 29 WORM | PARTIAL (DB immutability and no-delete triggers; object-lock storage is CONFIG_REQUIRED) |
| 31/32 batch manifest | PARTIAL (pack manifests exist, but no batch hash or signature) |
| 39 environment marking | BUILT (demo overlay exists; DEVELOPMENT, UAT and SANDBOX marks added; DEMO_VALID result) |
| 42 bilingual integrity | EXISTS (one render from one snapshot) |
| 44 version diff | PARTIAL (endorsement changes printed; no diff API) |
| 45/46 verification API and codes | BUILT (`GET /api/v1/public/verify-document/{token}`; legacy `result` kept) |
| 20–26, 33–37, 41, 47–50 (physical, monitoring, fraud, compromise, vendor, asset registry, dashboards, roles) | MISSING or CONFIG_REQUIRED |

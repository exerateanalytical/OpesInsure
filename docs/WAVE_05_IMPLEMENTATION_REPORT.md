# Wave 5 Implementation Report

Date: 2026-09-21  
Scope: issuance, certificates/stickers, policy servicing, renewals, cancellation and reinstatement.

## Delivered

- Policy issuance requests require an approved proposal, matching successful reconciled payment, immutable terms hash and carrier review or valid delegated authority.
- Carrier authorization is maker–checker separated; activation records carrier reference, authority evidence and append-only issuance/status events.
- Certificate templates are versioned, hashed and independently approved. Certificates use high-entropy verification tokens stored only as SHA-256 hashes.
- Public verification returns privacy-minimised policy validity data and records hashed request fingerprints.
- Physical stickers are received by carrier batch, assigned from locked available stock and tracked through custody events.
- Endorsement, cancellation and reinstatement requests use guarded policy states, immutable before/after terms, transaction-bound exact-amount payments, rejection recovery and independent approval.
- Renewal work queues create new quotes from current risk facts while preserving the permanent customer attribution.
- Cancellation refunds use approved, non-overlapping effective-dated pro-rata or short-rate configuration and create a separate maker–checker refund request; no statutory rate is invented.
- Filament policy-operation workspaces, secured APIs, tenant policies, audit/outbox, EN/FR and automated specifications are included.
- Filament web actions cover proposal issuance requests, carrier decisions, policy servicing, adjustment payments, certificate issuance/voiding, sticker batch intake, renewals and cancellation-rule governance.
- Final hardening corrected legacy Filament 4 section components and two mismatched resource page names discovered by the Wave 5 navigation scan.

Runtime acceptance remains provisional until PostgreSQL migrations, Laravel tests, certificate rendering/QR tests, browser tests and carrier UAT execute.

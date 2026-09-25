# Release handover: Batch 8 (servicing and documents), for the local deploy session

**Branch:** `origin/claude/charming-bohr-2fd2hk`. Deploy it **after** the Batch 7 release `acedff7` (docs/RELEASE_HANDOVER_BATCH7.md). You can also deploy
both together: the steps are the same, and all migrations run in date order.
**Tests (cloud):** the full pest suite passes, 1228 passed / 0 failed.

## Steps
Same chain as Batch 7: merge into master → local full pest → backup → rehearse migrations on a prod copy → dump-autoload in the snapshot → deploy.sh →
verify-live → record the release id in SESSION_HANDOFF.md.
After deploy:
- Re-run the role/permission seeder. **Existing tenants' non-carrier roles use firstOrCreate, so they do NOT get new grants automatically**:
  update those roles explicitly (see §3).
- Make sure the scheduler is running. New scheduled jobs: `policies:scan-issuance-queue` (hourly), `policies:premium-cover-sweep` (daily 00:45 Africa/Douala).
- Schedule the renewal sweep daily (`POST renewals/seed` per tenant, or RenewalService::sweep). There is no command for it yet.
- Optional: `ALTER TABLE renewal_cases VALIDATE CONSTRAINT renewal_case_status_allowed;` once the data is confirmed clean.

## 1. Migrations (additive)
| Migration | What |
|---|---|
| 2026_10_13_810001 | endorsement_type_rules (7 platform defaults) + 9 nullable policy_transactions columns + unique (tenant_id, idempotency_key) |
| 2026_10_13_820001 | policy_cancellations (request → review → approve case) |
| 2026_10_13_830001 | policy_suspensions; policy_versions kind check + SUSPENSION; case type POLICY_REINSTATEMENT |
| 2026_10_13_840001 | renewal machine (renewal_case_events, windows, status CHECK NOT VALID); issuance_exceptions.renewal_case_id |
| 2026_10_13_850001 | premium_cover_rules.lapse_after_days, policy_premium_instalments, policy_recovery_cases |
| 2026_10_13_860001 | special products: profiles, schedule items, cargo declarations, life surrender scales/quotes |
| 2026_10_13_870001 | policy portfolio transfers, servicing events, portability exports; policies.servicing_partner_id/servicing_carrier_id |
| 2026_10_13_890001 | document governance: retention_schedules, legal_holds, destruction requests, intake items, signature requests; documents.destroyed_at/by; case types DOCUMENT_DESTRUCTION |

## 2. Behaviour changes
| Area | Change |
|---|---|
| Endorsements | Typed rules (backdating limit, rerate / manual premium, ENDORSE authority when configured); each approval writes an ENDORSEMENT policy version; a negative delta raises a refund |
| Service requests | mobile/policy-service-requests is an alias of policies/{id}/service-requests (one intake, idempotent) |
| Cancellation | request → review → approve with maker-checker; insurer-initiated cancellation: 10-day notice, pro-rata, no fee; approval revokes documents and voids legacy certificates |
| Suspension | suspend / reinstate queue with maker-checker (POLICY_REINSTATEMENT case) |
| Premium default | daily sweep: GRACE → DEFAULTED + suspension → LAPSED per premium_cover_rules. It acts only on instalment rows, and nothing creates those yet, so there is no live effect until the instalment generator exists |
| Renewals | windows 90/60/30/15/7; auto-lapse of uncontacted cases; **a renewal quote now returns 422 if no active product/tariff can price it** (it used to create an unpriced quote); broker/renewals/seed is an alias |
| Public verification | one service; new POST public/verify; every answer has status VALID/EXPIRED/REVOKED/REPLACED/NOT_FOUND (+NOT_YET_ACTIVE) |
| Documents | security-level access check and access log on reads; destruction only after an approved retention schedule (none seeded, so nothing can be destroyed yet) |
| Roles | Batch 7 permissions granted (reinsurance, coinsurance, providers, issuance queue, stickers); carrier staff limited to their own carrier's stickers |

## 3. Permissions still to grant (new in Batch 8, not yet in RoleCatalogue)
special_policies.view|manage|schedule.manage, cargo_declarations.declare|cancel, life_surrender.scales.manage|approve, life_surrender.quote,
policies.portfolio_transfer.request|approve|read, policies.portability.export,
documents.intake.manage|access_log.read|retention.manage|retention.approve|legal_hold.manage|destruction.request|destruction.approve|signatures.manage.
Not routed yet (service only): cancellation, suspension/reinstatement, premium recovery. Those APIs come in the next wiring pass.

## 4. Rollback
DB backup + previous tarball. All migrations are additive.

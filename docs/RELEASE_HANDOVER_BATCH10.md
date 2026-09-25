# Release handover: Batch 10 (commission, settlement, accounting), for the local deploy session

**Branch:** `origin/claude/charming-bohr-2fd2hk`. Deploy after the Batch 7/8/9 handovers, or all together (migrations run in date order).
**Tests (cloud):** the full pest suite passes, 1348 passed / 0 failed.

## Steps
Standard chain (merge → local pest → backup → rehearse migrations → dump-autoload in the snapshot → deploy.sh → verify-live → record the release id), then:
1. `php artisan rbac:sync-role-permissions --dry-run`, then run it for real (Batch 9 finance permissions are now in the role catalogue).
2. Optional: `php artisan db:seed --class=LedgerChartOfAccountsSeeder` (platform XAF chart). Tenant charts are otherwise created on first posting.
3. New scheduled jobs (the scheduler must be running): `ledger:open-periods` daily 00:05, `commissions:advance` daily 02:10 (Africa/Douala).
4. Accounting periods are opt-in per tenant: nothing is period-controlled until a period is configured.

## Migrations (additive)
| Migration | What |
|---|---|
| 2026_10_15_101001 | commission_accruals lifecycle columns (earned/approved/payable/paid/disputed…) |
| 2026_10_15_102001 | commission_rule_versions scope (agreement, line, transaction type, method); commission_rule_tiers; commission_rule_splits |
| 2026_10_15_103001 | partner statements: adjustments, revision, obligation, dispute; payout journal_id; case type COMMISSION_DISPUTE |
| 2026_10_15_104001 | settlement_batches ledger lifecycle (calculation_basis POLICY/OBLIGATIONS…); settlement_items obligation + collection mode |
| 2026_10_15_105001 | bordereau_items source_type/source_id/amount (backfilled); new unique key; bordereaux.total_amount_minor |
| 2026_10_15_106001 | accounting_events + accounting_event_mappings (platform defaults for 18 events) |
| 2026_10_15_107001 | manual journal lifecycle columns; **balanced-journal triggers** (checked at commit); maker-checker constraints |
| 2026_10_15_108001 | accounting_period_configs, accounting_periods; journals.accounting_date |
| 2026_10_15_109001 | technical accounting: actuarial imports/values, UPR postings |

## Behaviour changes to watch
| Area | Change |
|---|---|
| Ledger | Payments (webhook), refund payout, cashier collection, clearing settlement, commission accrued/earned/clawed back/paid, settlement approved/settled now **write POSTED journals** through the default OHADA-style mapping (a tenant posting profile still overrides). **Any unbalanced posted journal now fails at commit** (DB trigger). |
| Commission | Issuing a policy auto-accrues commission (attributed partner + approved rule); settled premium earns it; endorsements adjust it; cancellations claw back pro-rata. Paid commission clawed back becomes a receivable from the partner. Payout completion marks accruals PAID. **One payable per approved partner statement** (not per accrual). |
| Commission rules | Approval fails if the intermediary splits don't total 100% or tiered rules lack tiers; automatic accrual picks the most specific rule (agreement → partner → product → line → transaction type). |
| Statements | Approval blocked while adjustments are pending; disputes open a case and cancel the unpaid payable. |
| Settlement | New OBLIGATIONS-based broker→insurer settlement (`broker-settlements/*`); `GET settlements/{batch}` is canonical, carrier-/broker-settlements read routes are aliases. The old weekly POLICY-basis job still runs (see open question). |
| Bordereaux | One service for all three routes; the preparer can no longer submit on any route; ENDORSEMENT/CANCELLATION/CLAIM/COMMISSION bordereaux now contain their own items. Reinsurance bordereau class renamed TreatyBordereauService. |
| Journals | Manual journals: draft → validate → approve (different user) → post → reverse. The old `POST ledger/journals` still posts immediately (see open question). |
| Periods | Postings dated in a CLOSED period roll to the next open period; reopen needs privileged maker-checker. |
| Roles | Batch 9 finance permissions granted (FINANCE_OFFICER maker, CARRIER_SUPER_ADMIN checker, BRANCH_MANAGER cashier); finance modules added to business-data (platform-only roles no longer see them). |

## Permissions still to grant (new in Batch 10, not yet in RoleCatalogue)
ledger.periods.close, ledger.periods.reopen, ledger.approve, ledger.post, technical_accounting.read|actuarial.import|actuarial.approve|upr.post,
commission.statements.adjust|adjustments.approve|dispute|dispute.resolve, settlement.reconcile, bordereaux.view. Wildcard roles already have them.

## Open owner decisions
Retire `POST ledger/journals` (bypasses maker-checker)? Retire the weekly POLICY-basis `settlements:prepare` (risk of remitting the same premium twice)?
Add a CASHIER role? Confirm the 20 finance report names (FR-01…FR-20)? Reversing a commission payout leaves accruals PAID (needs a reopen transition)?

## Rollback
DB backup + previous tarball. Migrations are additive, but the balanced-journal triggers stay active until rolled back
(`php artisan migrate:rollback --step` for 107001 drops them).

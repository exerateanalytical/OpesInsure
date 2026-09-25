# Release handover: Batch 8 wiring + Batch 9 (money chain), for the local deploy session

**Branch:** `origin/claude/charming-bohr-2fd2hk`. Deploy after the Batch 7 and Batch 8 handovers, or all together (migrations run in date order).
**Tests (cloud):** the full pest suite passes, 1288 passed / 0 failed.

## Steps
Standard chain (merge → local pest → backup → rehearse migrations → dump-autoload in the snapshot → deploy.sh → verify-live → record the release id), then:
1. `php artisan rbac:sync-role-permissions --dry-run`, then run it for real (gives existing tenants every new permission; it never removes grants).
2. `php artisan finance:backfill-obligations --dry-run`, then run it for real (one PREMIUM obligation per issued policy, settled if paid).
3. Scheduler must be running. Jobs: `policies:scan-issuance-queue` hourly, `policies:premium-cover-sweep` 00:45, `renewals:sweep` 01:15 (Africa/Douala).
4. New .env keys (only if you enable these providers): `BANK_TRANSFER_WEBHOOK_SECRET`, `BANK_TRANSFER_BANK_NAME`, `BANK_TRANSFER_ACCOUNT_NAME`,
   `BANK_TRANSFER_ACCOUNT_NUMBER`, `BANK_TRANSFER_SWIFT`, `BANK_TRANSFER_VALIDITY_DAYS`; card sandbox: `CARD_SANDBOX_ENABLED`, `CARD_SANDBOX_CHECKOUT_URL`,
   `CARD_SANDBOX_WEBHOOK_SECRET` (leave disabled in production). Each provider also needs an ACTIVE payment_provider_connections row.

## Migrations (additive)
| Migration | What |
|---|---|
| 2026_10_14_910001 | financial_obligations + events; financial_obligation_id on policy_premium_instalments and payment_intents |
| 2026_10_14_920001 | allocation_rule_versions, premium_components, payment_allocation_runs, payment_allocations (append-only trigger) |
| 2026_10_14_940001 | payment_intents: collection_mode, collection_semantics, max_attempts; payment_attempts retry columns |
| 2026_10_14_950001 | reconciliation outcomes/variance/refund candidate; reconciliation_manual_matches; case type RECONCILIATION_EXCEPTION |
| 2026_10_14_960001 | refunds engine columns; mobile_money_clearing_batches/items |
| 2026_10_14_970001 | fx_rates (+EUR→XAF/XOF 655.957 peg seed), fx_conversions (append-only), cashier_sessions, cashier_collections |

## Behaviour changes
| Area | Change |
|---|---|
| Issuance | Each issued policy creates its amounts-owed (one PREMIUM obligation, or one INSTALMENT obligation + instalment row per instalment). **Instalment plans can now issue on the first instalment amount** (before, the full total was required). |
| Payments | A confirmed webhook payment and an approved reconciliation settle the related obligation. Provider callbacks can only apply provider events (no cancel/refund by callback). |
| Payment initiation | If a carrier profile sets BROKER/INSURER/BANK/EXTERNAL collection, the app no longer prompts mobile money (422). Default stays MOBILE_MONEY. |
| Retry | Mobile retry now goes through the retry service: max 3 attempts, no retry of a paid payment, no double charge on the same obligation |
| Reconciliation | Outcomes MATCHED/PARTIAL/UNDER/OVER/DUPLICATE/UNMATCHED; exceptions open cases; **a manual MATCHED resolution now needs a second approver** (new manual-match endpoints); duplicates become refund requests |
| Refunds | Full lifecycle candidate → calculated → reviewed → approved → paid → reconciled; calculator cannot approve; an approved refund is a PAYABLE obligation; resolving an issuance exception as REFUND_REQUESTED now creates a real refund |
| Suspension/cancellation/recovery | Now available as APIs (see §Routes); premium-default suspensions use the single suspension path; reinstating a LAPSED policy returns 422 pointing to recovery |

## Routes added (all /api/v1, tenant + permission scoped)
Cancellations (`policies/{p}/cancellations`, `policy-cancellations/*`), suspension (`policies/{p}/suspend|reinstate|reinstatement-requests`, `policy-reinstatement-queue`),
recovery (`policy-recovery-cases/*`, `policy-premium-instalments/{i}/settle|waive`), `policy-service-requests`, `finance/obligations/*`, `policies/{p}/instalments`,
`payments/{p}/allocations`, `payment-allocation-runs/{r}/reverse`, `finance/allocation-rule`, `policies/{p}/premium-status|premium-components`,
`payments/{p}/retry|attempts|collection-mode`, `reconciliation/workspace/*`, `reconciliation/items/{i}/manual-matches`, `refunds/*`, `payments/{p}/refund-candidates`,
`clearing/*`, `finance/fx-rates/*`, `finance/cashier-sessions/*`, `finance/statements/*`, `mobile/statements`, bank-transfer and card-sandbox webhooks.

## Permissions still to grant (Batch 9, not yet in RoleCatalogue)
finance.obligations.view|manage, payments.allocations.read|manage|reverse, finance.allocation_rules.manage, premium_status.read, premium_components.manage|close,
refund.view|review|pay|reconcile, clearing.view|manage|reconcile, cashier.sessions.view|operate|approve, fx.rates.view|manage, statements.read.
Until granted, only wildcard admins (FINANCE_MANAGER/FINANCE_ADMIN etc. hold `*`) can use them. After they are added, run the sync command.

## Rollback
DB backup + previous tarball. All migrations are additive.

# Finance Reports V1 (FR-01 .. FR-20)

Status: accepted by the owner (D10, 2026-09-25). Source of truth: `app/Application/Finance/Reports/FinanceReportRegistry.php`.
Requirement: REQ-ACC-005 "20 finance reports" (FRP I; ESR FIN-001..024, FIN-024 Financial Reports).

## Common rules

- API: `GET /api/v1/finance/reports` (catalogue with availability) and `GET /api/v1/finance/reports/{FR-nn}` (JSON, or CSV),
  permission `finance.reports.view`.
- Parameters: `from`, `to` (applied to the report's date column when it has one), `currency`, `as_of` (aging reports).
- Every report is read-only and tenant-scoped. Amounts are integer minor units plus an ISO currency. At most 5,000 rows
  (`MAX_ROWS`); the response flags `truncated`.
- Availability: a report is `AVAILABLE` when all its source tables are installed. Otherwise it is `NOT_AVAILABLE` and gives
  the reason. The platform never estimates figures for a report it cannot compute.

## Reports

| Code | Name | Purpose | FIN screen | Source tables | Availability |
|---|---|---|---|---|---|
| FR-01 | Cash & collections | Successful collections by day, provider and currency (count and amount collected). | FIN-002 | `payment_intents` (status SUCCEEDED) | AVAILABLE |
| FR-02 | Receivables aging | Open receivable obligations bucketed CURRENT / 1-30 / 31-60 / 61-90 / 90+ days per currency, as of a date. | FIN-003 | `financial_obligations` (kind RECEIVABLE) | AVAILABLE |
| FR-03 | Payables aging | Open payable obligations (carrier, commission, claims, refunds) in the same aging buckets. | FIN-004 | `financial_obligations` (kind PAYABLE) | AVAILABLE |
| FR-04 | Premium receivables (open) | Every open PREMIUM / INSTALMENT receivable with its debtor, outstanding amount and due date. | FIN-007 | `financial_obligations` | AVAILABLE |
| FR-05 | Instalment schedule status | Instalments by status and currency: billed, paid and outstanding. | FIN-007 | `policy_premium_instalments` | AVAILABLE |
| FR-06 | Premium components (gross-to-net) | Open premium components (net premium, taxes, fees, commission) totalled by component. | FIN-008 | `premium_components` | AVAILABLE |
| FR-07 | Payment allocations by category | How collected money was allocated, by target category and allocation kind. | FIN-008 | `payment_allocations` | AVAILABLE |
| FR-08 | Reconciliation summary | Reconciliation imports: provider, period, status, matched rows and exception rows. | FIN-005 | `reconciliation_imports` | AVAILABLE |
| FR-09 | Unmatched transactions | Unresolved reconciliation exceptions, with the variance and the linked case. | FIN-022 | `reconciliation_items`, `reconciliation_imports` | AVAILABLE |
| FR-10 | Mobile-money clearing | Clearing batches: expected amount, fees, settled amount, variance and the bank reference. | FIN-021 | `mobile_money_clearing_batches` | AVAILABLE |
| FR-11 | Refund register | Refunds through their lifecycle (review, payment, reconciliation, rejection). | FIN-018 | `refunds` | AVAILABLE |
| FR-12 | Cashier sessions & variances | Cashier sessions with the opening float, expected cash, counted cash, variance and cheques. | FIN-002 | `cashier_sessions` | AVAILABLE |
| FR-13 | Trial balance | Debit, credit and balance per ledger account and currency, from POSTED journals. | FIN-013 | `journals`, `journal_lines`, `ledger_accounts` | AVAILABLE |
| FR-14 | Journal register | Journals with their status, the journal they reverse, and debit/credit totals. | FIN-014 | `journals`, `journal_lines` | AVAILABLE |
| FR-15 | Commission ledger | Commission accruals by partner and status: accrued, vested, paid and clawed back. | FIN-017 | `commission_accruals` | AVAILABLE |
| FR-16 | Carrier settlement batches | Settlement batches (POLICY basis kept for history, and OBLIGATIONS basis) with net amounts and status. | FIN-019 | `settlement_batches` | AVAILABLE |
| FR-17 | Partner (broker / agent) statements | Partner statements: opening balance, earned, clawed back, paid and closing balance. | FIN-010 | `partner_statements` | AVAILABLE |
| FR-18 | Claims paid | Claim payments made (and any reversals), with the claim, policy and payee. | FIN-004 | `claim_payments`, `claims` | AVAILABLE |
| FR-19 | Reinsurance ceded premium | Ceded premium by treaty: gross premium, ceded premium, reinsurance commission and net ceded premium. | FIN-024 | `reinsurance_cessions` | AVAILABLE |
| FR-20 | Technical provisions (UPR / IBNR / outstanding) | Technical provisions from the insurer's actuarial engine. | FIN-024 | `technical_provisions` | NOT_AVAILABLE: provisions are stored or imported from the insurer's actuarial engine (FRP I). No provisions store exists yet, and the platform must not invent actuarial methods. |

"AVAILABLE" means the report is available once its tables are installed. The live answer for each tenant comes from
`GET /api/v1/finance/reports`.

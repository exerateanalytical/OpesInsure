# Finance Counterparty Accounts & Commission Sub-Ledger — gap analysis (Agent F1)

Spec: `docs/spec/canonical/OpesInsure_Finance_Counterparty_Accounts_Commission_Subledger_v1.json` (merged into the canonical
specification under `finance_counterparty_accounts_commission_subledger`). Rule followed: **extend, never duplicate** — every spec
entity is mapped to the Batch 9–10 money chain first; only the gaps were built (namespace `App\Application\Finance\Subledger`,
migration `2026_10_17_140201_create_finance_counterparty_subledger`).

Legend: **EXISTS** = already implemented, reused as-is · **EXTENDED** = existing code extended · **NEW** = gap built by F1 ·
**NOT MODELLED** = no store exists; reported, never faked.

## 1. Principles

| Spec principle | Status | Where |
|---|---|---|
| double_entry_required / journal debits = credits | EXISTS | `App\Domain\Ledger\Journal` (balanced-journal domain rule) used by `LedgerService::post`, `ManualJournalService::validate` |
| financial_truth_from_transactions, no_direct_balance_editing | NEW | balances are Σ `finance_ledger_entries` (projection of posted `journal_lines`); `finance_counterparty_accounts` has no balance column; dashboards are GET-only |
| posted_entries_immutable | EXTENDED | journals were already append-only by service; sub-ledger entries are protected by a DB trigger (UPDATE/DELETE raise) |
| corrections_via_reversal | EXISTS + NEW | `LedgerService::reverse` / `ManualJournalService::reverse`; reversal entries carry `reverses_entry_id` |
| distinct_obligations_not_silently_netted | NEW | mirrored views return premium and commission as separate gross balances; `net_*` flagged informational, never posted |
| source_lineage_required | NEW | entry → journal → source entity (`source_entity_type/id`) → policy → documents (`SubledgerQuery::drilldown`) |
| tenant_isolation_required | EXISTS + NEW | all reads `where tenant_id`; tenant middleware; cross-tenant drill-down 404 |
| idempotent_posting_required | EXISTS + NEW | `FinancialPostingService` (event, reference) idempotency; entries unique on `journal_line_id` + `idempotency_key` |
| maker_checker_for_sensitive_actions | EXISTS + NEW | manual journals, period reopen, payout reversal (existing); counterparty account approval (new) |

## 2. Entities (`database_entities`)

| Spec entity | Status | Implementation |
|---|---|---|
| finance_accounts | EXISTS | `ledger_accounts` + `DefaultChartOfAccounts` (EXTENDED with 10 control accounts: 411100, 412000, 412100, 409100–409300, 445000, 447000, 471100, 581000) |
| finance_counterparty_accounts | NEW | table + `CounterpartyAccountService` (relationship × account type, GL control account link, PENDING_APPROVAL→ACTIVE maker-checker, SUSPENDED/CLOSED) |
| finance_journals / finance_journal_lines | EXISTS | `journals` / `journal_lines` (LedgerService lifecycle, ManualJournalService DRAFT→VALIDATED→APPROVED→POSTED→REVERSED). Spec journal types/statuses mapped in `SubledgerCatalogue::EVENT_JOURNAL_TYPE` / `JOURNAL_STATUS` |
| finance_ledger_entries | NEW | projected by `SubledgerProjector::catchUp` from POSTED/REVERSED journal lines with all 29 spec dimensions + financials; immutable trigger; one-sided CHECK |
| financial_obligations | EXISTS | `ObligationService` (REQ-OBL-001) |
| payment_allocations | EXISTS | `AllocationService` (REQ-PAY-004) + premium components / `PremiumStatusReadModel` |
| premium_remittances | NEW | `premium_remittances` + `premium_remittance_allocations`, `PremiumRemittanceService` (unapplied cash 471100, partial / multi-policy allocation, DISPUTED / RECONCILIATION_HOLD) |
| commission_rules / commission_rule_versions | EXISTS | `commission_rule_versions`, `CommissionRuleResolver` (rates are tenant data; none invented) |
| commission_entries | EXISTS | `commission_accruals` + `commission_movements`, `CommissionMachine` (EXTENDED with `SPEC_STATE` / `specState()`) |
| commission_payments | EXISTS | `partner_payout_requests` + `PayoutService`, `CommissionPayableLink` |
| commission_clawbacks | EXISTS | `claw_back` transition + `commission.clawed_back` posting; original accrual kept |
| settlements / settlement_lines | EXISTS | `settlement_batches` + `SettlementService` (both lifecycles); lines = settlement-opened carrier PAYABLE obligations |
| reconciliations / reconciliation_lines | EXISTS | `reconciliation_imports` / `reconciliation_items`, clearing batches, manual match maker-checker |
| aging_snapshots | NEW (derived) | `AgingService` computes on read; no snapshot table (a snapshot would be a second balance store) |
| account_statement_snapshots | EXISTS + NEW | `partner_statements` (persisted); DOC-193..200 documents carry figures + content hash in provenance |
| finance_periods / finance_period_close_checks | EXISTS + NEW | `accounting_periods`, `PeriodGuard`, `PreCloseChecklist`; spec's 10 checks evaluated in PERIOD_CLOSE_STATUS report |
| finance_adjustments | EXISTS | MANUAL journals (reason code + maker-checker) = MANUAL_ADJUSTMENT |
| finance_audit_links | EXISTS | `audit_events` hash chain; AUDIT detail tab reads it |

## 3. Vocabularies

| Spec list | Status | Mapping |
|---|---|---|
| subledgers (20) | NEW | `SubledgerCatalogue::SUBLEDGER_BY_CODE` → `finance_ledger_entries.entry_type` (421000 split BROKER/AGENT by beneficiary) |
| counterparty_accounts (6 relationships) | NEW | `SubledgerCatalogue::RELATIONSHIPS` + `CONTROL_ACCOUNTS` (default chart choice; tenant may pass its own GL code) |
| journal types (22) / statuses (5) | EXTENDED | read-model mapping from accounting event + `journal_type=MANUAL`; VALIDATED/APPROVED → PENDING_APPROVAL |
| commission states (10) | EXTENDED | `CommissionMachine::SPEC_STATE`: CALCULATED→EXPECTED, PENDING/EARNED/APPROVED→ACCRUED, VESTED/AVAILABLE→PAYABLE (PARTIALLY_PAID when 0<paid<vested), ADJUSTED→HELD, REVERSED before earning→CANCELLED |
| basis types / earning events / clawback triggers | EXISTS | commission rule conditions + `CommissionLifecycleService` hooks (issuance, premium settled, cancellation/endorsement) — values are CONFIG_REQUIRED per rule version |
| premium statuses (9) | NEW | `SubledgerViews::premiumStatus` from receivable + refund + carrier-payable obligations |
| remittance statuses (7) | NEW | `PremiumRemittanceService::remittanceStatus` |
| settlement types / statuses | EXISTS | `SettlementService`; statuses mapped `SubledgerCatalogue::SETTLEMENT_STATUS`. Types other than BROKER_TO_INSURER / AGENT_COMMISSION live in their modules (provider batches, reinsurance, coinsurance, refunds) |
| reconciliation types / statuses / matching keys | EXISTS | Batch 9 reconciliation; statuses mapped `RECONCILIATION_STATUS`. DATE_MISMATCH / REFERENCE_MISMATCH / UNMATCHED_INTERNAL not distinguished by the matcher (see open questions) |
| aging buckets / basis | NEW | `AgingService` (CURRENT, 1-30, 31-60, 61-90, 91-120, 120+), basis per tenant+scope in `finance_aging_settings`; `ObligationService::aging` keeps its legacy 90_PLUS view for FR-02/03 |
| filters (39) | NEW | `SubledgerQuery::normalise/entries`; `applied_filters` echoed in JSON and CSV header. `group_id`, `fleet_id` → 422 NOT MODELLED |
| permissions (21) | NEW (routes) | used on the F1 route block; NOT added to RoleCatalogue/config (see report) |
| events (25) | EXTENDED | `DomainEventCatalogue`: 17 aliases on existing events, 10 new events (5 emitted: remittance recorded/allocated/overdue, counterparty account opened/approved, finance.statement.generated; debit/credit note + period closed/reopened catalogued, not emitted) |

## 4. Views, dashboards, reports, documents

| Spec item | Status | Implementation |
|---|---|---|
| broker_dashboard KPIs (17) + group_by + insurer_detail_tabs (12) | NEW | `SubledgerViews::brokerDashboard` / `brokerInsurerAccount`; each KPI carries a drill-down filter set |
| insurer_dashboard KPIs (12) + broker_detail_tabs (11) | NEW | `SubledgerViews::insurerDashboard` / `insurerBrokerAccount` (same rows, mirrored labels) |
| customer_accounting views (7) + statement fields | EXTENDED | `SubledgerViews::customerLedger` / `customerStatement` over `AccountStatementService` (reconciliation asserted) |
| agent_accounting views (7) + filters | NEW | `SubledgerViews::agentLedger` |
| drilldown_paths (2) | NEW | KPI → entries (filters) → entry drill-down → journal (+lines, balanced) → source → policy → documents |
| reports (22) | NEW + delegated | `SubledgerReports`: UNMATCHED_PAYMENTS→FR-09, REFUND_REGISTER→FR-11, FINANCIAL_EXCEPTION_DASHBOARD→`FinanceExceptionCentre`; DEBIT_CREDIT_NOTE_REGISTER NOT AVAILABLE; the rest computed |
| DOC-186..192 | EXISTS | document catalogue / engine (premium invoice, receipts, refund advice) |
| DOC-193..200 | NEW | `StatementDocumentService` via `DocumentNumberAllocator` + `DocumentRegister` + `pdf.engine-document`; mapped to catalogue codes (spec DOC numbers collide with the 220-document register, kept in provenance) |

## 5. Validation rules

| Rule | Where enforced |
|---|---|
| debit and credit not both positive | `fle_one_side_check` CHECK; Journal domain rule |
| journal balanced | `Journal` domain object (LedgerService, ManualJournalService) |
| posted entries immutable | DB trigger `finance_ledger_entries_immutable` |
| reversal references original | `journals.reverses_journal_id`, `finance_ledger_entries.reverses_entry_id` |
| commission outstanding = net − paid − offsets | `SubledgerViews::sumCommission`, `SubledgerReports::commissionLedger` |
| settlement net reconciles to lines | SETTLEMENT_RECONCILIATION report (difference column) |
| currency matches account currency | remittance allocation + manual journal validation |
| payment allocations ≤ payment | `AllocationService` (existing) |
| remittance allocations ≤ remittance | `prm_amount_check` + locked validation in `PremiumRemittanceService::allocate` |
| duplicate idempotency key | `FinancialPostingService::existing`, unique idempotency keys |
| cross-tenant posting forbidden | tenant-scoped services / manual journal account check |
| closed-period posting blocked | `PeriodGuard` (existing) |

## 6. Not built / open questions

* Debit / credit notes (DOC-188/189, `DebitNoteIssued`/`CreditNoteIssued`): no store exists; events catalogued, register NOT AVAILABLE.
* `group_id` / `fleet_id` filters: no group or fleet store.
* Advances (agent / provider): accounts 409100/409300 exist and are projected, but no advance workflow exists.
* Reconciliation DATE_MISMATCH / REFERENCE_MISMATCH / UNMATCHED_INTERNAL: matcher does not emit these outcomes.
* Default control-account codes for the new relationship accounts are a chart choice (OHADA class 4/5 numbering) — owner to confirm the tenant chart.
* Netting configuration: none offered (spec: only when explicitly configured) — `net_*` values are informational only.

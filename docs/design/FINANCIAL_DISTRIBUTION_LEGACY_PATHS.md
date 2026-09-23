# Legacy financial-distribution write paths — findings and decisions

Three legacy controllers write to the same tables as the governed Wave6 domain
(`app/Application/FinancialDistribution/`) using raw `DB::table()` queries,
bypassing maker-checker, advisory locks, idempotency keys, content hashes, the
`financial_distribution_events` trail and the outbox.

Both route sets are live simultaneously and are gated by **the same permission
strings**, so one grant lets a caller hit either implementation.

---

## What the investigation actually found

Everything in the original report was confirmed. Three additional findings
materially change the picture.

### 1. The two commission paths are mutually incompatible, not merely divergent

This is the finding that settles the decision.

| | Legacy | Wave6 |
|---|---|---|
| Rule status after approval | `ACTIVE` | `APPROVED` |
| Status required to accrue | `ACTIVE` | `APPROVED` |

`CommissionService::accrue()` rejects any rule whose status is not `APPROVED`;
the legacy `accrue()` requires `ACTIVE`. So a rule approved through one path
**cannot be used to accrue through the other at all**. These are not two views
of one workflow that happen to disagree on labels — they are two disjoint
workflows sharing one table. Any row is usable by exactly one of them.

The accrual vocabularies diverge the same way (`ACCRUED`/`CLAWED_BACK` versus
`PENDING`/`VESTED`), so downstream readers filtering on status see only part of
the ledger depending on which path wrote it.

### 2. `created_by` being NULL silently defeated Wave6 maker-checker

`commission_rule_versions.created_by` exists and Wave6's `approveRule()`
enforces `created_by === actor → reject`. The legacy `createRule()` never
populated it.

`NULL === $actor->id` is never true, so **a legacy-created rule passed Wave6
maker-checker unconditionally** — including self-approval by its own author.
The control was present in code and inert in practice for any rule that entered
through the legacy door. Combined with `tenant_id` also being NULL (which made
the row unapprovable via Wave6's `owns()` check), legacy-created rules were
simultaneously ungovernable *and* exempt from the one governance check that
could still see them.

### 3. The cross-tenant exposure was wider than reported

Confirmed as reported: `balance()` had no tenant scoping, and settlement
`show()`/`approve()` had none.

Also found, not previously reported:

- `SettlementController::prepare()` selected policies with
  `where('carrier_id')->whereIn('id', $policy_ids)` and **no tenant filter**, so
  supplying another tenant's policy UUIDs pulled their policies into your
  settlement batch — a cross-tenant **write**, not just a read.
- `vest()` and `clawback()` looked accruals up by id alone, so either could be
  driven against another tenant's accrual.
- The commission-accrual duplicate guard in `accrue()` was unscoped, so one
  tenant's accrual blocked another tenant's legitimate accrual on the same
  policy/partner pair.

Every one of these tables already has a `tenant_id` column. Nothing was blocked
by schema; the filters were simply absent.

### 4. One correction to the original report

`SettlementController::approve()` **does** enforce maker-checker
(`abort_if($row->prepared_by === $r->user()->id, 403)`). It is not ungoverned.
Its defects were the missing tenant scoping and the divergent status vocabulary.

---

## Decisions

### Landed now — tenant scoping and identity stamping

Scoping is correct under *all three* options below: if an endpoint is kept it
must be scoped, if it becomes a wrapper the wrapper scopes, and if it is deleted
the question is moot. It is also a security fix, not a product change. So it
landed ahead of the structural decision rather than waiting on it.

- Tenant filters added to every legacy commission and settlement query
  (`createRule` version probe, `approveRule`, `accrue` rule lookup and duplicate
  guard, `vest`, `clawback`, `balance`, settlement `prepare`/`show`/`approve`).
- `tenant_id` and `created_by` now stamped on `commission_rule_versions`;
  `tenant_id` on `commission_accruals` and `settlement_batches`.
- `approveRule()` now refuses self-approval, matching `CommissionService`.

Covered by `tests/Feature/FinancialDistribution/LegacyControllerTenantScopingTest.php`
— 8 cases, each verified to fail against the pre-fix controllers and pass after.

### Executed — the structural change (signed off)

All three open questions were answered, and the answers made deletion viable:

- **Nothing calls the legacy routes**, so they were deleted outright rather than
  wrapped. The `ACTIVE`->`APPROVED` / `ACCRUED`->`PENDING` vocabulary break
  disappears with them — there was no client to break.
- **Both bordereau flows are real**, so `BordereauService::prepare()` gained an
  explicit `policy_ids` selection mode alongside its period auto-select. An
  explicit list naming a policy outside the caller's tenant or carrier now
  fails loudly rather than quietly billing a shorter list.
- **`settlement_approvals` is worth keeping**, so `CarrierSettlementService::approve()`
  now writes it. The events trail records the transition but not the stage,
  deciding actor or reasoning.

Also closed: `BrokerOperationsController::submitBordereau` required only
`DRAFT`, letting one actor create and submit with no second approver. It now
requires `APPROVED` and refuses submission by the preparer.

Removed: `POST /commission-rules`, `POST /commission-rules/{rule}/approve`,
`POST /commissions/accrue`, `POST /commissions/{accrual}/vest`,
`POST /commissions/{accrual}/clawback`, `POST /settlements`,
`POST /settlements/{batch}/approve`.

Retained (read-only, no Wave6 equivalent):
`GET /partners/{partner}/commission-balance` and `GET /settlements/{batch}`.

### Original recommendation, superseded by the above

| Endpoint | Decision | Rationale |
|---|---|---|
| `POST /commission-rules` | **(b) wrapper** | Pure duplicate of `CommissionService::createRule`, minus the advisory lock and basis-point validation. No distinct product behaviour to preserve. |
| `POST /commission-rules/{rule}/approve` | **(b) wrapper** | Still lacks effective-date overlap validation, which Wave6 has. Wrapping inherits it. Changes the returned status `ACTIVE`→`APPROVED` — see breaking-change note. |
| `POST /commissions/accrue` | **(b) wrapper** | Wave6 adds idempotency keys, which this path has no equivalent of; a retried accrual currently 409s rather than replaying. Returned status changes `ACCRUED`→`PENDING`. |
| `POST /commissions/{accrual}/vest` | **(b) wrapper** | Same table, same intent, no extra behaviour. |
| `POST /commissions/{accrual}/clawback` | **(b) wrapper** | Same. |
| `GET /partners/{partner}/commission-balance` | **(c) keep** | Read-only aggregate with no Wave6 equivalent. Now tenant-scoped. Genuinely useful; nothing to delegate to. |
| `POST /settlements` | **(b) wrapper** | Duplicates `CarrierSettlementService::prepare` on a narrower state machine. |
| `GET /settlements/{batch}` | **(c) keep** | Read-only, and it returns `settlement_approvals`, which the Wave6 path does not model. See open question. |
| `POST /settlements/{batch}/approve` | **(b) wrapper** | Has maker-checker but the wrong state machine; Wave6 adds SUBMITTED/PAID/FAILED/REVERSED. |
| `BrokerOperationsController::createBordereau` | **(c) keep, reconcile separately** | **Not interchangeable** with `BordereauService::prepare`. This takes an explicit client-supplied `policy_ids` list; Wave6 auto-selects by carrier and period window. Those are two different product flows — "bill exactly these policies" versus "bill everything in this period". Collapsing them would silently change which policies land on a bordereau. Needs a product decision, not a refactor. |
| `BrokerOperationsController::submitBordereau` | **(a) delete or gate** | This one is a genuine control failure independent of the flow question: `DRAFT → SUBMITTED` skips `APPROVED`, so a single actor creates and submits with no second approver. Should require the Wave6 approval step regardless of which creation flow feeds it. |

### Why wrappers rather than deletion

Deleting live routes breaks any existing caller with no migration path.
Wrapping keeps the URL contract while making the governed service the only
writer, which is what actually resolves the divergence.

### The breaking part, and why it should not be done silently

Wrapping changes observable response values: `ACTIVE`→`APPROVED`,
`ACCRUED`→`PENDING`. A client branching on those strings breaks.

That is unavoidable — the legacy vocabulary is precisely what makes the rows
unusable by the rest of the system — but it is an API break on financial
endpoints and should ship deliberately: announced, versioned, or behind a
window, not as a side effect of a cleanup. **This is why the wrappers are not
implemented in this change.**

---

## Open questions for product — all answered, see "Executed" above

1. **Are the two bordereau flows both wanted?** If the explicit-`policy_ids`
   flow is real, `BordereauService` needs to accept an explicit selection mode
   rather than the legacy controller being deleted.
2. **Is `settlement_approvals` still wanted?** Only the legacy path writes it.
   If yes, Wave6 should adopt it; if no, the legacy `show()` should stop
   returning it and the table should be retired.
3. **Who still calls the legacy routes?** Nothing in this repo does. If nothing
   external does either, deletion becomes viable and the vocabulary break
   disappears entirely — worth confirming before building wrappers.

---

## Testing note

The existing `tests/Feature/Wave6/` tests `file_get_contents()` the service
classes and assert substrings. They never touch the database and cannot catch
behavioural regressions in any of this. The new test file is the first
behavioural coverage of these controllers; it runs against Postgres on an
isolated database, never the shared `opesinsure_testing` one.

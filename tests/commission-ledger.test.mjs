import test from "node:test";
import assert from "node:assert/strict";
import {
  availableBases,
  basisOf,
  commissionTotals,
  kpiRows,
  matchesLifecycle,
  periodComparison,
  previousWindow,
  quarterValue,
  runCommissions,
  saleCommission,
  stageOf,
} from "../src/components/partner/commissionFilters.ts";
import { customPeriod } from "../src/components/filters/core.ts";

const NOW = new Date("2026-09-27T10:00:00Z");
// Shape of the live broker ledger (partner/broker/commissions joined with policies + commission-accruals).
const row = (o) => ({ currency: "XAF", paid_minor: 0, clawed_back_minor: 0, carrier_id: "c1", carrier_name: "SanlamAllianz", line_code: "MOTOR", ...o });
const rows = [
  row({ id: "a", policy_id: "p1", policy_number: "POL-921", customer_name: "Pharmacie du Rond-Point", status: "PENDING", amount_minor: 544800, issued_at: "2026-01-27T10:10:42+01:00", available_at: "2026-02-26T10:10:42+01:00", accrued_at: "2026-09-02T01:10:04Z", carrier_id: "c2", carrier_name: "Chanas" }),
  row({ id: "b", policy_id: "p2", policy_number: "POL-931", customer_name: "Roger Etoundi", status: "AVAILABLE", amount_minor: 657240, premium_minor: 5477000, issued_at: "2026-08-18T10:10:42+01:00", available_at: "2026-09-17T10:10:42+01:00", accrued_at: "2026-08-18T12:00:00Z" }),
  row({ id: "c", policy_id: "p3", policy_number: "POL-913", customer_name: "Société Camerounaise de Transport", status: "PAID", amount_minor: 828000, paid_minor: 828000, issued_at: "2026-06-27T10:10:42+01:00", accrued_at: "2026-06-27T12:00:00Z", paid_at: "2026-09-05T09:00:00Z" }),
  // Partly paid, with a clawback: net 700 000, paid 200 000, owed 500 000.
  row({ id: "d", policy_id: "p4", policy_number: "POL-911", customer_name: "Société Camerounaise de Transport", status: "AVAILABLE", amount_minor: 800000, paid_minor: 200000, clawed_back_minor: 100000, accrued_at: "2026-09-10T12:00:00Z", line_code: "HEALTH" }),
  row({ id: "e", policy_id: "p5", policy_number: "POL-912", customer_name: "Roger Etoundi", status: "REVERSED", amount_minor: 300000, accrued_at: "2026-09-11T12:00:00Z" }),
  row({ id: "f", policy_id: "p6", proposal_id: "prop6", status: "ESTIMATED", amount_minor: 90000, rate_bps: 1200, accrued_at: "2026-09-12T12:00:00Z" }),
];

test("stages map server statuses to estimated / accrued / payable / paid", () => {
  assert.deepEqual(rows.map(stageOf), ["accrued", "payable", "paid", "payable", "reversed", "estimated"]);
});

test("totals: earned = unpaid + paid, clawbacks and partial payments honoured, reversals and estimates excluded", () => {
  const t = commissionTotals(rows);
  assert.equal(t.paid, 828000 + 200000);
  assert.equal(t.unpaid, 544800 + 657240 + 500000);
  assert.equal(t.available, 657240 + 500000);
  assert.equal(t.total, t.unpaid + t.paid);
  assert.equal(t.estimated, 90000);
});

test("lifecycle drill-down shows exactly the rows each KPI sums", () => {
  const sum = (l, pick) => rows.filter((r) => matchesLifecycle(r, l)).length && commissionTotals(rows.filter((r) => matchesLifecycle(r, l)))[pick];
  const t = commissionTotals(rows);
  assert.equal(sum("unpaid", "unpaid"), t.unpaid);
  assert.equal(sum("payable", "available"), t.available);
  assert.equal(sum("paid", "paid"), t.paid);
  // A partly paid row is both unpaid (balance) and paid (settled part).
  assert.ok(matchesLifecycle(rows[3], "paid") && matchesLifecycle(rows[3], "unpaid"));
});

test("KPI cards ignore the lifecycle choice so drilling into Paid does not zero the others", () => {
  const values = { lifecycle: ["paid"] };
  assert.deepEqual(runCommissions(rows, values, "", NOW).map((r) => r.id).sort(), ["c", "d"]);
  assert.equal(commissionTotals(kpiRows(rows, values, "", NOW)).unpaid, commissionTotals(rows).unpaid);
});

test("date basis: accrual uses the accrual date, not the availability date; unavailable bases are not offered", () => {
  const month = { period: ["this_month"], basis: ["accrual"] };
  assert.deepEqual(runCommissions(rows, month, "", NOW).map((r) => r.id).sort(), ["a", "d", "e", "f"]);
  const byAvail = { period: ["this_month"], basis: ["available"] };
  assert.deepEqual(runCommissions(rows, byAvail, "", NOW).map((r) => r.id), ["b"]);
  assert.deepEqual(availableBases(rows), ["accrual", "issue", "available", "payment"]);
  assert.equal(basisOf({ basis: ["sale"] }, rows), "accrual", "sale date never sent -> falls back");
  assert.deepEqual(runCommissions(rows, { period: ["this_month"], basis: ["payment"] }, "", NOW).map((r) => r.id), ["c"]);
});

test("insurer, product line and search narrow rows and totals together (accent-insensitive)", () => {
  assert.deepEqual(runCommissions(rows, { carriers: ["c2"] }, "", NOW).map((r) => r.id), ["a"]);
  assert.deepEqual(runCommissions(rows, { lines: ["HEALTH"] }, "", NOW).map((r) => r.id), ["d"]);
  assert.deepEqual(runCommissions(rows, {}, "societe", NOW).map((r) => r.id).sort(), ["c", "d"]);
  assert.equal(commissionTotals(runCommissions(rows, {}, "etoundi", NOW)).total, 657240);
});

test("custom range is inclusive Douala days; half-typed dates never hide rows", () => {
  const v = (from, to) => ({ period: [customPeriod(from, to)], basis: ["accrual"] });
  assert.deepEqual(runCommissions(rows, v("2026-09-10", "2026-09-11"), "", NOW).map((r) => r.id).sort(), ["d", "e"]);
  assert.equal(runCommissions(rows, v("2026-0", ""), "", NOW).length, rows.length);
  // Reversed bounds are swapped, not an empty result.
  assert.deepEqual(runCommissions(rows, v("2026-09-11", "2026-09-10"), "", NOW).map((r) => r.id).sort(), ["d", "e"]);
});

test("sort by amount and by date on the chosen basis", () => {
  assert.equal(runCommissions(rows, { sort: ["amount_desc"] }, "", NOW)[0].id, "c");
  assert.equal(runCommissions(rows, { sort: ["amount_asc"] }, "", NOW)[0].id, "f");
  assert.equal(runCommissions(rows, { sort: ["date_desc"], basis: ["accrual"] }, "", NOW)[0].id, "f");
  assert.equal(runCommissions(rows, { sort: ["date_asc"], basis: ["accrual"] }, "", NOW)[0].id, "c");
});

test("quarter preset and period comparison", () => {
  assert.equal(quarterValue(NOW), "custom:2026-07-01..2026-09-30");
  assert.equal(quarterValue(new Date("2026-12-31T23:30:00Z")), "custom:2027-01-01..2027-03-31", "Douala is already in Q1 2027");
  assert.equal(previousWindow("any", NOW), null);
  const cmp = periodComparison(rows, { period: ["this_month"], basis: ["accrual"] }, "", NOW);
  assert.equal(cmp.current, 544800 + 700000);
  assert.equal(cmp.previous, 657240);
  const lm = previousWindow("last_month", NOW);
  assert.equal(new Date(lm.from).toISOString(), "2026-06-30T23:00:00.000Z");
});

test("commission per sale joins on policy / proposal id and never guesses", () => {
  const s = saleCommission(rows, { policyId: "p2" });
  assert.equal(s.amount_minor, 657240);
  assert.equal(s.stage, "payable");
  assert.equal(s.rate_bps, null);
  assert.equal(s.effective_rate_bps, 1200);
  assert.equal(s.payable_from, "2026-09-17T10:10:42+01:00");
  const paid = saleCommission(rows, { policyId: "p3" });
  assert.equal(paid.stage, "paid");
  assert.equal(paid.paid_at, "2026-09-05T09:00:00Z");
  const est = saleCommission(rows, { proposalId: "prop6" });
  assert.ok(est.estimated);
  assert.equal(est.rate_bps, 1200);
  assert.equal(saleCommission(rows, { policyId: "nope" }), null);
  assert.equal(saleCommission(rows, { policyId: "p5" }).stage, "reversed");
});

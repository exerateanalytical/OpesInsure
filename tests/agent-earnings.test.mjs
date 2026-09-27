import test from "node:test";
import assert from "node:assert/strict";
import {
  calculationLines,
  commissionVocab,
  earningsSummary,
  maskPhone,
  paymentState,
  runEarnings,
  withdrawalReached,
  withdrawalVocab,
} from "../src/components/partner/agentEarnings.ts";

const NOW = new Date("2026-09-27T10:00:00Z");
const row = (o) => ({ currency: "XAF", paid_minor: 0, clawed_back_minor: 0, amount_minor: 1000, carrier_id: "c1", carrier_name: "Insurer", line_code: "MOTOR", ...o });

test("commission statuses map onto the locked vocabulary", () => {
  assert.equal(commissionVocab(row({ status: "ACCRUED" })), "accrued");
  assert.equal(commissionVocab(row({ status: "PENDING_ELIGIBILITY" })), "pending");
  assert.equal(commissionVocab(row({ status: "ESTIMATED" })), "pending");
  assert.equal(commissionVocab(row({ status: "AVAILABLE" })), "available");
  assert.equal(commissionVocab(row({ status: "APPROVED_FOR_PAYOUT" })), "available");
  assert.equal(commissionVocab(row({ status: "PAID" })), "paid");
  assert.equal(commissionVocab(row({ status: "AVAILABLE", paid_minor: 1000 })), "paid");
  assert.equal(commissionVocab(row({ status: "CANCELLED" })), "reversed");
  assert.equal(commissionVocab(row({ status: "WITHHELD" })), "disputed");
  assert.equal(commissionVocab(row({ status: "SOMETHING_NEW" })), "accrued");
});

test("withdrawal statuses map onto the locked vocabulary and timeline", () => {
  assert.equal(withdrawalVocab("REQUESTED"), "requested");
  assert.equal(withdrawalVocab("APPROVED"), "under_review");
  assert.equal(withdrawalVocab("PROCESSING"), "processing");
  assert.equal(withdrawalVocab("PAID"), "paid");
  assert.equal(withdrawalVocab("CANCELED"), "cancelled");
  assert.equal(withdrawalReached(withdrawalVocab("PROCESSING")), 2);
  assert.equal(withdrawalReached(withdrawalVocab("FAILED")), -1);
});

test("phone masking never reveals more than the server did", () => {
  assert.equal(maskPhone("+237690000432"), "+237 6•• ••• 432");
  assert.equal(maskPhone("+237600••••"), "+237 6•• ••• •••");
  assert.equal(maskPhone(""), "—");
});

test("earnings filters: vocab, payment, customer, policy status + shared dims", () => {
  const rows = [
    row({ id: "a", status: "AVAILABLE", customer_id: "u1", policy_status: "ACTIVE", accrued_at: "2026-09-10T10:00:00Z" }),
    row({ id: "b", status: "PAID", paid_minor: 1000, paid_at: "2026-09-12T10:00:00Z", customer_id: "u2", accrued_at: "2026-09-01T10:00:00Z" }),
    row({ id: "c", status: "ACCRUED", carrier_id: "c2", customer_id: "u1", accrued_at: "2026-08-01T10:00:00Z" }),
  ];
  assert.deepEqual(runEarnings(rows, { vocab: ["available"] }).map((r) => r.id), ["a"]);
  assert.deepEqual(runEarnings(rows, { customers: ["u1"] }, "", NOW).map((r) => r.id).sort(), ["a", "c"]);
  assert.deepEqual(runEarnings(rows, { carriers: ["c2"] }).map((r) => r.id), ["c"]);
  assert.deepEqual(runEarnings(rows, { payment: ["paid"] }).map((r) => r.id), ["b"]);
  assert.deepEqual(runEarnings(rows, { policy_status: ["ACTIVE"] }).map((r) => r.id), ["a"]);
  assert.deepEqual(runEarnings(rows, { period: ["this_month"] }, "", NOW).map((r) => r.id).sort(), ["a", "b"]);
  assert.equal(paymentState(rows[2]), "unpaid");
});

test("summary: tiles sum exactly the server rows", () => {
  const rows = [
    row({ id: "a", status: "AVAILABLE", amount_minor: 5000 }),
    row({ id: "b", status: "PAID", amount_minor: 2000, paid_minor: 2000, paid_at: "2026-09-12T10:00:00Z" }),
    row({ id: "c", status: "ACCRUED", amount_minor: 3000 }),
    row({ id: "d", status: "REVERSED", amount_minor: 9000 }),
  ];
  const s = earningsSummary(rows, [{ amount_minor: 1500, status: "PAID" }, { amount_minor: 700, status: "REQUESTED" }], NOW);
  assert.deepEqual({ ...s, currency: undefined }, { total: 10000, available: 5000, pending: 3000, paidMonth: 2000, withdrawn: 1500, currency: undefined });
});

test("calculation shows server values only", () => {
  assert.deepEqual(calculationLines(row({ status: "ESTIMATED" })), { premium: null, rate_bps: null, gross: null, adjustments: null, net: null, estimated: true });
  const c = calculationLines(row({ status: "ACCRUED", premium_minor: 10000, rate_bps: 1000, clawed_back_minor: 200 }));
  assert.equal(c.gross, 1000);
  assert.equal(c.adjustments, -200);
  assert.equal(c.net, 800);
});

// Owner fixes 2026-09-29 (purchase & payment): no second charge for a paid application, counter-offer price
// before Accept, insurer rejection after payment, payment deep link after a restart, no pay without accepted
// terms, and a vehicle added mid-quote coming back selected.
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { hubForward, isPayable, issuanceDeclined, nextPurchaseStep, paidRoute, paymentConflict, paymentState } from "../src/lib/paymentRouting.ts";
import { termsGate } from "../src/lib/contractTerms.ts";
import { counterOfferView } from "../src/lib/counterOffer.ts";
import { API_ERROR_COPY } from "../src/lib/apiErrors.ts";
import { en } from "../src/i18n/en.ts";
import { fr } from "../src/i18n/fr.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const NOW = Date.parse("2026-09-29T10:00:00Z");

test("a SUCCEEDED payment (or issuance pending) makes a PAYMENT_PENDING application paid, not payable", () => {
  assert.equal(paymentState({ payments: [{ status: "FAILED" }, { status: "SUCCEEDED" }] }), "paid");
  assert.equal(paymentState({ purchaseStatus: "ISSUANCE_PENDING" }), "paid");
  assert.equal(paymentState({ purchaseStatus: "POLICY_ISSUED" }), "paid");
  assert.equal(paymentState({ payments: [] }), null);
  assert.equal(paymentState({ payments: [{ status: "FAILED" }, { status: "EXPIRED" }] }), null);
  assert.equal(isPayable("PAYMENT_PENDING", null, "paid"), false);
  assert.equal(isPayable("PAYMENT_PENDING", null, null), true);
  assert.equal(nextPurchaseStep("PAYMENT_PENDING", true, null, "paid"), null);
  assert.equal(hubForward({ proposalId: "p1", status: "PAYMENT_PENDING", termsAccepted: true, forwarded: false, paid: "paid" }), null);
  assert.deepEqual(termsGate({ status: "PAYMENT_PENDING", termsAccepted: true, paid: "paid" }), { mode: "blocked", reason: "paid" });
});

test("a payment with the operator is in flight (same rule as the server's 409 PAYMENT_IN_PROGRESS)", () => {
  const future = "2026-09-29T10:10:00Z";
  const past = "2026-09-29T09:50:00Z";
  assert.equal(paymentState({ payments: [{ status: "PROCESSING" }], now: NOW }), "in_flight");
  assert.equal(paymentState({ payments: [{ status: "PENDING_CUSTOMER", provider_reference: "ref", expires_at: future }], now: NOW }), "in_flight");
  assert.equal(paymentState({ payments: [{ status: "PENDING_CUSTOMER", provider_reference: "ref", expires_at: past }], now: NOW }), null, "expired prompt");
  assert.equal(paymentState({ payments: [{ status: "PENDING_CUSTOMER", provider_reference: null, expires_at: future }], now: NOW }), null, "never sent to the operator");
  assert.equal(paymentState({ purchaseStatus: "PAYMENT_PROCESSING" }), "in_flight");
  assert.deepEqual(paidRoute("p1", "paid"), { pathname: "/confirmation", params: { proposalId: "p1" } });
  assert.deepEqual(paidRoute("p1", "in_flight"), { pathname: "/payment", params: { proposalId: "p1" } });
});

test("409 codes from POST /payments are recognised and have EN/FR copy", () => {
  assert.equal(paymentConflict({ code: "PAYMENT_ALREADY_MADE" }), "paid");
  assert.equal(paymentConflict({ code: "payment_in_progress" }), "in_flight");
  assert.equal(paymentConflict({ code: "CONFLICT" }), null);
  assert.equal(paymentConflict(null), null);
  for (const code of ["PAYMENT_ALREADY_MADE", "PAYMENT_IN_PROGRESS", "TERMS_NOT_ACCEPTED"]) {
    const key = API_ERROR_COPY[code];
    assert.ok(key, code);
    assert.ok(en[key] && fr[key] && en[key] !== fr[key], key);
  }
});

test("screens never offer checkout for a paid application", () => {
  const hub = read("app/proposals/[id].tsx");
  assert.match(hub, /paymentState\(\{ payments: p\.payments \}\)/);
  assert.match(hub, /forwarded: forwarded\.current, paid \}/);
  const checkout = read("app/checkout.tsx");
  assert.match(checkout, /paymentConflict\(e\)/);
  assert.match(read("app/quote/terms.tsx"), /termsGate\(\{[^}]*paid \}\)/);
});

test("issuance rejected after payment: final state, polling stops", () => {
  assert.equal(issuanceDeclined({ policy: null, issuance: { status: "REJECTED" } }), true);
  assert.equal(issuanceDeclined({ policy: null, issuance: { status: "PENDING_APPROVAL" } }), false);
  assert.equal(issuanceDeclined({ policy: { id: "x" }, issuance: { status: "REJECTED" } }), false);
  assert.equal(issuanceDeclined(null), false);
  const confirmation = read("app/confirmation.tsx");
  assert.match(confirmation, /if \(issued \|\| issuanceFailed \|\| paymentFailed \|\| declined\) return;/);
  assert.match(confirmation, /cfRequestRefund/);
});

test("counter-offer: revised total and changed parts before Accept", () => {
  const v = counterOfferView(
    { premium_minor: 120000, tax_minor: null, fee_minor: 5000, total_minor: 130000, notes: " Claims history. " },
    { premium_minor: 90000, tax_minor: 5000, fee_minor: 5000, total_minor: 100000 },
  );
  assert.equal(v.revisedTotal, 130000);
  assert.equal(v.originalTotal, 100000);
  assert.deepEqual(v.rows.map((r) => [r.key, r.from, r.to]), [["premium", 90000, 120000], ["total", 100000, 130000]]);
  assert.equal(v.notes, "Claims history.");
  // No revised total: the server keeps the original total, so that is what Accept charges.
  assert.equal(counterOfferView({ premium_minor: 95000 }, { premium_minor: 90000, total_minor: 100000 }).revisedTotal, 100000);
  assert.equal(counterOfferView(null, { total_minor: 100000 }).revisedTotal, null);
  const hub = read("app/proposals/[id].tsx");
  assert.doesNotMatch(hub, /\.counteroffer\b/, "the server field is counter_offer");
  assert.match(hub, /counterOfferView\(p\?\.counter_offer/);
  assert.match(read("src/store/insurance.ts"), /ProposalsApi\.mobileShow\(id\)/);
});

test("payment screen recovers this application's payment after a restart", () => {
  const store = read("src/store/insurance.ts");
  assert.match(store, /payment\.proposal_id === proposalId/, "a saved payment of another application is ignored");
  assert.match(store, /refreshPurchase\(proposalId\)/, "falls back to the purchase status");
  const payment = read("app/payment.tsx");
  assert.match(payment, /recover\(proposalId \?\? null\)/);
  assert.match(payment, /payNoneFoundTitle/, "no endless Recovering");
});

test("a vehicle added from the quote comes back selected", () => {
  assert.match(read("app/quote/risk.tsx"), /returnTo: "quote"/);
  assert.match(read("app/quote/risk.tsx"), /useFocusEffect\(loadAssets\)/);
  const add = read("app/assets/new.tsx");
  assert.match(add, /setRiskAsset\(id\)/);
  assert.match(add, /params\.returnTo === "quote"/);
});

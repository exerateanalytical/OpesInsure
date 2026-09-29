import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { coverEnd, coverStart, hasInstalments, nonPaymentConsequence, paymentPlan, quoteDocumentReady, termsGate } from "../src/lib/contractTerms.ts";
import { quoteNotReady } from "../src/lib/documentViewer.ts";
import { en } from "../src/i18n/en.ts";
import { fr } from "../src/i18n/fr.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("cover start: issued date, then the chosen date, else the event resolved at issuance", () => {
  assert.deepEqual(coverStart(null, null, "2026-10-01T00:00:00Z"), { kind: "date", date: "2026-10-01T00:00:00Z" });
  assert.deepEqual(coverStart({ effective_rule: "SPECIFIED_DATE", start_date: "2026-11-05" }), { kind: "date", date: "2026-11-05T12:00:00" });
  assert.deepEqual(coverStart({ effective_rule: "IMMEDIATE" }), { kind: "event", event: "PAYMENT" });
  assert.deepEqual(coverStart(null), { kind: "event", event: "PAYMENT" }, "no cover terms = server default");
  assert.deepEqual(coverStart(null, { default_effective_rule: "APPROVAL_DATE" }), { kind: "event", event: "APPROVAL" });
  assert.deepEqual(coverStart({ effective_rule: "MIDNIGHT_RULE" }), { kind: "event", event: "MIDNIGHT" });
  assert.deepEqual(coverStart({ effective_rule: "SPECIFIED_DATE", start_date: null }), { kind: "event", event: "PAYMENT" }, "no date: never invent one");
});

test("cover end: chosen/product duration instead of a fixed 12 months", () => {
  assert.deepEqual(coverEnd({ effective_rule: "SPECIFIED_DATE", ends_before: "2027-11-05" }), { kind: "date", date: "2027-11-05T12:00:00" });
  assert.deepEqual(coverEnd({ effective_rule: "IMMEDIATE", duration: { unit: "DAY", value: 30 } }), { kind: "duration", unit: "DAY", value: 30 });
  assert.deepEqual(coverEnd(null, { durations: [{ unit: "MONTH", value: 6 }] }), { kind: "duration", unit: "MONTH", value: 6 });
  assert.deepEqual(coverEnd(null, null), { kind: "duration", unit: "MONTH", value: 12 }, "server default");
  assert.deepEqual(coverEnd(null, null, "2027-01-01T00:00:00Z"), { kind: "date", date: "2027-01-01T00:00:00Z" });
});

test("payment plan and instalment schedule from cover_terms", () => {
  const single = paymentPlan(null);
  assert.equal(single.plan, "SINGLE");
  assert.equal(hasInstalments(single), false);
  const q = paymentPlan({
    instalment_plan: "QUARTERLY",
    schedule: [
      { sequence: 2, due: "+3M", amount_minor: 2600000, fee_minor: 100000 },
      { sequence: 1, due: "AT_BIND", amount_minor: 2500000, fee_minor: 0 },
    ],
    total_payable_minor: 5100000,
  });
  assert.equal(q.plan, "QUARTERLY");
  assert.equal(hasInstalments(q), true);
  assert.deepEqual(q.rows.map((r) => r.due), [{ kind: "bind" }, { kind: "months", months: 3 }]);
  assert.equal(q.rows[1].feeMinor, 100000);
  assert.equal(q.totalMinor, 5100000);
  assert.deepEqual(paymentPlan({ instalment_plan: "CUSTOM", schedule: [{ sequence: 1, due: "AT_BIND", amount_minor: 10 }, { sequence: 2, due: "CUSTOM_2", amount_minor: 5 }] }).rows[1].due, { kind: "later" });
  assert.equal(paymentPlan({ instalment_plan: "CUSTOM", schedule: [{ sequence: 1, due: "AT_BIND", amount_minor: 10 }, { sequence: 2, due: "CUSTOM_2", amount_minor: 5 }] }).totalMinor, 15);
});

test("non-payment consequence only when the server has a verified rule", () => {
  assert.equal(nonPaymentConsequence({ non_payment_consequence: "GRACE_THEN_SUSPEND" }), "GRACE_THEN_SUSPEND");
  assert.equal(nonPaymentConsequence(null, { non_payment_consequence: "CANCEL" }), "CANCEL");
  assert.equal(nonPaymentConsequence({ non_payment_consequence: "UNVERIFIED" }), null);
  assert.equal(nonPaymentConsequence(null, null), null);
});

test("terms gate: accept only while it means something", () => {
  assert.deepEqual(termsGate({ status: "APPROVED" }), { mode: "accept" });
  assert.deepEqual(termsGate({ status: "PAYMENT_PENDING", termsAccepted: true }), { mode: "accepted" });
  assert.deepEqual(termsGate({ status: "DOCUMENTS_PENDING", blocking: ["DECLARATION_REQUIRED:TERMS_ACCEPTANCE"] }), { mode: "accept" });
  assert.deepEqual(termsGate({ status: "DOCUMENTS_PENDING" }), { mode: "accept" }, "older server without a checklist");
  assert.deepEqual(termsGate({ status: "DOCUMENTS_PENDING", blocking: ["DOCUMENT_MISSING:ID_CARD"] }), { mode: "blocked", reason: "documents" });
  const blocked = { DISCLOSURES_PENDING: "questions", DRAFT: "questions", UNDER_REVIEW: "review", SUBMITTED: "review", INFORMATION_REQUIRED: "information", COUNTEROFFERED: "counteroffer", PAID: "paid", ISSUED: "paid", DECLINED: "closed", WITHDRAWN: "closed", EXPIRED: "closed" };
  for (const [status, reason] of Object.entries(blocked)) assert.deepEqual(termsGate({ status }), { mode: "blocked", reason }, status);
  assert.deepEqual(termsGate({ status: "APPROVED", policyId: "pol-1" }), { mode: "blocked", reason: "paid" }, "policy already issued");
});

test("quote PDF: ready once generated; a 422 is 'being prepared', not a failure", () => {
  assert.equal(quoteDocumentReady({ lifecycle_state: "ACCEPTED" }), true);
  assert.equal(quoteDocumentReady({ lifecycle_state: "CALCULATED" }), false);
  assert.equal(quoteDocumentReady({ lifecycle_state: null, status: "ACCEPTED" }), true);
  assert.equal(quoteDocumentReady({ lifecycle_state: null, status: "OFFERED" }), null);
  assert.equal(quoteDocumentReady(null), null);
  assert.equal(quoteNotReady({ kind: "quote", quoteId: "q" }, { status: 422 }), true);
  assert.equal(quoteNotReady({ kind: "url", url: "https://x" }, { status: 422 }), false);
  assert.equal(quoteNotReady({ kind: "quote", quoteId: "q" }, { status: 500 }), false);
});

test("contract copy exists in EN and FR", () => {
  const keys = Object.keys(en).filter((k) => k.startsWith("ct") && /^ct[A-Z_]/.test(k));
  assert.ok(keys.length >= 40);
  for (const k of keys) assert.ok(fr[k] && fr[k] !== en[k], `fr ${k}`);
  for (const r of ["questions", "documents", "review", "information", "counteroffer", "paid", "closed"]) assert.ok(en[`ctBlocked_${r}`], r);
  for (const p of ["SINGLE", "MONTHLY", "QUARTERLY", "SEMI_ANNUAL", "ANNUAL", "CUSTOM"]) assert.ok(en[`ctPlan_${p}`], p);
});

test("contract screens: server facts, one summary, no stale store data", () => {
  const terms = read("app/quote/terms.tsx");
  assert.match(terms, /termsGate\(/, "acceptance only in an acceptable state");
  assert.match(terms, /submitting\.current/, "double-tap guard");
  assert.match(terms, /useProposalQuote\(proposal\)/);
  assert.match(terms, /statement/, "the recorded declaration wording is what the checkbox accepts");
  assert.match(terms, /isPayable\(proposal\.status/, "approved banner only when payable");
  assert.doesNotMatch(terms, /<Card>/, "shared review cards only");
  const summary = read("src/components/purchase/ProposalSummary.tsx");
  assert.match(summary, /coverStart\(terms, rule/);
  assert.match(summary, /paymentPlan\(terms\)/);
  assert.match(summary, /<CoverList /);
  assert.doesNotMatch(summary, /sumTwelveMonths/, "no fixed 12-month fallback");
  const checkout = read("app/checkout.tsx");
  assert.match(checkout, /storeProposal\.id === id/, "never shows another application from the store");
  assert.match(checkout, /termsAccepted !== false/);
  assert.match(read("src/components/policies/PolicyDetailView.tsx"), /<CoverList /);
  assert.match(read("app/documents/view.tsx"), /quoteNotReady\(source, error\)/);
});

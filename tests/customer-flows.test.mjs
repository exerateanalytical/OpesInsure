import test from "node:test";
import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import {
  activityFallback,
  activityKey,
  balanceSide,
  canSetPayout,
  canSignDischarge,
  complaintPayload,
  complaintTone,
  instalmentAttention,
  instalmentTone,
  normalizeMsisdn,
  payoutPayload,
  refundIdFromPath,
  refundTone,
  settlementProbeClaims,
  statementPeriod,
} from "../src/lib/customerFlows.ts";
import { resolveNotificationTarget } from "../src/lib/customerLogic.ts";
import { customerTabForRoute } from "../src/lib/customerNav.ts";
import { en } from "../src/i18n/en.ts";
import { fr } from "../src/i18n/fr.ts";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

test("complaint payload: one subject (claim wins), trimmed, optional contact", () => {
  assert.deepEqual(complaintPayload({ description: "  Too slow  ", policyId: "p", claimId: "c", contact: " a@b.cm " }), { description: "Too slow", claim_id: "c", contact: "a@b.cm" });
  assert.deepEqual(complaintPayload({ description: "x", policyId: "p", contact: "" }), { description: "x", policy_id: "p" });
  assert.equal(complaintTone("CLOSED", true), "neutral");
  assert.equal(complaintTone("RECEIVED", false), "neutral");
  assert.equal(complaintTone("WAITING_CUSTOMER", true), "warning");
  assert.equal(complaintTone("ESCALATED_L2", true), "danger");
});

test("complaints use the register API with one idempotency key, and support routes formal complaints there", () => {
  const api = read("src/api/customerFlows.ts");
  assert.match(api, /api<Complaint\[\]>\("\/mobile\/complaints"\)/);
  assert.match(api, /"\/mobile\/complaints", \{ method: "POST", body: JSON\.stringify\(payload\), idempotencyKey \}/);
  assert.match(read("app/complaints/new.tsx"), /useState\(\(\) => Crypto\.randomUUID\(\)\)/);
  const support = read("app/support/new.tsx");
  assert.match(support, /category !== "FORMAL_COMPLAINT"/);
  assert.match(support, /pathname: "\/complaints\/new"/);
  for (const f of ["app/complaints/index.tsx", "app/complaints/new.tsx", "app/complaints/[id].tsx"]) assert.ok(existsSync(new URL(`../${f}`, import.meta.url)), f);
  assert.match(read("app/claim/[id].tsx"), /pathname: "\/complaints\/new", params: \{ claimId: claim\.id/);
  assert.match(read("src/components/policies/PolicyDetailView.tsx"), /pathname: "\/complaints\/new", params: \{ policyId: p\.id/);
});

test("deep links: complaints, refunds and /kyc/* resolve in-app", () => {
  assert.equal(resolveNotificationTarget({ path: "/complaints/0b8a1c2e-0000-4000-8000-000000000001" }), "/complaints/0b8a1c2e-0000-4000-8000-000000000001");
  assert.equal(resolveNotificationTarget({ path: "/refunds/0b8a1c2e-0000-4000-8000-000000000002" }), "/refunds/0b8a1c2e-0000-4000-8000-000000000002");
  assert.equal(resolveNotificationTarget({ path: "/kyc/0b8a1c2e-0000-4000-8000-000000000003" }), "/onboarding/kyc");
  assert.equal(resolveNotificationTarget({ path: "/kyc" }), "/onboarding/kyc");
  assert.equal(resolveNotificationTarget({ path: "/policy/abc" }), "/policy/abc");
  assert.equal(refundIdFromPath("/refunds/0b8a1c2e-0000-4000-8000-000000000002"), "0b8a1c2e-0000-4000-8000-000000000002");
  assert.equal(refundIdFromPath("/payments/x"), null);
  assert.equal(customerTabForRoute("complaints/[id]"), "profile");
  assert.equal(customerTabForRoute("refunds/[id]"), "policies");
});

test("statement periods and balance side", () => {
  const now = new Date(2026, 9, 15);
  assert.deepEqual(statementPeriod("this_month", now), { from: "2026-10-01", to: "2026-10-15" });
  assert.deepEqual(statementPeriod("last_month", now), { from: "2026-09-01", to: "2026-09-30" });
  assert.deepEqual(statementPeriod("this_year", now), { from: "2026-01-01", to: "2026-10-15" });
  assert.equal(balanceSide(5000, "OWED_BY_SUBJECT"), "due");
  assert.equal(balanceSide(-5000, "OWED_BY_SUBJECT"), "credit");
  assert.equal(balanceSide(0, "OWED_BY_SUBJECT"), "zero");
  assert.match(read("src/api/customerFlows.ts"), /\/mobile\/statements\?\$\{statementQuery\(p, true\)\}/);
});

test("instalment attention: overdue first, due within a week, never paid or in-flight lines", () => {
  const now = new Date(2026, 9, 10);
  const rows = [
    { id: "a", number: 1, due_date: "2026-10-05", status: "OVERDUE", outstanding_minor: 100, payable: true, overdue: true },
    { id: "b", number: 2, due_date: "2026-10-14", status: "DUE", outstanding_minor: 100, payable: true },
    { id: "c", number: 3, due_date: "2026-11-30", status: "DUE", outstanding_minor: 100, payable: true },
    { id: "d", number: 4, due_date: "2026-10-12", status: "DUE", outstanding_minor: 100, payable: true, payment_in_progress: true },
    { id: "e", number: 5, due_date: "2026-10-11", status: "PAID", outstanding_minor: 0, payable: false },
  ];
  assert.deepEqual(instalmentAttention(rows, now).map((x) => x.instalment.id), ["a", "b"]);
  assert.equal(instalmentTone({ status: "PAID" }), "success");
  assert.equal(instalmentTone({ status: "OVERDUE", overdue: true }), "warning");
  assert.equal(instalmentTone({ status: "DUE", payment_in_progress: true }), "info");
  // Each payment attempt keeps one key across retries; a new attempt gets a new key.
  const pay = read("app/policy/[id]/instalment-pay.tsx");
  assert.match(pay, /InstalmentsApi\.pay\(id, instalmentId, .*, key\)/);
  assert.match(pay, /setKey\(Crypto\.randomUUID\(\)\)/);
  assert.match(read("src/components/policies/PolicyDetailView.tsx"), /<InstalmentSection policyId=\{p\.id\} \/>/);
});

test("discharge and payout follow the server's allowed actions; payout payload is validated", () => {
  assert.equal(canSignDischarge({ allowed_actions: ["sign_discharge"], discharge: { status: "PENDING" } }), true);
  assert.equal(canSignDischarge({ allowed_actions: [], discharge: { status: "PENDING" } }), false);
  assert.equal(canSignDischarge({ allowed_actions: ["sign_discharge"], discharge: { status: "COMPLETED" } }), false);
  assert.equal(canSetPayout({ allowed_actions: ["set_payout"] }), true);
  assert.equal(normalizeMsisdn("6 71 23 45 67"), "+237671234567");
  assert.equal(normalizeMsisdn("+237 222 23 45 67"), null);
  assert.deepEqual(payoutPayload({ method: "MOBILE_MONEY", operator: "MTN", msisdn: "671234567" }), { method: "MOBILE_MONEY", operator: "MTN", msisdn: "+237671234567" });
  assert.equal(payoutPayload({ method: "MOBILE_MONEY", operator: "MTN", msisdn: "123" }), null);
  assert.deepEqual(payoutPayload({ method: "BANK_TRANSFER", bankName: "Afriland", accountName: "Jude", accountNumber: "cm21 0001 0001 2345" }), {
    method: "BANK_TRANSFER", bank_name: "Afriland", account_name: "Jude", account_number: "CM21000100012345",
  });
  assert.deepEqual(settlementProbeClaims([{ id: "1", status: "APPROVED" }, { id: "2", status: "SUBMITTED" }, { id: "3", status: "PAYMENT_PENDING" }]).map((c) => c.id), ["1", "3"]);
  const api = read("src/api/customerFlows.ts");
  assert.match(api, /settlement\/discharge\/sign`, \{\s*method: "POST",\s*body: JSON\.stringify\(\{ consent_accepted: true \}\)/);
  assert.match(api, /stepUpPurpose: "CLAIM_SETTLEMENT_DECISION"/);
  assert.match(read("app/claim/[id]/settlement.tsx"), /\/claim\/\$\{id\}\/discharge/);
});

test("refund tone, activity labels and every new screen is registered", () => {
  assert.equal(refundTone("PAID"), "success");
  assert.equal(refundTone("REJECTED"), "danger");
  assert.equal(activityKey("claim.settlement.payout_destination_set"), "claim_settlement_payout_destination_set");
  assert.equal(activityFallback("policy.cancellation.requested"), "Policy cancellation requested");
  const layout = read("app/_layout.tsx");
  for (const r of ["complaints/index", "complaints/new", "complaints/\\[id\\]", "payments/statement", "account/activity", "documents/index", "policy/\\[id\\]/instalment-pay", "claim/\\[id\\]/discharge", "refunds/\\[id\\]"])
    assert.match(layout, new RegExp(`name="${r}"`));
});

test("new strings exist in EN and FR", () => {
  for (const k of ["cplTitle", "cplStatus_WAITING_CUSTOMER", "stmTitle", "actTitle", "dhTitle", "instTitle", "instStatus_OVERDUE", "rfListTitle", "dchReviewSign", "payoutTitle"]) {
    assert.equal(typeof en[k], "string", `en ${k}`);
    assert.ok(fr[k] && fr[k] !== en[k] || k === "instPaySubtitle", `fr ${k}`);
  }
});

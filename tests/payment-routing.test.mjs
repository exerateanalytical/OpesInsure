import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  afterDisclosureRoute,
  afterTermsRoute,
  hubForward,
  isPayable,
  nextPurchaseStep,
  payableApplications,
  purchaseRoute,
  readyForTerms,
  termsAcceptedIn,
} from "../src/lib/paymentRouting.ts";
import { resolveNotificationTarget } from "../src/lib/customerLogic.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("payable = APPROVED / PAYMENT_PENDING without an issued policy", () => {
  assert.equal(isPayable("APPROVED"), true);
  assert.equal(isPayable("payment_pending"), true);
  assert.equal(isPayable("APPROVED", "pol-1"), false, "a policy was issued");
  for (const s of ["DRAFT", "DISCLOSURES_PENDING", "DOCUMENTS_PENDING", "UNDER_REVIEW", "COUNTEROFFERED", "DECLINED", "PAID", "ISSUED", "", null, undefined])
    assert.equal(isPayable(s), false, String(s));
});

test("next purchase step: terms first, checkout once the terms were accepted", () => {
  assert.equal(nextPurchaseStep("APPROVED"), "terms");
  assert.equal(nextPurchaseStep("APPROVED", false), "terms");
  assert.equal(nextPurchaseStep("APPROVED", true), "checkout");
  assert.equal(nextPurchaseStep("UNDER_REVIEW", true), null);
  assert.equal(nextPurchaseStep("APPROVED", true, "pol-1"), null);
  assert.deepEqual(purchaseRoute("p1", "terms"), { pathname: "/quote/terms", params: { proposalId: "p1" } });
  assert.deepEqual(purchaseRoute("p1", "checkout", true), { pathname: "/checkout", params: { proposalId: "p1", approved: "1" } });
});

test("terms acceptance comes from the checklist declarations", () => {
  assert.equal(termsAcceptedIn(undefined), false);
  assert.equal(termsAcceptedIn([{ code: "DISCLOSURE_ACCURACY", accepted: true }, { code: "TERMS_ACCEPTANCE", accepted: false }]), false);
  assert.equal(termsAcceptedIn([{ code: "TERMS_ACCEPTANCE", accepted: true }]), true);
});

test("hub forwards to payment once per visit, only when payable", () => {
  const base = { proposalId: "p1", status: "APPROVED", forwarded: false };
  assert.deepEqual(hubForward(base), { pathname: "/quote/terms", params: { proposalId: "p1", approved: "1" } });
  assert.deepEqual(hubForward({ ...base, termsAccepted: true }), { pathname: "/checkout", params: { proposalId: "p1", approved: "1" } });
  assert.equal(hubForward({ ...base, forwarded: true }), null, "coming back with the hardware back never loops");
  assert.equal(hubForward({ ...base, status: "UNDER_REVIEW" }), null);
  assert.equal(hubForward({ ...base, status: "COUNTEROFFERED" }), null);
  assert.equal(hubForward({ ...base, policyId: "pol-1" }), null);
  assert.equal(hubForward({ ...base, proposalId: "" }), null);
});

test("after the disclosure answers: referral, payment when payable, else the hub", () => {
  assert.deepEqual(afterDisclosureRoute("p1", "REFERRED"), { pathname: "/quote/referral", params: { proposalId: "p1" } });
  assert.deepEqual(afterDisclosureRoute("p1", "APPROVED"), { pathname: "/quote/terms", params: { proposalId: "p1", approved: "1" } });
  assert.deepEqual(afterDisclosureRoute("p1", "DOCUMENTS_PENDING"), { pathname: "/proposals/[id]", params: { id: "p1" } });
  assert.deepEqual(afterDisclosureRoute("p1", "UNDER_REVIEW"), { pathname: "/proposals/[id]", params: { id: "p1" } });
});

test("after accepting the terms: checkout when payable, the hub when now under review", () => {
  assert.deepEqual(afterTermsRoute("p1", "APPROVED"), { pathname: "/checkout", params: { proposalId: "p1" } });
  assert.deepEqual(afterTermsRoute("p1", undefined), { pathname: "/checkout", params: { proposalId: "p1" } }, "older servers: unchanged");
  assert.deepEqual(afterTermsRoute("p1", "SUBMITTED"), { pathname: "/proposals/[id]", params: { id: "p1" } });
  assert.deepEqual(afterTermsRoute("p1", "DOCUMENTS_PENDING"), { pathname: "/proposals/[id]", params: { id: "p1" } });
});

test("documents complete: the terms are next (accepting them submits the application)", () => {
  assert.equal(readyForTerms("DOCUMENTS_PENDING", []), true);
  assert.equal(readyForTerms("DOCUMENTS_PENDING", ["DECLARATION_REQUIRED:TERMS_ACCEPTANCE"]), true);
  assert.equal(readyForTerms("DOCUMENTS_PENDING", ["DOCUMENT_MISSING:ID_CARD"]), false);
  assert.equal(readyForTerms("DOCUMENTS_PENDING", ["KYC_REQUIRED:NOT_VERIFIED"]), false);
  assert.equal(readyForTerms("DOCUMENTS_PENDING", ["DECLARATION_REQUIRED:MEDICAL_CONSENT"]), false);
  assert.equal(readyForTerms("DOCUMENTS_PENDING", undefined), false, "unknown checklist: keep the refresh");
  assert.equal(readyForTerms("UNDER_REVIEW", []), false);
  const hub = read("app/proposals/[id].tsx");
  assert.match(hub, /readyForTerms\(p\.status, checklist\?\.blocking\)/);
  assert.match(hub, /completedByUpload\.current = true/);
});

test("Home: payable applications without a payment attempt of their own, newest first", () => {
  const proposals = [
    { id: "old", status: "APPROVED", updated_at: "2026-09-20T10:00:00Z" },
    { id: "new", status: "PAYMENT_PENDING", updated_at: "2026-09-28T10:00:00Z" },
    { id: "review", status: "UNDER_REVIEW", updated_at: "2026-09-29T10:00:00Z" },
    { id: "issued", status: "APPROVED", policy_id: "pol", updated_at: "2026-09-29T10:00:00Z" },
    { id: "failed", status: "APPROVED", updated_at: "2026-09-29T10:00:00Z" },
    { id: "waiting", status: "APPROVED", updated_at: "2026-09-29T10:00:00Z" },
    { id: "retry", status: "APPROVED", updated_at: "2026-09-10T10:00:00Z" },
  ];
  const payments = [
    { id: "x1", proposal_id: "failed", status: "FAILED", created_at: "2026-09-29T11:00:00Z" },
    { id: "x2", proposal_id: "waiting", status: "PENDING_CUSTOMER", created_at: "2026-09-29T11:00:00Z" },
    // A cancelled attempt leaves the application payable again.
    { id: "x3", proposal_id: "retry", status: "CANCELLED", created_at: "2026-09-29T11:00:00Z" },
  ];
  assert.deepEqual(payableApplications(proposals, payments).map((p) => p.id), ["new", "old", "retry"]);
  assert.deepEqual(payableApplications(null, null), []);
});

test("approval notifications open the application, which forwards to payment", () => {
  // Backend LifecycleNotificationProducer: proposal_approved -> path /proposals/{id}.
  assert.equal(resolveNotificationTarget({ path: "/proposals/abc", code: "proposal_approved" }), "/proposals/abc");
  const hub = read("app/proposals/[id].tsx");
  assert.match(hub, /hubForward\(/);
  assert.match(hub, /useFocusEffect/, "only forwards while the hub is in front");
  assert.match(hub, /forwarded\.current = true/);
});

test("screens use the routing helpers", () => {
  assert.match(read("app/quote/questions.tsx"), /afterDisclosureRoute\(proposalId, x\.status\)/);
  assert.match(read("app/quote/terms.tsx"), /afterTermsRoute\(proposalId, res\?\.status\)/);
  assert.match(read("app/(customer)/(tabs)/index.tsx"), /payableApplications\(/);
  assert.match(read("src/components/customer/PriorityFeed.tsx"), /purchaseRoute\(a\.id, "terms"\)/);
  assert.match(read("src/components/customer/HomeSections.tsx"), /item\.kind === "application"/);
});

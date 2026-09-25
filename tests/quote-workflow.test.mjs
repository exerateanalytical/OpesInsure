import test from "node:test";
import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import {
  buildCarrierOffer,
  canDeclineQuote,
  canResubmitProposal,
  canWithdrawProposal,
  catalogueName,
  commissionPercent,
  comparisonRows,
  groupCatalogue,
  hasQuoteDocument,
  isAnswersLocked,
  QUOTE_DECLINE_REASONS,
  quoteOutcome,
  quoteStateKey,
  quoteTone,
  requiredDocumentInfo,
  sentToInsurer,
  slaState,
  toMinor,
} from "../src/lib/quoteWorkflow.ts";
import { API_ERROR_COPY } from "../src/lib/apiErrors.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const NOW = Date.parse("2026-09-25T10:00:00Z");
const en = read("src/i18n/en.ts");
const fr = read("src/i18n/fr.ts");
const hasKey = (src, key) => new RegExp(`^\\s+${key}:`, "m").test(src);

test("quote lifecycle: DECLINED and EXPIRED are terminal outcomes; decline only while open", () => {
  assert.equal(quoteOutcome({ status: "DECLINED", lifecycle_state: "DECLINED" }, NOW), "DECLINED");
  assert.equal(quoteOutcome({ status: "EXPIRED" }, NOW), "EXPIRED");
  assert.equal(quoteOutcome({ status: "OFFERED", lifecycle_state: "SENT", expires_at: "2026-09-01T00:00:00Z" }, NOW), "EXPIRED");
  assert.equal(quoteOutcome({ status: "OFFERED", lifecycle_state: "SENT", expires_at: "2026-10-01T00:00:00Z" }, NOW), null);
  assert.equal(quoteTone({ lifecycle_state: "DECLINED" }, NOW), "danger");
  assert.equal(quoteTone({ lifecycle_state: "REFERRED" }, NOW), "warning");
  assert.equal(quoteStateKey({ lifecycle_state: "GENERATED", status: "OFFERED" }, NOW), "quoteLifecycle_GENERATED");
  assert.equal(quoteStateKey({ lifecycle_state: "SENT", expires_at: "2026-01-01T00:00:00Z" }, NOW), "quoteLifecycle_EXPIRED");
  for (const s of ["CALCULATED", "GENERATED", "SENT", "VIEWED", "REFERRED"]) assert.ok(canDeclineQuote({ lifecycle_state: s }, NOW), s);
  for (const s of ["DRAFT", "RATING", "ACCEPTED", "DECLINED", "EXPIRED", "CANCELLED"]) assert.ok(!canDeclineQuote({ lifecycle_state: s }, NOW), s);
  assert.deepEqual([...QUOTE_DECLINE_REASONS], ["CUSTOMER_DECLINED", "PRICE_TOO_HIGH", "COVER_NOT_SUITABLE", "LOST_TO_COMPETITOR", "NO_RESPONSE", "DUPLICATE", "OTHER"]);
});

test("quote PDF is offered only in the states where GET quotes/{q}/document answers", () => {
  for (const s of ["GENERATED", "SENT", "VIEWED", "ACCEPTED"]) assert.ok(hasQuoteDocument({ lifecycle_state: s }), s);
  for (const s of ["DRAFT", "CALCULATED", "REFERRED", "DECLINED", "EXPIRED", null]) assert.ok(!hasQuoteDocument({ lifecycle_state: s }), String(s));
});

test("sent-to-insurer summary counts open, offered and declined carrier requests", () => {
  assert.equal(sentToInsurer([]), null);
  assert.equal(sentToInsurer([{ status: "CANCELLED" }]), null);
  const s = sentToInsurer([
    { status: "REQUESTED", response_due_at: "2026-09-27T10:00:00Z" },
    { status: "IN_PROGRESS", response_due_at: "2026-09-26T10:00:00Z" },
    { status: "OFFERED" },
    { status: "DECLINED" },
  ]);
  assert.deepEqual(s, { waiting: 2, offered: 1, declined: 1, total: 4, nextDueAt: "2026-09-26T10:00:00Z" });
});

test("carrier SLA: on track, due soon, breached, answered", () => {
  assert.deepEqual(slaState({ status: "REQUESTED", response_due_at: "2026-09-26T10:00:00Z" }, NOW), { state: "on_track", hoursLeft: 24 });
  assert.equal(slaState({ status: "IN_PROGRESS", response_due_at: "2026-09-25T12:00:00Z" }, NOW).state, "due_soon");
  assert.equal(slaState({ status: "REQUESTED", response_due_at: "2026-09-25T09:00:00Z" }, NOW).state, "breached");
  assert.equal(slaState({ status: "REQUESTED", response_due_at: "2026-09-30T09:00:00Z", sla: [{ breached_at: "x" }] }, NOW).state, "breached");
  assert.equal(slaState({ status: "OFFERED", responded_at: "2026-09-24T09:00:00Z" }, NOW).state, "met");
  assert.equal(slaState({ status: "REQUESTED" }, NOW).state, "none");
});

test("carrier offer: total = premium + tax + fees and the breakdown must sum to it", () => {
  assert.equal(toMinor("125 000"), 12_500_000);
  assert.equal(toMinor("12,5"), 1250);
  assert.equal(toMinor("abc"), null);
  const base = { premium: "100000", tax: "19250", fee: "5000", validUntil: "2026-10-31", lines: [], conditions: ["Garage parking", " "] };
  const ok = buildCarrierOffer(base, NOW);
  assert.equal(ok.ok, true);
  assert.equal(ok.total, 12_425_000);
  assert.deepEqual(ok.body.conditions, [{ text: "Garage parking" }]);
  assert.equal(ok.body.total_minor, ok.body.premium_minor + ok.body.tax_minor + ok.body.fee_minor);
  assert.equal(ok.body.premium_breakdown, undefined);

  const lines = [
    { code: "", label: "Base premium", amount: "100000" },
    { code: "", label: "Tax", amount: "19250" },
    { code: "FEES", label: "", amount: "5000" },
  ];
  const summed = buildCarrierOffer({ ...base, lines }, NOW);
  assert.equal(summed.ok, true);
  assert.deepEqual(summed.body.premium_breakdown.map((l) => [l.code, l.amount_minor]), [["BASE_PREMIUM", 10_000_000], ["TAX", 1_925_000], ["FEES", 500_000]]);

  const off = buildCarrierOffer({ ...base, lines: lines.slice(0, 2) }, NOW);
  assert.deepEqual([off.ok, off.error, off.linesSum, off.total], [false, "breakdown", 11_925_000, 12_425_000]);
  assert.equal(buildCarrierOffer({ ...base, premium: "" }, NOW).error, "premium");
  assert.equal(buildCarrierOffer({ ...base, tax: "x" }, NOW).error, "tax");
  assert.equal(buildCarrierOffer({ ...base, validUntil: "2026-09-01" }, NOW).error, "validity");
  assert.equal(buildCarrierOffer({ ...base, lines: [{ code: "", label: "", amount: "5" }] }, NOW).error, "line");
  // Server 422 codes arrive localized.
  assert.equal(API_ERROR_COPY.PREMIUM_INCONSISTENT, "errPremiumInconsistent");
  assert.equal(API_ERROR_COPY.BREAKDOWN_INCONSISTENT, "errBreakdownInconsistent");
  assert.equal(API_ERROR_COPY.VALIDITY_IN_PAST, "errValidityInPast");
});

test("comparison rows cover premium, tax, fees, total, limits, deductibles and exclusions", () => {
  const cmp = {
    id: "c1",
    quote_id: "q1",
    lowest_total_offer_id: "o2",
    offers: [
      { offer_id: "o1", carrier: "Alpha", product: "Auto+", premium_minor: 100, tax_minor: 19, fee_minor: 5, total_minor: 124 },
      { offer_id: "o2", carrier: "Beta", product: { en: "Motor", fr: "Auto" }, premium_minor: 90, tax_minor: 17, fee_minor: 5, total_minor: 112 },
    ],
    coverages: [{ code: "TPL", name: "Third party", by_offer: [{ offer_id: "o1", included: true, limit_minor: 5000, deductible_minor: 100 }, { offer_id: "o2", included: false }] }],
    exclusions: [{ code: "RACING", name: "Racing", by_offer: [{ offer_id: "o1", applies: true }, { offer_id: "o2", applies: false }] }],
  };
  const rows = comparisonRows(cmp, "fr", { included: "Inclus", notIncluded: "Non inclus", applies: "Exclu", none: "—" });
  assert.deepEqual(rows.map((r) => r.key), ["insurer", "premium_minor", "tax_minor", "fee_minor", "total_minor", "limit:TPL", "deductible:TPL", "exclusion:RACING"]);
  assert.equal(rows[0].cells[1].text, "Beta · Auto");
  assert.deepEqual(rows[4].cells.map((c) => c.best), [false, true]);
  assert.deepEqual(rows[5].cells, [{ minor: 5000 }, { text: "Non inclus" }]);
  assert.deepEqual(rows[6].cells, [{ minor: 100 }, { text: "Non inclus" }]);
  assert.deepEqual(rows[7].cells.map((c) => c.text), ["Exclu", "—"]);
});

test("proposal lifecycle: document statuses, withdraw, resubmit and the answers-locked 422", () => {
  assert.deepEqual(requiredDocumentInfo("ACCEPTED"), { key: "propDoc_ACCEPTED", tone: "success", done: true });
  assert.equal(requiredDocumentInfo("REJECTED").tone, "danger");
  assert.equal(requiredDocumentInfo(null).key, "propDoc_MISSING");
  assert.equal(requiredDocumentInfo("REVIEWING").done, true);
  assert.ok(canWithdrawProposal("UNDER_REVIEW"));
  assert.ok(!canWithdrawProposal("ISSUED"));
  assert.ok(!canWithdrawProposal("UNDER_REVIEW", ["approve", "decline"]));
  assert.ok(canWithdrawProposal("PAID", ["withdraw"]));
  assert.ok(canResubmitProposal("INFORMATION_REQUIRED", ["resubmit", "withdraw"], []));
  assert.ok(!canResubmitProposal("INFORMATION_REQUIRED", [], ["DOCUMENT_MISSING:ID"]));
  assert.ok(!canResubmitProposal("UNDER_REVIEW"));
  assert.ok(isAnswersLocked({ status: 422, fields: { status: ["locked"] } }));
  assert.ok(!isAnswersLocked({ status: 422, fields: { answers: ["bad"] } }));
  assert.ok(!isAnswersLocked({ status: 409, fields: { status: ["x"] } }));
});

test("distribution catalogue groups by line, sellable first", () => {
  const groups = groupCatalogue([
    { product_id: "p3", name: "Zeta", line_code: "motor", sellable: false },
    { product_id: "p1", name: { en: "Home", fr: "Habitation" }, line_code: "HOME", sellable: true },
    { product_id: "p2", name: "Alpha", line_code: "MOTOR", sellable: true },
  ]);
  assert.deepEqual(groups.map((g) => [g.line, g.items.map((i) => i.product_id)]), [["HOME", ["p1"]], ["MOTOR", ["p2", "p3"]]]);
  assert.equal(catalogueName({ product_id: "p1", name: { en: "Home", fr: "Habitation" }, sellable: true }, "fr"), "Habitation");
  assert.equal(commissionPercent(1250), "12.5 %");
  assert.equal(commissionPercent(1000), "10 %");
  assert.equal(commissionPercent(null), null);
});

test("Batch 6 screens exist, are registered behind the right guards and call the documented endpoints", () => {
  const layout = read("app/_layout.tsx");
  for (const route of ["quote-comparison/[id]", "proposals/[id]/information", "agent/quotes/[id]", "agent/catalogue", "broker/quotes/[id]", "broker/catalogue", "carrier/quote-requests/index", "carrier/quote-requests/[id]"]) {
    assert.ok(layout.includes(`name="${route}"`), route);
    assert.ok(existsSync(new URL(`../app/${route}.tsx`, import.meta.url)), route);
  }
  const carrierBlock = layout.slice(layout.indexOf("guard={carrier}"), layout.indexOf("guard={partner}"));
  assert.ok(carrierBlock.includes("carrier/quote-requests/[id]"));
  const api = read("src/api/workflow.ts");
  for (const path of ["/quotes/${id}/decline", "/quotes/${id}/document", "/quote-comparisons", "/quotes/${quoteId}/carrier-requests", "/carrier/quote-requests/${id}/start", "/carrier/quote-requests/${id}/offer", "/carrier/quote-requests/${id}/decline", "/proposals/${id}/checklist", "/proposals/${id}/resubmit", "/proposals/${id}/withdraw", "/distribution/catalogue"]) {
    assert.ok(api.includes(path), path);
  }
  assert.match(read("app/quotes/[id].tsx"), /QuoteWorkflowPanel/);
  assert.match(read("src/components/offers/PartnerQuoteScreen.tsx"), /QuoteWorkflowPanel/);
  assert.match(read("app/quote/questions.tsx"), /isAnswersLocked\(e\)/);
  assert.match(read("app/proposals/[id].tsx"), /ProposalLifecycleApi\.withdraw/);
  // One comparison table for both the offer screen and the saved comparison.
  assert.match(read("app/quote/compare.tsx"), /<CompareTable/);
  assert.match(read("app/quote-comparison/[id].tsx"), /<CompareTable/);
});

test("Batch 6 copy exists in English and French", () => {
  const keys = [
    "quoteStatus_DECLINED", "quoteLifecycle_DECLINED", "quoteLifecycle_EXPIRED", "qwNumber", "qwDecline", "qwDocumentOpen", "qwCompareTitle",
    "qwSentTitle", "cqrTitle", "cqrSendOffer", "cqrErr_breakdown", "proposalStatus_INFORMATION_REQUIRED", "proposalStatus_RESUBMITTED",
    "proposalMsg_INFORMATION_REQUIRED", "proposalMsg_RESUBMITTED", "prInfoResubmit", "prWithdraw", "prAnswersLocked", "catTitle",
    "errPremiumInconsistent", "errBreakdownInconsistent", "errValidityInPast",
    ...QUOTE_DECLINE_REASONS.map((r) => `qwDeclineReason_${r}`),
  ];
  for (const k of keys) {
    assert.ok(hasKey(en, k), `en ${k}`);
    assert.ok(hasKey(fr, k), `fr ${k}`);
  }
});

test("Batch 6 stays JS-only: no native config or dependency changes needed", () => {
  const pkg = JSON.parse(read("package.json"));
  assert.equal(pkg.version, "1.3.0");
  for (const dep of ["react-native-webview", "expo-file-system", "expo-sharing", "expo-print"]) assert.equal(pkg.dependencies[dep], undefined, dep);
});

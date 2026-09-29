// Compare-offers flow fixes: choosable offers, compare selection, accepted offer / application,
// amount filters, cover period, "Edit quote" routing and prefill, the one comparison table, and the
// screens wired to them (quoteId params, replace navigation, no duplicate compare screen).
import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { copyText, insurerSummary, liveOffers, offerBlock, paymentMethodLabel } from "../src/lib/purchase.ts";
import {
  acceptedOfferId,
  amountFilter,
  amountFilterCount,
  amountInvalid,
  compareAllIds,
  editQuotePlan,
  MAX_COMPARE,
  offerChoice,
  offerPeriod,
  parseIds,
  periodCopy,
  proposalForOffer,
  proposalQuoteId,
  pruneCompareIds,
  toggleCompareId,
} from "../src/lib/offerChoice.ts";
import { comparisonFromOffers, comparisonTable } from "../src/lib/offerComparison.ts";
import { factsToValues, insuredFromFacts, buildFacts, localRiskSchema } from "../src/lib/riskSchema.ts";
import { resolveSystemPath } from "../src/lib/navigationContinuity.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const NOW = Date.parse("2026-09-29T10:00:00Z");
const FUTURE = "2026-10-05T10:00:00Z";
const PAST = "2026-09-28T10:00:00Z";

const offer = (id, total, extra = {}) => ({
  id,
  quote_id: "q1",
  carrier_id: `c${id}`,
  total_minor: total,
  premium_minor: total - 20,
  tax_minor: 15,
  fee_minor: 5,
  valid_until: FUTURE,
  status: "OFFERED",
  coverage_snapshot: { coverages: [{ code: "TPL", name: "Third party", mandatory: true, limit_minor: 1000 * total, deductible_minor: 50 }] },
  carrier: { party: { display_name: `Insurer ${id}` } },
  ...extra,
});

test("selectability: status and validity decide; live offers drive cheapest / from prices", () => {
  assert.equal(offerBlock(offer("a", 1), NOW), null);
  assert.equal(offerBlock(offer("a", 1, { valid_until: PAST }), NOW), "expired");
  assert.equal(offerBlock(offer("a", 1, { status: "DECLINED" }), NOW), "declined");
  assert.equal(offerBlock(offer("a", 1, { status: "WITHDRAWN" }), NOW), "withdrawn");
  assert.equal(offerBlock(offer("a", 1, { status: "SUPERSEDED" }), NOW), "superseded");
  assert.equal(offerBlock(offer("a", 1, { status: "ACCEPTED" }), NOW), "accepted");
  const list = [offer("1", 300), offer("2", 100, { valid_until: PAST }), offer("3", 200, { carrier_id: "c1" })];
  assert.deepEqual(liveOffers(list, NOW).map((o) => o.id), ["1", "3"]);
  // The expired 100 offer never becomes an insurer's "from" price.
  const summary = insurerSummary(list, "fr", NOW);
  assert.deepEqual(summary.map((s) => [s.carrierId, s.cheapest?.id ?? null]), [["c1", "3"], ["c2", null]]);
});

test("one choose rule for cards and columns: outcome, accepted offer, expiry", () => {
  const o = offer("1", 100);
  assert.deepEqual(offerChoice(o, { now: NOW }), { kind: "select" });
  assert.deepEqual(offerChoice(o, { acceptedId: "1", now: NOW }), { kind: "selected" });
  assert.deepEqual(offerChoice(o, { acceptedId: "2", now: NOW }), { kind: "blocked", reason: "other_chosen" });
  assert.deepEqual(offerChoice(o, { outcome: "EXPIRED", now: NOW }), { kind: "blocked", reason: "expired" });
  assert.deepEqual(offerChoice(o, { outcome: "DECLINED", now: NOW }), { kind: "blocked", reason: "unavailable" });
  assert.deepEqual(offerChoice(offer("1", 1, { valid_until: PAST }), { now: NOW }), { kind: "blocked", reason: "expired" });
  assert.equal(acceptedOfferId([offer("1", 1), offer("2", 2, { status: "ACCEPTED" })]), "2");
  assert.equal(acceptedOfferId([offer("1", 1)]), null);
});

test("compare selection: max three with feedback, pruned to visible choosable offers, compare-all takes the cheapest three", () => {
  assert.equal(MAX_COMPARE, 3);
  let r = toggleCompareId([], "a");
  r = toggleCompareId(r.ids, "b");
  r = toggleCompareId(r.ids, "c");
  const full = toggleCompareId(r.ids, "d");
  assert.deepEqual(full, { ids: ["a", "b", "c"], limited: true });
  assert.deepEqual(toggleCompareId(full.ids, "b"), { ids: ["a", "c"], limited: false });
  const visible = [offer("a", 1), offer("b", 2, { valid_until: PAST }), offer("c", 3)];
  assert.deepEqual(pruneCompareIds(["a", "b", "x", "c"], visible, NOW), ["a", "c"]);
  const same = ["a", "c"];
  assert.equal(pruneCompareIds(same, visible, NOW), same, "unchanged selection keeps its identity (no effect loop)");
  const many = [offer("1", 500), offer("2", 100), offer("3", 300, { status: "DECLINED" }), offer("4", 200), offer("5", 400)];
  assert.deepEqual(compareAllIds(many, NOW), ["2", "4", "5"]);
  assert.deepEqual(parseIds("a,b,,a, c"), ["a", "b", "c"]);
});

test("applications: the one opened for an offer, and the quote an application came from", () => {
  const rows = [
    { id: "p0", status: "WITHDRAWN", quote_offer_id: "o1" },
    { id: "p1", status: "DRAFT", quote_offer_id: "o1", quote_id: "q9" },
    { id: "p2", status: "DRAFT", terms_snapshot: { offer_id: "o2", quote_id: "q2" } },
  ];
  assert.equal(proposalForOffer(rows, "o1"), "p1");
  assert.equal(proposalForOffer(rows, "o2"), "p2");
  assert.equal(proposalForOffer(rows, "o3"), null);
  assert.equal(proposalQuoteId(rows[1]), "q9");
  assert.equal(proposalQuoteId(rows[2]), "q2");
  assert.equal(proposalQuoteId({ id: "p", offer: { quote_id: "q3" } }), "q3");
  assert.equal(proposalQuoteId({ id: "p", offer: { quote: { id: "q4" } } }), "q4");
  assert.equal(proposalQuoteId({ id: "p" }), null);
});

test("amount filters are part of the sheet draft and parse FR/EN amounts", () => {
  const v = { minTotal: ["12,5"], maxTotal: ["250 000"], maxExcess: ["abc"] };
  assert.deepEqual(amountFilter(v), { minPremiumMinor: 1250, maxPremiumMinor: 25_000_000, maxExcessMinor: null });
  assert.equal(amountFilterCount(v), 2);
  assert.equal(amountInvalid("abc"), true);
  assert.equal(amountInvalid(""), false);
  assert.equal(amountInvalid("1.000,50"), false);
  assert.deepEqual(amountFilter({}), { minPremiumMinor: null, maxPremiumMinor: null, maxExcessMinor: null });
});

test("cover period only when the payload states one (no assumed 'per year')", () => {
  assert.equal(offerPeriod(offer("1", 1)), null);
  assert.equal(periodCopy(null), null);
  assert.deepEqual(periodCopy(offerPeriod({ coverage_snapshot: { cover_period: "ANNUAL" } })), { key: "ofPeriodPerYear", vars: {} });
  assert.deepEqual(periodCopy(offerPeriod({ coverage_snapshot: { term_months: 12 } })), { key: "ofPeriodPerYear", vars: {} });
  assert.deepEqual(periodCopy(offerPeriod({ product: { duration: { unit: "month", value: 6 } } })), { key: "ofPeriodMonths", vars: { count: 6 } });
  assert.deepEqual(periodCopy(offerPeriod({ coverage_snapshot: { coverage_period: "P30D" } })), { key: "ofPeriodDays", vars: { count: 30 } });
  for (const key of ["ofPeriodPerYear", "ofPeriodPerMonth", "ofPeriodYears", "ofPeriodMonths", "ofPeriodWeeks", "ofPeriodDays"]) assert.notEqual(copyText("fr", key), key, key);
});

test("Edit quote: back to the risk form when it is right below, else dismiss the flow and replace with a prefilled form", () => {
  assert.deepEqual(editQuotePlan(["(customer)", "quote/product", "quote/risk", "quote/offers"], 3), { dismiss: 1, action: "back" });
  assert.deepEqual(editQuotePlan(["(customer)", "quote/product", "quote/risk", "quote/offers", "quote/compare"], 4), { dismiss: 2, action: "back" });
  assert.deepEqual(editQuotePlan(["(customer)", "quotes/[id]", "quote/offers"], 2), { dismiss: 0, action: "replace" });
  assert.deepEqual(editQuotePlan(["(customer)", "quotes/[id]", "quote/offers", "quote/compare"], 3), { dismiss: 1, action: "replace" });
});

test("the saved quote's facts prefill the risk form (inverse of buildFacts)", () => {
  const schema = localRiskSchema("MOTOR");
  const values = {
    make_code: "TOYOTA", make: "Toyota", year: "2019", registration_number: "LT 123 AB", fiscal_power: "7", zone: "DOUALA",
    vehicle_value_minor: "4500000", previously_insured: "true", start_date: "2026-10-01", cover_type: "COMPREHENSIVE",
  };
  const facts = buildFacts(schema, values);
  assert.equal(facts.vehicle_value_minor, 450000000);
  const back = factsToValues(schema, facts);
  for (const [k, v] of Object.entries(values)) assert.equal(back[k], v, k);
  // Rebuilding from the prefilled form gives the same facts (resubmitting an unchanged form changes nothing).
  assert.deepEqual(buildFacts(schema, back), facts);
  assert.deepEqual(insuredFromFacts({ insured_person: { relationship: "SELF" } }), { mode: "self" });
  assert.deepEqual(insuredFromFacts({ insured_person: { relationship: "CHILD", full_name: "Ada", date_of_birth: "2010-01-02" } }), { mode: "other", full_name: "Ada", date_of_birth: "2010-01-02", relationship: "CHILD" });
  assert.deepEqual(insuredFromFacts(null), { mode: "self" });
});

test("one comparison table: server or device source, validity row, expired columns never highlighted, sort order", () => {
  const offers = [offer("1", 300), offer("2", 100, { valid_until: PAST }), offer("3", 200)];
  const local = comparisonFromOffers("q1", offers, "en");
  assert.deepEqual(local.offers.map((o) => o.offer_id), ["1", "2", "3"]);
  assert.equal(local.coverages[0].by_offer[0].limit_minor, 300000);
  const { columns, rows } = comparisonTable(local, { language: "en", now: NOW, order: ["3", "1", "2"], offers, formatDate: (iso) => `D:${iso.slice(0, 10)}` });
  assert.deepEqual(columns.map((c) => c.offerId), ["3", "1", "2"]);
  assert.deepEqual(columns.map((c) => c.block), [null, null, "expired"]);
  const total = rows.find((r) => r.key === "total");
  // The expired 100 total is cheaper but cannot be chosen: 200 is the best live total.
  assert.deepEqual(total.cells.map((c) => c.best), [true, false, false]);
  const validity = rows.find((r) => r.key === "valid_until");
  assert.deepEqual(validity.cells.map((c) => c.text), ["D:2026-10-05", "D:2026-10-05", copyText("en", "offerExpired")]);
  assert.equal(validity.cells[2].tone, "danger");
  assert.ok(!rows.some((r) => r.key === "period"), "no cover period row when no offer states one");
  assert.ok(!rows.some((r) => r.key === "cover_start"));
  const dated = comparisonTable(local, { now: NOW, offers, coverStart: "2026-10-01", formatDate: (iso) => `D:${iso}` }).rows.find((r) => r.key === "cover_start");
  assert.deepEqual(dated.cells.map((c) => c.text), ["D:2026-10-01", "D:2026-10-01", "D:2026-10-01"]);
  // Headings are separate rows; labels use the same catalogue keys as the offer card.
  assert.deepEqual(rows.filter((r) => r.heading).map((r) => r.key), ["section:limits", "section:deductibles"]);
  assert.equal(rows.find((r) => r.key === "total").label, copyText("en", "sumTotalPayable"));
  // A status fresh from the store (just accepted) wins over the saved comparison's.
  const accepted = comparisonTable(local, { now: NOW, offers: offers.map((o) => (o.id === "1" ? { ...o, status: "ACCEPTED" } : o)) });
  assert.equal(accepted.columns[0].block, "accepted");
  assert.equal(paymentMethodLabel("ORANGE_MONEY", "fr"), "Orange Money");
  assert.equal(paymentMethodLabel("BANK_TRANSFER", "fr"), copyText("fr", "payMethod_BANK_TRANSFER"));
});

test("offers and comparison reload their quote by id and are allowed deep links", () => {
  assert.equal(resolveSystemPath("/quote/offers?quoteId=q1", false), "/quote/offers?quoteId=q1");
  assert.equal(resolveSystemPath("https://insurance.opesdatacenter.tech/app/quote/compare?quoteId=q1&ids=a,b", false), "/quote/compare?quoteId=q1&ids=a,b");
  assert.equal(resolveSystemPath("/quote/risk", false), "");
  const offers = read("app/quote/offers.tsx");
  const compare = read("app/quote/compare.tsx");
  for (const src of [offers, compare]) {
    assert.match(src, /useOpenQuote\(quoteId\)/);
    assert.match(src, /useChooseOffer\(/);
    assert.match(src, /useEditQuote\(/);
    assert.doesNotMatch(src, /getState\(\)\.proposal/);
    assert.doesNotMatch(src, /router\.push\("\/quote\/risk"\)/);
  }
  assert.match(offers, /pruneCompareIds\(c, visible, now\)/);
  assert.doesNotMatch(offers, /ChevronRight/, "insurer summary rows are informational");
  assert.match(read("src/hooks/useQuoteFlow.ts"), /router\.replace\(\{ pathname: "\/proposals\/\[id\]"/);
  // Risk: prefilled edit, amend, and replace (not push) to the offers of that quote.
  const risk = read("app/quote/risk.tsx");
  assert.match(risk, /factsToValues\(schema, editQuote\.risk_facts\)/);
  assert.match(risk, /router\.replace\(\{ pathname: "\/quote\/offers", params: \{ quoteId: result\.quote\.id \} \}\)/);
  assert.match(read("src/store/insurance.ts"), /QuoteWorkflowApi\.amend\(opts\.amendQuoteId/);
  // Every entry point passes the quote id.
  assert.match(read("app/quotes/[id].tsx"), /pathname: "\/quote\/offers", params: \{ quoteId/);
  assert.match(read("app/quote/referral.tsx"), /pathname: "\/quote\/offers", params: \{ quoteId: r\.quote\.id \}/);
  assert.match(read("app/checkout.tsx"), /proposalQuoteId\(proposal\)/);
  assert.match(read("app/proposals/[id].tsx"), /proposalQuoteId\(p\)/);
  assert.doesNotMatch(read("app/search.tsx"), /"\/quote\/compare"/);
  // Quotes list: Compare only with two offers and no outcome.
  assert.match(read("app/quotes/index.tsx"), /\(q\.offer_count \?\? 0\) >= 2 && !outcome/);
});

test("store: selectOffer returns the application and a new quote drops the previous quote's purchase state", () => {
  const store = read("src/store/insurance.ts");
  assert.match(store, /selectOffer: \(v: QuoteOffer\) => Promise<Proposal>;/);
  assert.match(store, /return proposal;/);
  assert.match(store, /throw new Error\(say\("insBusy"\)\)/);
  assert.match(store, /const QUOTE_SCOPED = \{ selectedOffer: null, proposal: null, payment: null, purchase: null, accepted: null \}/);
  assert.match(store, /get\(\)\.quote\?\.id === quote\.id \? \{\} : QUOTE_SCOPED/);
  assert.doesNotMatch(store, /message\(e, "[A-Z]/, "store fallbacks are catalogue keys, not English text");
  for (const k of ["insQuoteLoadFailed", "insQuoteUnavailable", "insRerateFailed", "insSelectFailed", "insBusy", "ofSelectedOpen", "ofCompareMax", "cmpRowValidUntil", "rfOutcome_EXPIRED", "payMethod_CARD"]) {
    assert.match(read("src/i18n/en.ts"), new RegExp(`^  ${k}: `, "m"), `en ${k}`);
    assert.match(read("src/i18n/fr.ts"), new RegExp(`^  ${k}: `, "m"), `fr ${k}`);
  }
  assert.match(read("src/i18n/en.ts"), /ofSubtitle: "Step 3 of 4/);
});

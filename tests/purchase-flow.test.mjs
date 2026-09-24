import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
// Pure helpers are dependency-free TypeScript, loaded with Node's type stripping.
import {
  compareRows,
  filterOffers,
  isProviderNotConfigured,
  normalizeCoverage,
  paymentIdempotencyKey,
  policyStatusInfo,
  proposalStatusInfo,
  providerErrorMessage,
  purchaseStep,
  receiptView,
  refundPayload,
  sortOffers,
  unwrapPage,
  validityLeft,
  openableUrl,
} from "../src/lib/purchase.ts";
import { buildFacts, localRiskSchema, normalizeRiskSchema, validateStep } from "../src/lib/riskSchema.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("list payloads unwrap in every shape without crashing", () => {
  const rows = [{ id: "a" }, { id: "b" }];
  assert.deepEqual(unwrapPage(rows).items, rows);
  // new top-level envelope
  const env = unwrapPage({ data: rows, meta: { current_page: 1, last_page: 3, total: 50 } });
  assert.deepEqual(env.items, rows);
  assert.equal(env.info.hasMore, true);
  // old paginator nested under data (what crashed .map before)
  const old = unwrapPage({ data: { data: rows, current_page: 2, last_page: 2, total: 22 } });
  assert.deepEqual(old.items, rows);
  assert.equal(old.info.hasMore, false);
  assert.equal(old.info.page, 2);
  // bare paginator (api() already stripped the outer data)
  assert.equal(unwrapPage({ data: rows, current_page: 1, last_page: 2 }).info.hasMore, true);
  for (const junk of [null, undefined, "x", 5, {}, { data: null }]) assert.deepEqual(unwrapPage(junk).items, []);
});

test("coverage snapshots normalize localized names, limits and excess", () => {
  const n = normalizeCoverage(
    {
      coverages: [
        { code: "TP", name: { en: "Third party", fr: "RC" }, mandatory: true, limit_minor: 100, deductible_minor: 10 },
        { code: "OD", name: "Own damage", mandatory: false, optional: true, limit_minor: 50, deductible_minor: 25 },
      ],
      exclusions: [{ code: "DUI", name: { en: "Drunk driving" } }],
      policy_wording_url: "https://example.test/w.pdf",
    },
    "fr",
  );
  assert.equal(n.coverages[0].name, "RC");
  assert.equal(n.excessMinor, 25);
  assert.equal(n.coverTotalMinor, 100);
  assert.equal(n.exclusions[0].name, "Drunk driving");
  assert.equal(n.documents[0].url, "https://example.test/w.pdf");
  assert.deepEqual(normalizeCoverage(null).coverages, []);
});

const offer = (id, carrier, total, limit, deductible) => ({
  id,
  carrier_id: carrier,
  total_minor: total,
  premium_minor: total - 10,
  tax_minor: 5,
  fee_minor: 5,
  valid_until: "2099-01-01T00:00:00Z",
  coverage_snapshot: { coverages: [{ code: "A", name: "A", mandatory: true, limit_minor: limit, deductible_minor: deductible }] },
  carrier: { party: { display_name: carrier.toUpperCase() } },
});

test("offers sort by price/cover and filter by provider, premium and excess", () => {
  const list = [offer("1", "x", 300, 1000, 50), offer("2", "y", 100, 500, 10), offer("3", "z", 200, 2000, 90)];
  assert.deepEqual(sortOffers(list, "price").map((o) => o.id), ["2", "3", "1"]);
  assert.deepEqual(sortOffers(list, "cover").map((o) => o.id), ["3", "1", "2"]);
  assert.deepEqual(filterOffers(list, { providers: ["x", "y"] }).map((o) => o.id), ["1", "2"]);
  assert.deepEqual(filterOffers(list, { maxPremiumMinor: 200 }).map((o) => o.id), ["2", "3"]);
  assert.deepEqual(filterOffers(list, { maxExcessMinor: 50 }).map((o) => o.id), ["1", "2"]);
  const rows = compareRows(list.slice(0, 2));
  const total = rows.find((r) => r.key === "total");
  assert.equal(total.cells[1].best, true);
  assert.ok(rows.some((r) => r.key === "cover:A"));
});

test("validity countdown and expiry", () => {
  const now = Date.parse("2026-09-24T10:00:00Z");
  assert.equal(validityLeft("2026-09-24T09:00:00Z", now).expired, true);
  assert.match(validityLeft("2026-09-26T12:00:00Z", now).label, /2d 2h/);
  assert.equal(validityLeft(undefined, now).expired, false);
});

test("every policy and proposal status has a meaning", () => {
  assert.equal(policyStatusInfo("PAID_PENDING_ISSUANCE").label, "Issuance in progress");
  assert.equal(policyStatusInfo("PENDING_PAYMENT").bucket, "pending");
  assert.equal(policyStatusInfo("EXPIRING").claimable, true);
  assert.equal(policyStatusInfo("SUSPENDED").bucket, "suspended");
  assert.equal(policyStatusInfo("CANCELLED").bucket, "cancelled");
  assert.equal(proposalStatusInfo("UNDER_REVIEW").stage, "review");
  assert.equal(proposalStatusInfo("MORE_INFORMATION").stage, "documents");
  assert.equal(proposalStatusInfo("DOCUMENTS_PENDING").stage, "documents");
  assert.equal(proposalStatusInfo("COUNTEROFFERED").stage, "counteroffer");
  assert.equal(proposalStatusInfo("DECLINED").stage, "declined");
  assert.equal(proposalStatusInfo("PAYMENT_PENDING").stage, "payable");
});

test("payment stepper follows payment and purchase status", () => {
  assert.equal(purchaseStep("CREATED").step, 0);
  assert.equal(purchaseStep("PENDING_CUSTOMER").step, 1);
  assert.equal(purchaseStep("PENDING_CUSTOMER", "ISSUANCE_PENDING").done, true);
  assert.equal(purchaseStep("FAILED").failed, true);
});

test("provider-not-configured is recognised (422 errors.provider and 503)", () => {
  const e422 = { status: 422, code: "REQUEST_FAILED", message: "The given data was invalid.", fields: { provider: ["Payment provider is not configured."] } };
  assert.equal(isProviderNotConfigured(e422), true);
  assert.equal(providerErrorMessage(e422), "Payment provider is not configured.");
  assert.equal(isProviderNotConfigured({ status: 503, message: "Payment provider not configured" }), true);
  assert.equal(isProviderNotConfigured({ status: 422, message: "phone invalid", fields: { phone: ["x"] } }), false);
});

test("payment idempotency key is stable per proposal + attempt + payer", () => {
  const a = paymentIdempotencyKey("p-1", 1, "mtn_momo", "+237 650 000 000");
  assert.equal(a, paymentIdempotencyKey("p-1", 1, "mtn_momo", "+237650000000"));
  assert.notEqual(a, paymentIdempotencyKey("p-1", 2, "mtn_momo", "+237650000000"));
  assert.ok(a.length >= 16 && a.length <= 128);
});

test("refund payload matches the new contract and keeps legacy fields", () => {
  const body = refundPayload({ reason: "  Paid twice by mistake ", reasonCode: "DUPLICATE_PAYMENT", amountMinor: 1500.4, idempotencyKey: "refund:abc:1234567890" });
  assert.equal(body.reason, "Paid twice by mistake");
  assert.equal(body.reason_code, "DUPLICATE_PAYMENT");
  assert.equal(body.amount_minor, 1500);
  assert.equal(body.notes, body.reason);
  assert.ok(body.idempotency_key.length >= 16);
});

test("receipts read both field sets; URLs are never opened blindly", () => {
  const old = receiptView({ reference: "MTN-1", confirmed_at: "2026-09-24T10:00:00Z", amount_minor: 100 });
  assert.equal(old.number, "MTN-1");
  assert.equal(old.issuedAt, "2026-09-24T10:00:00Z");
  assert.equal(old.downloadUrl, null);
  const next = receiptView({ receipt_number: "RCP-9", issued_at: "2026-09-25T00:00:00Z", download_url: "https://x.test/r.pdf", amount_minor: 1 });
  assert.equal(next.number, "RCP-9");
  assert.equal(next.downloadUrl, "https://x.test/r.pdf");
  assert.equal(openableUrl(undefined), null);
  assert.equal(openableUrl("javascript:alert(1)"), null);
});

test("risk schema: server shape normalizes, local fallback validates and builds facts", () => {
  const server = normalizeRiskSchema(
    { data: { line_code: "motor", steps: [{ key: "vehicle", title: "Vehicle", fields: [{ key: "usage_type", label: "Usage", type: "select", options: ["PRIVATE", "TAXI"], required: true }] }] } },
    "MOTOR",
  );
  assert.equal(server.source, "server");
  assert.equal(server.steps[0].fields[0].options[1].value, "TAXI");
  assert.equal(normalizeRiskSchema({ data: { steps: [] } }, "MOTOR"), null);

  const motor = localRiskSchema("motor");
  assert.deepEqual(motor.steps.map((s) => s.key), ["vehicle", "usage", "owner", "cover", "history"]);
  const required = motor.steps.flatMap((s) => s.fields.filter((f) => f.required).map((f) => f.key));
  // usage_type (tariff fact) is derived from the 28-value vehicle_usage by buildFacts / the server.
  for (const k of ["registration_number", "fiscal_power", "vehicle_usage", "zone"]) assert.ok(required.includes(k), k);
  assert.equal(buildFacts(motor, { vehicle_usage: "PRIVATE_PERSONAL" }).usage_type, "PRIVATE");
  assert.ok(localRiskSchema("BUSINESS") && localRiskSchema("ACCIDENT"));

  const errors = validateStep(motor.steps[0], { registration_number: "", fiscal_power: "abc" });
  assert.ok(errors.registration_number && errors.fiscal_power);
  const trip = localRiskSchema("TRAVEL").steps[0];
  assert.ok(validateStep(trip, { destination_country: "FR", departure_date: "2026-10-10", return_date: "2026-10-01", traveller_count: "1" }).return_date);
  assert.ok(validateStep(trip, { destination_country: "FR", departure_date: "2026-02-30", return_date: "2026-03-01", traveller_count: "1" }).departure_date);

  const facts = buildFacts(localRiskSchema("HOME"), { property_type: "HOUSE", city: "douala", declared_value_minor: "25000000" });
  assert.equal(facts.declared_value_minor, 2500000000);
  assert.equal(facts.city, "DOUALA");
  assert.equal(facts.property_type, "HOUSE");
});

test("client: one idempotency key per operation, reused on retry and after 401", () => {
  const client = read("src/api/client.ts");
  // resolved once, outside the per-attempt function
  assert.match(client, /options\.idempotencyKey \?\?\s*\(options\.idempotent \? Crypto\.randomUUID\(\) : undefined\)/);
  assert.match(client, /"Idempotency-Key": idempotencyKey/);
  assert.doesNotMatch(client, /"Idempotency-Key": Crypto\.randomUUID\(\)/);
  assert.match(client, /return attemptRequest<T>\(path, \{ \.\.\.options, retryAuth: false \}\)/);
  // payment creation uses the caller's stable key in the header too
  assert.match(client, /idempotencyKey: payload\.idempotency_key/);
  assert.match(read("src/store/insurance.ts"), /paymentIdempotencyKey\(proposal\.id, attempt/);
});

test("screens use the fixed contracts", () => {
  assert.match(read("app/payments/index.tsx"), /usePagedList/);
  assert.match(read("app/wallet/index.tsx"), /usePagedList/);
  assert.match(read("app/payments/[id]/refund.tsx"), /refundPayload/);
  assert.match(read("app/payment.tsx"), /refreshPurchase/);
  assert.match(read("app/payment.tsx"), /Check later/);
  assert.match(read("app/quotes/[id].tsx"), /setQuoteResult|loadQuote/);
  assert.match(read("app/quotes/[id].tsx"), /next_path/);
  assert.match(read("src/hooks/usePolicies.ts"), /WalletApi/);
  assert.doesNotMatch(read("src/hooks/usePolicies.ts"), /InsuranceApi\.policies/);
  assert.match(read("src/components/policies/PolicyDetailView.tsx"), /openableUrl\(cert\.download_url\)/);
  assert.match(read("app/quote/offers.tsx"), /snapToInterval/);
  assert.match(read("app/quote/compare.tsx"), /compareRows/);
});

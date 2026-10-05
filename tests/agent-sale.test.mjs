import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { networkForPhone, saleButton, salePolls, saleProductFromParam, saleProgress, saleRiskFacts } from "../src/lib/assistedSale.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("product param preselects the customer-wizard line ids", () => {
  assert.equal(saleProductFromParam("motor"), "motor");
  assert.equal(saleProductFromParam("TRAVEL"), "travel");
  assert.equal(saleProductFromParam("Motor Third Party"), null);
  assert.equal(saleProductFromParam(undefined), null);
});

test("operator inferred from the Cameroon numbering plan", () => {
  assert.equal(networkForPhone("+237677123456"), "mtn_momo");
  assert.equal(networkForPhone("+237652123456"), "mtn_momo");
  assert.equal(networkForPhone("+237699123456"), "orange_money");
  assert.equal(networkForPhone("+237656123456"), "orange_money");
  assert.equal(networkForPhone("+33612345678"), null);
});

test("the client is the insured person of the risk facts", () => {
  assert.deepEqual(saleRiskFacts({ fiscal_power: 7 }), { fiscal_power: 7, insured_person: { relationship: "SELF" } });
});

test("one footer action per server next step; waits are refreshes, never a second request", () => {
  assert.equal(saleButton({ next_action: "SEND_TO_CLIENT" }).kind, "advance");
  assert.equal(saleButton({ next_action: "REQUEST_PAYMENT" }).needsNetwork, true);
  assert.equal(saleButton({ next_action: "AWAIT_PAYMENT" }).kind, "refresh");
  assert.equal(saleButton({ next_action: "AWAIT_UNDERWRITING" }).kind, "refresh");
  assert.equal(saleButton({ next_action: "NONE" }), null);
  assert.equal(saleButton(undefined), null);
  assert.equal(salePolls({ next_action: "AWAIT_PAYMENT" }), true);
  assert.equal(salePolls({ next_action: "AWAIT_CLIENT" }), false);
});

test("progress comes from server statuses only", () => {
  assert.deepEqual(saleProgress({ payment_status: "NOT_REQUESTED" }), [true, false, false, false, false, false]);
  assert.deepEqual(saleProgress({ proposal_id: "p", payment_status: "NOT_REQUESTED" }), [true, true, false, false, false, false]);
  assert.deepEqual(saleProgress({ proposal_id: "p", client_terms_accepted: true, payment_status: "CUSTOMER_PROMPTED" }), [true, true, true, true, false, false]);
  assert.deepEqual(saleProgress({ proposal_id: "p", payment_status: "PAID", issuance_status: "ISSUED" }), [true, true, true, true, true, true]);
});

test("assisted sale sends real risk facts on the shared risk schema, never invented defaults", () => {
  const screen = read("src/components/sales/AssistedSaleNew.tsx");
  assert.match(screen, /CatalogueApi\.riskSchema/);
  assert.match(screen, /ContractField/);
  assert.match(screen, /validateStep/);
  assert.match(screen, /risk_facts: saleRiskFacts\(buildFacts\(/);
  assert.match(screen, /params\.product/);
  assert.doesNotMatch(screen, /Motor Third Party/);
  const quote = read("app/quote/risk.tsx");
  assert.match(quote, /from "@\/lib\/riskFormValues"/);
});

test("sale detail guards repeated taps and reports failures", () => {
  const screen = read("src/components/sales/AssistedSaleDetail.tsx");
  assert.match(screen, /inFlight\.current/);
  assert.match(screen, /catch \(e\)/);
  assert.match(screen, /saleApi\(portal\)\.requestPayment\(id, body\)/);
  const client = read("src/api/client.ts");
  assert.match(client, /risk_facts: Record<string, unknown>/);
  assert.match(client, /commission_basis\?: "ACCRUED" \| "RULE_ESTIMATE" \| "NOT_CONFIGURED"/);
});

test("new sale copy exists in EN and FR", () => {
  const en = read("src/i18n/en.ts");
  const fr = read("src/i18n/fr.ts");
  for (const key of ["slGetPrice", "slSendToClient", "slRemindClient", "slRefreshStatus", "slActionFailed", "slClientNetwork", "slCommissionRule", "slCommissionNotConfigured", "slNext_AWAIT_CLIENT", "saleStatus_AWAITING_CLIENT", "salePayment_CUSTOMER_PROMPTED"]) {
    assert.match(en, new RegExp(`\\b${key}: "`), `en ${key}`);
    assert.match(fr, new RegExp(`\\b${key}: "`), `fr ${key}`);
  }
});

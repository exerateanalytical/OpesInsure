import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { declineReasonValid, deskActions, deskTone } from "../src/lib/renewalDesk.ts";
import { focusedCarrierIds, focusFromParams, focusParams, quoteFocusOf } from "../src/lib/quoteFocus.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

// ------------------------------------------------------------------ broker quote & sell
test("brokers quote and sell on the shared assisted-sale screens (no copy of the agent flow)", () => {
  for (const portal of ["agent", "broker"]) {
    assert.match(read(`app/${portal}/sales/new.tsx`), new RegExp(`<AssistedSaleNew portal="${portal}" />`));
    assert.match(read(`app/${portal}/sales/[id].tsx`), new RegExp(`<AssistedSaleDetail portal="${portal}" />`));
  }
  const client = read("src/api/client.ts");
  assert.match(client, /export const saleApi = \(portal: SalePortal\)/);
  assert.match(client, /`\/mobile\/\$\{portal\}\/sales`/);
  assert.match(client, /\.\.\.saleApi\("agent"\)/);
  const layout = read("app/_layout.tsx");
  for (const route of ["broker/sales/new", "broker/sales/[id]", "agent/renewals/[id]"]) assert.ok(layout.includes(`name="${route}"`), route);
});

test("broker entry points: home quick action, client detail, sellable catalogue", () => {
  assert.match(read("app/broker/index.tsx"), /href: "\/broker\/sales\/new"/);
  const detail = read("app/broker/clients/[id].tsx");
  assert.match(detail, /\/broker\/sales\/new\?customerId=\$\{id\}/);
  assert.match(detail, /usePermission\("quotes\.manage"\)/);
  assert.doesNotMatch(detail, /bkAssistedQuotePending|UnavailableSection/);
  assert.match(read("app/broker/catalogue.tsx"), /portal="broker"/);
  assert.match(read("src/components/offers/SellableCatalogueScreen.tsx"), /`\/\$\{portal\}\/sales\/new`/);
});

test("agent Home 'Create quote' starts the assisted sale directly", () => {
  const home = read("app/agent/index.tsx");
  assert.match(home, /label: "agQuickCreateQuote", subtitle: "agQuoteAndRequest", icon: FileSignature, href: "\/agent\/sales\/new"/);
  // One action per destination: no second "New sale" tile to the same screen.
  assert.equal((home.match(/href: "\/agent\/sales\/new"/g) ?? []).length, 1);
});

// ------------------------------------------------------------------ renewals
test("renewal desk actions follow the server's allowed_actions and close with the case", () => {
  assert.deepEqual(deskActions({ status: "NOT_OPENED", allowed_actions: ["REQUOTE", "DECLINE"] }), { openOffer: false, requote: true, decline: true });
  assert.deepEqual(deskActions({ status: "QUOTED", allowed_actions: ["DECLINE"], sale_id: "q1" }), { openOffer: true, requote: false, decline: true });
  assert.deepEqual(deskActions({ status: "QUOTED", allowed_actions: [] }), { openOffer: false, requote: false, decline: false });
  assert.deepEqual(deskActions({ status: "RENEWED", allowed_actions: ["REQUOTE"], sale_id: "q1" }), { openOffer: false, requote: false, decline: false });
  assert.deepEqual(deskActions({ status: "QUOTED", sale_id: "q1", successor_policy_id: "p2" }), { openOffer: false, requote: false, decline: false });
  assert.deepEqual(deskActions(null), { openOffer: false, requote: false, decline: false });
  assert.equal(deskTone("RENEWED"), "success");
  assert.equal(deskTone("DUE"), "warning");
  assert.equal(deskTone("ISSUANCE_FAILED"), "danger");
  assert.equal(declineReasonValid("  sold "), true);
  assert.equal(declineReasonValid("ok"), false);
});

test("both portals use the one renewal desk, with confirmation before each action", () => {
  assert.match(read("app/broker/renewals/[id].tsx"), /<RenewalDeskScreen portal="broker" \/>/);
  assert.match(read("app/agent/renewals/[id].tsx"), /<RenewalDeskScreen portal="agent" \/>/);
  assert.match(read("app/agent/renewals.tsx"), /pathname: "\/agent\/renewals\/\[id\]"/);
  const desk = read("src/components/renewals/RenewalDeskScreen.tsx");
  assert.match(desk, /setConfirm\("requote"\)/);
  assert.match(desk, /setConfirm\("decline"\)/);
  assert.match(desk, /renewalDeskApi\(portal\)\.requote\(id\)/);
  assert.match(desk, /renewalDeskApi\(portal\)\.decline\(id, reason\.trim\(\)\)/);
  assert.match(desk, /inFlight\.current/);
});

// ------------------------------------------------------------------ broker claim picker
test("broker claim searches the whole book on the server (no 30-client cap, no swallowed errors)", () => {
  const screen = read("app/broker/claims/new.tsx");
  assert.doesNotMatch(screen, /MAX_CLIENTS|BrokerApi\.client\(/);
  assert.match(screen, /ClaimablePolicyPicker/);
  const picker = read("src/components/partner/ClaimablePolicyPicker.tsx");
  assert.match(picker, /BrokerWorkspaceApi\.claimablePolicies\(/);
  assert.match(picker, /ErrorCard/);
  assert.match(picker, /clmLoadMore/);
  assert.match(read("src/api/partner.ts"), /\/mobile\/broker\/claimable-policies\?/);
});

// ------------------------------------------------------------------ quote from a profile
test("an insurer profile focuses the quote on that insurer; a broker profile on its insurers", () => {
  assert.deepEqual(quoteFocusOf({ id: "c1", type: "insurer", name: "Activa Assurances", short_name: "ACTIVA" }), { carriers: ["c1"], name: "ACTIVA" });
  assert.deepEqual(quoteFocusOf({ id: "b1", type: "broker", name: "Ascoma", affiliated_insurers: [{ id: "c1" }, { id: "c2" }, { id: "c1" }] }), { carriers: ["c1", "c2"], name: "Ascoma" });
  assert.equal(quoteFocusOf({ id: "b1", type: "broker", name: "Solo", affiliated_insurers: [] }), null);
  assert.deepEqual(focusParams({ carriers: ["c1", "c2"], name: "Ascoma" }), { carriers: "c1,c2", from: "Ascoma" });
  assert.deepEqual(focusParams(null), {});
  assert.deepEqual(focusFromParams("c1,c2", "Ascoma"), { carriers: ["c1", "c2"], name: "Ascoma" });
  assert.equal(focusFromParams(undefined, "x"), null);
  assert.deepEqual(focusedCarrierIds({ carriers: ["c1", "c9"], name: "x" }, ["c1", "c2"]), ["c1"]);
  assert.deepEqual(focusedCarrierIds(null, ["c1"]), []);
});

test("the focus travels product → risk → offers and shows a chip with 'See all insurers'", () => {
  assert.match(read("src/components/institutions/InstitutionProfile.tsx"), /quoteFocusOf\(row\)/);
  assert.match(read("app/institutions/insurer/[id].tsx"), /quoteFocusOf\(insurer\)/);
  assert.match(read("app/quote/product.tsx"), /focusParams\(focusFromParams\(carriers, from\)\)/);
  assert.match(read("app/quote/risk.tsx"), /params: \{ quoteId: result\.quote\.id, \.\.\.focus \}/);
  const offers = read("app/quote/offers.tsx");
  assert.match(offers, /prov: focusIds/);
  assert.match(offers, /t\("ofOffersFrom"/);
  assert.match(offers, /t\("ofSeeAllInsurers"\)/);
});

test("new copy exists in EN and FR", () => {
  const en = read("src/i18n/en.ts");
  const fr = read("src/i18n/fr.ts");
  for (const key of ["rdRequote", "rdRequoteConfirm", "rdOpenOffer", "rdDecline", "rdDeclineReason", "rdDeclineConfirm", "rdActionFailed", "rdEvent_QUOTED", "renewalStatus_NOT_OPENED", "bkNeverPin", "ofOffersFrom", "ofSeeAllInsurers", "ofFocusNoOffer", "clmSearchPolicies", "clmNoPolicies", "clmLoadMore", "clmChangePolicy"]) {
    assert.match(en, new RegExp(`\\b${key}: "`), `en ${key}`);
    assert.match(fr, new RegExp(`\\b${key}: "`), `fr ${key}`);
  }
});

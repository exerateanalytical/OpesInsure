// Customer bottom navigation on stack screens (owner report: "the home
// buttons HOME, EXPLORE, POLICY, PROFILE are missing" while buying a policy)
// and the Android back rules that keep a customer inside the app.
import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  CUSTOMER_HOME_HREF,
  CUSTOMER_TABS,
  customerBackAction,
  customerBarVisible,
  customerTabForRoute,
  normalizeRouteName,
} from "../src/lib/customerNav.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("route names normalise the way the root Stack reports them", () => {
  assert.equal(normalizeRouteName("notifications/index"), "notifications");
  assert.equal(normalizeRouteName("/quote/product/[id]"), "quote/product/[id]");
  assert.equal(normalizeRouteName("index"), "");
  assert.equal(normalizeRouteName(undefined), "");
});

test("the bar shows on every purchase and servicing screen", () => {
  for (const name of [
    "quote/product",
    "quote/product/[id]",
    "quote/risk",
    "quote/offers",
    "quote/compare",
    "quote/terms",
    "quote/questions",
    "quote/referral",
    "quote/disclosure",
    "quote-comparison/[id]",
    "quotes/index",
    "quotes/[id]",
    "proposals/index",
    "proposals/[id]",
    "proposals/[id]/information",
    "checkout",
    "confirmation",
    "policy/[id]",
    "policy/[id]/renew",
    "claim/new",
    "claim/[id]",
    "payments/index",
    "payments/[id]/receipt",
    "documents/[id]",
    "services/new",
    "notifications/index",
    "notifications/[id]",
    "support/new",
    "wallet/index",
    "account/profile",
    "assets/index",
    "institutions/insurers",
    "search",
  ]) {
    assert.equal(customerBarVisible(name), true, name);
  }
});

test("the bar is hidden where it would be harmful or duplicate", () => {
  for (const name of [
    "(customer)", // the tab navigator draws its own bar
    "index",
    "payment", // payment confirmation poll
    "documents/view",
    "assets/[id]/scan",
    "verify/scan",
    "security/step-up",
    "onboarding/kyc",
    "(auth)/sign-in",
    "(auth)/role",
    "welcome",
    "agent/index",
    "broker/clients",
    "carrier/claims",
    "workspace/[role]",
  ]) {
    assert.equal(customerBarVisible(name), false, name);
  }
});

test("each stack screen highlights the tab it belongs to", () => {
  const cases = {
    "quote/product": "explore",
    "quote/offers": "explore",
    "quotes/[id]": "explore",
    "quote-comparison/[id]": "explore",
    "proposals/[id]": "explore",
    checkout: "explore",
    confirmation: "explore",
    "institutions/insurer/[id]": "explore",
    "policy/[id]": "policies",
    "policy/[id]/documents": "policies",
    "payments/[id]": "policies",
    "wallet/index": "policies",
    "services/new": "policies",
    "documents/[id]": "policies",
    "claim/new": "claims",
    "claim/[id]/evidence": "claims",
    "account/profile": "profile",
    "account/language": "profile",
    "assets/index": "profile",
    "support/index": "profile",
    "notifications/index": "home",
    search: "home",
  };
  for (const [name, tab] of Object.entries(cases)) assert.equal(customerTabForRoute(name), tab, name);
  assert.equal(customerTabForRoute("something-new"), null);
});

test("the stack bar offers the same five tabs as the tab navigator", () => {
  assert.deepEqual(
    CUSTOMER_TABS.map((t) => t.key),
    ["home", "explore", "policies", "claims", "profile"],
  );
  assert.equal(CUSTOMER_TABS[0].href, CUSTOMER_HOME_HREF);
  const tabs = read("app/(customer)/(tabs)/_layout.tsx");
  for (const t of CUSTOMER_TABS) {
    const screen = t.key === "home" ? "index" : t.key;
    assert.match(tabs, new RegExp(`name="${screen}"[\\s\\S]*?customerTabBarIcon\\("${t.key}"\\)`), t.key);
    assert.ok(read(`app/(customer)/(tabs)/${screen}.tsx`).length > 0);
  }
  // One design: the tab navigator and the stack bar share the same module.
  assert.match(tabs, /from "@\/components\/customer\/CustomerTabBar"/);
  assert.match(read("app/_layout.tsx"), /screenLayout=\{customer \? customerScreenLayout : undefined\}/);
});

test("Android back never exits the app or reopens a finished payment", () => {
  assert.deepEqual(customerBackAction("quote/offers", true), { kind: "default" });
  // Opened from a notification / deep link with nothing underneath: Home, not exit.
  assert.deepEqual(customerBackAction("policy/[id]", false), { kind: "replace", href: CUSTOMER_HOME_HREF });
  assert.deepEqual(customerBackAction("checkout", false), { kind: "replace", href: CUSTOMER_HOME_HREF });
  // After payment the stack underneath is checkout/terms: never go back there.
  assert.deepEqual(customerBackAction("confirmation", true), { kind: "dismissTo", href: CUSTOMER_HOME_HREF });
  // Leaving the payment poll behaves like "Check later".
  assert.deepEqual(customerBackAction("payment", true, { proposalId: "p 1" }), { kind: "replace", href: "/proposals/p%201" });
  assert.deepEqual(customerBackAction("payment", true, {}), { kind: "replace", href: "/(customer)/(tabs)/policies" });
  // The tab navigator, splash router and auth keep their own back (Home tab exits the app as usual).
  for (const name of ["(customer)", "index", "(auth)/sign-in", "onboarding/kyc"]) {
    assert.deepEqual(customerBackAction(name, false), { kind: "default" }, name);
  }
});

test("the header back falls back to the session home, not the splash router", () => {
  const design = read("src/components/design/index.tsx");
  assert.match(design, /export function goBackOrHome\(\)/);
  assert.match(design, /router\.replace\(\(sessionHome\(useSession\.getState\(\)\) \?\? "\/"\) as never\)/);
  assert.doesNotMatch(design, /router\.replace\("\/" as never\)\)\}/);
});

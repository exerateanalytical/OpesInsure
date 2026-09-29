import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  branchOffices,
  directionsUrl,
  hostLabel,
  hqAddress,
  institutionRoute,
  isNotFound,
  licenceState,
  officeKind,
  quoteEntry,
  readDirectory,
  sourceLinks,
} from "../src/lib/institutions.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("route follows the row type; 404 is recognised", () => {
  assert.equal(institutionRoute("broker"), "/institutions/broker/[id]");
  assert.equal(institutionRoute("insurer"), "/institutions/insurer/[id]");
  assert.equal(institutionRoute(undefined), "/institutions/insurer/[id]");
  assert.equal(isNotFound({ status: 404 }), true);
  assert.equal(isNotFound({ status: 500 }), false);
  assert.equal(isNotFound(null), false);
});

test("offices: head office is not counted as a branch", () => {
  const offices = [
    { name: "Siège Social Douala", type: "HEAD_OFFICE", city: "Douala", address: null, phone: null },
    { name: "Bureau Direct Kribi", type: "DIRECT_BRANCH", city: "Kribi", address: null, phone: null },
    { name: "Agence", type: null, city: null, address: null, phone: "8033" },
  ];
  assert.equal(officeKind("HEAD_OFFICE"), "head");
  assert.equal(officeKind("direct_branch"), "branch");
  assert.equal(officeKind(null), null);
  assert.deepEqual(branchOffices(offices).map((b) => b.name), ["Bureau Direct Kribi", "Agence"]);
});

test("head office address and directions", () => {
  assert.equal(hqAddress({ city: "Douala", address: "Rue Prince de Galles, Akwa, Douala", po_box: null }), "Rue Prince de Galles, Akwa, Douala");
  assert.equal(hqAddress({ city: "Yaoundé", address: "Avenue Kennedy", po_box: null }), "Avenue Kennedy, Yaoundé");
  assert.equal(hqAddress({ city: "Douala", address: null, po_box: "12970" }), "Douala");
  assert.equal(hqAddress(null), null);
  assert.equal(directionsUrl({ city: null, address: null, po_box: "1" }), null);
  assert.match(directionsUrl({ city: "Douala", address: "309 Rue Bebey Eyidi", po_box: null }), /^https:\/\/www\.google\.com\/maps\/search\/\?api=1&query=309%20Rue%20Bebey%20Eyidi%2C%20Douala%2C%20Cameroun$/);
});

test("sources become short tappable links", () => {
  assert.equal(hostLabel("https://www.axa.cm/contact"), "axa.cm");
  assert.deepEqual(sourceLinks(["https://dgtcfm.cm/liste-des-compagnies/", "ASAC annual report", "https://dgtcfm.cm/liste-des-compagnies/"]), [
    { label: "dgtcfm.cm", url: "https://dgtcfm.cm/liste-des-compagnies/" },
    { label: "ASAC annual report", url: null },
  ]);
});

test("broker licence state against today", () => {
  assert.equal(licenceState("2026-12-27", "2026-09-29"), "valid");
  assert.equal(licenceState("2026-09-28", "2026-09-29"), "expired");
  assert.equal(licenceState("2026-09-29T00:00:00Z", "2026-09-29"), "valid");
  assert.equal(licenceState(null, "2026-09-29"), null);
  assert.equal(licenceState("soon", "2026-09-29"), null);
});

test("quote CTA: sign in when signed out, quote for customers, none for other workspaces", () => {
  assert.equal(quoteEntry("guest", null), "sign-in");
  assert.equal(quoteEntry("booting", null), "sign-in");
  assert.equal(quoteEntry("authenticated", "customer"), "quote");
  assert.equal(quoteEntry("authenticated", "agent"), null);
  assert.equal(quoteEntry("authenticated", "broker_admin"), null);
});

test("broker contacts read through the same directory reader as insurers", () => {
  const d = readDirectory({ type: "broker", phone: "+237 600 00 01 02" });
  assert.deepEqual(d.phones, ["+237 600 00 01 02"]);
  assert.deepEqual(d.emails, []);
  assert.equal(d.website, null);
});

test("both profile pages share one structure and component set", () => {
  const insurer = read("app/institutions/insurer/[id].tsx");
  const broker = read("app/institutions/broker/[id].tsx");
  for (const page of [insurer, broker]) {
    for (const part of [/InstitutionScreen/, /ProfileHero/, /KeyFacts/, /ContactCard/, /ProfileFooter/]) assert.match(page, part);
    // No stacked full-width contact buttons, no raw line codes.
    assert.doesNotMatch(page, /Linking\.openURL\(`tel:/);
    assert.doesNotMatch(page, /td\(`line_/);
  }
  const shared = read("src/components/institutions/InstitutionProfile.tsx");
  for (const s of [/quoteEntry/, /institutionRoute/, /isNotFound/, /telUrl/, /mailto:/, /sourceLinks/, /CtaBar/]) assert.match(shared, s);
});

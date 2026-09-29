import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  insurerFacts,
  insurerKindKey,
  legalFooterLines,
  legalNameLine,
  officeSummary,
  officesPending,
  validDate,
} from "../src/lib/insurerProfile.ts";
import { readDirectory } from "../src/lib/institutions.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

// Shape of GET /public/institutions/{id} for ACTIVA Assurances (production, 2026-09-29).
const activa = {
  type: "insurer",
  name: "ACTIVA Assurances",
  legal_name: "ACTIVA ASSURANCES",
  canonical_id: "CM-INS-IARD-001",
  regulator_sequence: 1,
  insurer_code: "ACTIVA",
  branch: "IARD",
  regulatory_status: "AUTHORIZED",
  licensed: true,
  verified_at: "2026-09-25",
  verification_status: "VERIFIED",
  legal_footer: [],
  contacts: { phones: ["+237 233 50 13 00", "8033"], emails: ["service.clients@group-activa.com"], website: "https://cameroun.group-activa.com/", po_box: "12970 Douala" },
  head_office: { city: "Douala", address: "Rue Prince de Galles, Akwa, Douala", po_box: "12970 Douala" },
  branches: [
    { name: "Siège Social Douala", type: "HEAD_OFFICE", city: "Douala", address: "Rue Prince de Galles", phone: "+237 233 50 13 00" },
    { name: "Bureau Direct Grand Mall", type: "DIRECT_BRANCH", city: "Douala", address: "Grand Mall", phone: "+237 692 88 00 00" },
    { name: "Bureau Direct Kribi", type: "DIRECT_BRANCH", city: "Kribi", address: "Rue Mokolo", phone: null },
    { name: "Bureau Direct Yaoundé", type: "DIRECT_BRANCH", city: "Yaoundé", address: "Chambre de Commerce", phone: null },
  ],
};

test("legal name is shown only when it says more than the display name", () => {
  assert.equal(legalNameLine("ACTIVA Assurances", "ACTIVA ASSURANCES"), null);
  assert.equal(legalNameLine("Zénithe Insurance", "ZENITHE INSURANCE"), null);
  assert.equal(legalNameLine("AXA Cameroun", "AXA Assurances Cameroun S.A."), "AXA Assurances Cameroun S.A.");
  assert.equal(legalNameLine("AXA", null), null);
  assert.equal(legalNameLine("AXA", "  "), null);
});

test("kind line names the licence branch instead of a raw IARD/Vie chip", () => {
  assert.equal(insurerKindKey("IARD"), "instKindInsurerIARD");
  assert.equal(insurerKindKey("LIFE"), "instKindInsurerLIFE");
  assert.equal(insurerKindKey(null), "instKindInsurer");
});

test("office summary counts branch offices only and their distinct cities", () => {
  const d = readDirectory(activa);
  assert.deepEqual(officeSummary(d), { count: 3, cities: 3 });
  assert.deepEqual(officeSummary({ branches: [] }), { count: 0, cities: 0 });
  assert.deepEqual(officeSummary({ branches: [{ name: "X", type: "DIRECT_BRANCH", city: null, address: null, phone: null }] }), { count: 1, cities: 0 });
});

test("key facts: register entry, branch, offices, directory check", () => {
  const facts = insurerFacts(activa, readDirectory(activa));
  assert.deepEqual(facts.map((f) => f.label), ["instRegisterEntry", "instLicenceBranch", "insurerStatBranches", "instDirectoryChecked"]);
  assert.deepEqual(facts[0].value, { key: "instRegisterEntryValue", vars: { number: 1, id: "CM-INS-IARD-001" } });
  assert.deepEqual(facts[1].value, { key: "branchIARD" });
  assert.deepEqual(facts[2].value, { key: "instOfficesValue", vars: { count: 3, cities: 3 } });
  assert.deepEqual(facts[3].value, { date: "2026-09-25" });
  // Internal codes ("ACTIVA", "ACTIVA_VIE") are never shown as facts.
  assert.ok(!JSON.stringify(facts).includes("ACTIVA\""));
  // No "products on OpesInsure" fact: the products section heading already carries the count.
  assert.ok(!facts.some((f) => f.label === "instProductsOnPlatform"));
});

test("key facts: missing values are left out, unlicensed insurers get a warning", () => {
  const facts = insurerFacts({ name: "X", canonical_id: "CM-1", licensed: false, regulatory_status: "SUSPENDED", verified_at: "not a date", branch: "OTHER" }, { branches: [] });
  assert.deepEqual(facts.map((f) => f.label), ["instRegisterEntry", "instRegulatoryStatus"]);
  assert.deepEqual(facts[0].value, { text: "CM-1" });
  assert.equal(facts[1].tone, "warning");
  assert.deepEqual(insurerFacts({ name: "X" }, { branches: [] }), []);
});

test("dates and legal footer lines are sanitised", () => {
  assert.equal(validDate("2026-09-25"), "2026-09-25");
  assert.equal(validDate("2026-09-25T10:00:00Z"), "2026-09-25T10:00:00Z");
  assert.equal(validDate("25/09/2026"), null);
  assert.equal(validDate(null), null);
  assert.deepEqual(legalFooterLines([" Line 1 ", "", 3, null, "Line 2"]), ["Line 1", "Line 2"]);
  assert.deepEqual(legalFooterLines("nope"), []);
});

test("offices pending note only when the directory lists no office and branches are unverified", () => {
  const hqOnly = readDirectory({ verification_status: "VERIFIED_HQ_BRANCHES_PENDING", branches: [] });
  assert.equal(officesPending(hqOnly), true);
  assert.equal(officesPending(readDirectory({ verification_status: "PARTIALLY_VERIFIED" })), true);
  assert.equal(officesPending(readDirectory({ verification_status: "VERIFIED" })), false);
  assert.equal(officesPending(readDirectory(activa)), false);
});

test("insurer page uses the helpers, the badge tone and translated copy", () => {
  const page = read("app/institutions/insurer/[id].tsx");
  for (const s of [/insurerFacts/, /insurerKindKey/, /officesPending/, /legalFooterLines/, /tone=\{d\.verification\.tone\}/, /instProductFamilies/]) assert.match(page, s);
  // The EN "Vie" badge duplicated the licence branch fact.
  assert.doesNotMatch(page, /branchBadgeLIFE/);
  const en = read("src/i18n/en.ts");
  const fr = read("src/i18n/fr.ts");
  for (const k of ["instKindInsurerIARD", "instKindInsurerLIFE", "instRegulatoryStatus", "instLicenceUnconfirmed", "instProductFamilies", "instProductFamiliesNote", "instOfficesPending"]) {
    assert.match(en, new RegExp(`\\b${k}:`), `en ${k}`);
    assert.match(fr, new RegExp(`\\b${k}:`), `fr ${k}`);
  }
});

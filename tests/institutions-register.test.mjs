import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import {
  REGISTER_SOURCE_KEY,
  filterBrokers,
  filterInsurers,
  registerCounts,
} from "../src/lib/institutions.ts";
import { en } from "../src/i18n/en.ts";
import { fr } from "../src/i18n/fr.ts";

const root = fileURLToPath(new URL("..", import.meta.url));
const read = (p) => readFileSync(join(root, p), "utf8");

const insurer = (over) => ({
  id: over.short_name,
  type: "insurer",
  name: over.short_name,
  initials: "X",
  city: null,
  branch: "IARD",
  product_families: [],
  is_official_register: true,
  ...over,
});
const insurers = [
  insurer({ short_name: "CHANAS", name: "Chanas Assurances", regulator_sequence: 8, product_families: ["Automobile", "Santé"] }),
  insurer({ short_name: "ACTIVA", name: "ACTIVA Assurances", regulator_sequence: 1 }),
  insurer({ short_name: "CHANAS VIE", name: "Chanas Vie", branch: "LIFE", regulator_sequence: 23 }),
  insurer({ short_name: "DEMO", name: "Demo Mutual", branch: null, is_official_register: false }),
];
const brokers = [
  { id: "a", type: "broker", name: "ACACE SARL", initials: "AS", city: null, regulator_number: 1, canonical_id: "CM-BRK-2026-001", licensed: true },
  { id: "b", type: "broker", name: "ASCOMA", initials: "A", city: "Douala", regulator_number: 15, canonical_id: "CM-BRK-2026-015", licensed: true },
];

test("filterInsurers splits IARD vs LIFE and searches name, short name and families", () => {
  assert.deepEqual(filterInsurers(insurers, "IARD", "").map((i) => i.short_name), ["CHANAS", "ACTIVA"]);
  assert.deepEqual(filterInsurers(insurers, "LIFE", "").map((i) => i.short_name), ["CHANAS VIE"]);
  assert.equal(filterInsurers(insurers, "all", "").length, 4);
  assert.deepEqual(filterInsurers(insurers, "all", "santé").map((i) => i.short_name), ["CHANAS"]);
  assert.deepEqual(filterInsurers(insurers, "all", "chanas").map((i) => i.short_name), ["CHANAS", "CHANAS VIE"]);
});

test("filterBrokers matches name, city or exact regulator number", () => {
  assert.deepEqual(filterBrokers(brokers, "ascoma").map((b) => b.id), ["b"]);
  assert.deepEqual(filterBrokers(brokers, "15").map((b) => b.id), ["b"]);
  assert.deepEqual(filterBrokers(brokers, "douala").map((b) => b.id), ["b"]);
  assert.equal(filterBrokers(brokers, "").length, 2);
});

test("registerCounts counts only official register rows", () => {
  assert.deepEqual(registerCounts(insurers), { total: 3, IARD: 2, LIFE: 1 });
});

test("register strings exist in EN and FR with the official source note", () => {
  for (const key of [
    REGISTER_SOURCE_KEY,
    "licensedInsurers",
    "authorizedBrokers",
    "branchIARD",
    "branchLIFE",
    "publishedFamiliesUnverified",
    "regulatorNumber",
    "exploreRegisterEntry",
  ]) {
    assert.ok(en[key], `en.${key}`);
    assert.ok(fr[key], `fr.${key}`);
  }
  assert.match(en[REGISTER_SOURCE_KEY], /DGTCFM\/MINFI/);
  assert.match(fr[REGISTER_SOURCE_KEY], /DGTCFM\/MINFI/);
  assert.match(en.publishedFamiliesUnverified, /unverified/i);
});

test("institution screens show counts, branch tabs, families and the source note", () => {
  const list = read("app/institutions/insurers.tsx");
  assert.match(list, /licensedInsurers/);
  assert.match(list, /branchIARD/);
  assert.match(list, /branchLIFE/);
  assert.match(list, /REGISTER_SOURCE_KEY/);
  const detail = read("app/institutions/insurer/[id].tsx");
  assert.match(detail, /publishedFamiliesUnverified/);
  assert.match(detail, /canonical_id/);
  const brokerList = read("app/institutions/brokers.tsx");
  assert.match(brokerList, /authorizedBrokers/);
  assert.match(brokerList, /regulator_number/);
  assert.match(read("app/(customer)/(tabs)/explore.tsx"), /exploreRegisterEntry/);
});

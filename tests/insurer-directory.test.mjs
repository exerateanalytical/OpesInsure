import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import {
  directoryCities,
  filterByCity,
  filterInsurers,
  groupBranchesByCity,
  readDirectory,
  telUrl,
  verificationBadge,
  verificationText,
} from "../src/lib/institutions.ts";
import { en } from "../src/i18n/en.ts";
import { fr } from "../src/i18n/fr.ts";

const root = fileURLToPath(new URL("..", import.meta.url));
const read = (p) => readFileSync(join(root, p), "utf8");

const activa = {
  id: "1", name: "ACTIVA ASSURANCES", branch: "IARD", city: null, is_official_register: true,
  contacts: { phones: ["+237 233 50 13 00", "+237 233 50 13 00"], emails: ["service.clients@group-activa.com"] },
  website: "cameroun.group-activa.com",
  head_office: { city: "Douala", address: "Rue Prince de Galles, Akwa", po_box: "12970 Douala" },
  verification_label: { en: "Verified by OpesInsure", fr: "Vérifié par OpesInsure" },
  branches: [
    { name: "Agence Yaoundé", city: "Yaoundé", address: "Bastos", phone: "+237 222 20 00 00" },
    { name: "Bureau Grand Mall", city: "Douala", address: "Grand Mall", phone: null },
    { name: "Point de vente", city: null, address: "Route X" },
    "garbage",
  ],
  verification_status: "VERIFIED_HQ_BRANCHES_PENDING",
  sources: ["https://cameroun.group-activa.com/", { url: "https://cima-afrique.org" }],
};
const bare = { id: "2", name: "Chanas Vie", branch: "LIFE", city: "Yaoundé", phone: "222 33 44 55" };

test("readDirectory reads nested contacts, dedupes, and normalises website", () => {
  const d = readDirectory(activa);
  assert.deepEqual(d.phones, ["+237 233 50 13 00"]);
  assert.deepEqual(d.emails, ["service.clients@group-activa.com"]);
  assert.equal(d.website, "https://cameroun.group-activa.com");
  assert.equal(d.hq.po_box, "12970 Douala");
  assert.equal(d.branches.length, 3);
  assert.deepEqual(d.sources, ["https://cameroun.group-activa.com/", "https://cima-afrique.org"]);
});

test("readDirectory hides missing sections and tolerates junk", () => {
  for (const row of [bare, null, {}, { hq: {}, branches: "x", contacts: [] }]) {
    const d = readDirectory(row);
    assert.equal(d.hq, null);
    assert.deepEqual(d.branches, []);
    assert.equal(d.verification, null);
  }
  assert.deepEqual(readDirectory(bare).phones, ["222 33 44 55"]);
  assert.deepEqual(readDirectory({ phones: ["1"], emails: ["a@b.c"] }).emails, ["a@b.c"]);
});

test("verification statuses map to badges", () => {
  assert.equal(verificationBadge("VERIFIED").key, "verificationVerified");
  assert.equal(verificationBadge("PARTIALLY_VERIFIED").key, "verificationPartial");
  assert.equal(verificationBadge("VERIFIED_HQ_BRANCHES_PENDING").key, "verificationHqBranchesPending");
  assert.equal(verificationBadge("VERIFIED_NETWORK_SHARED_WITH_GROUP").key, "verificationGroupNetwork");
  assert.equal(verificationBadge("???"), null);
  assert.equal(verificationBadge(undefined), null);
});

test("verification_label from the API wins over built-in copy", () => {
  const v = readDirectory(activa).verification;
  assert.equal(v.key, "verificationHqBranchesPending");
  assert.equal(verificationText(v, "fr", (k) => en[k]), "Vérifié par OpesInsure");
  assert.equal(verificationText(v, "en", (k) => en[k]), "Verified by OpesInsure");
  const noLabel = verificationBadge("VERIFIED", null);
  assert.equal(verificationText(noLabel, "fr", (k) => fr[k]), fr.verificationVerified);
  // A label without a known status still renders (admins may add new statuses).
  assert.equal(verificationBadge("NEW_STATUS", { en: "Custom" }).label.en, "Custom");
  // Draft `hq` key and contacts.po_box are still accepted.
  assert.equal(readDirectory({ hq: { city: "Buea" } }).hq.city, "Buea");
  assert.equal(readDirectory({ contacts: { po_box: "99" } }).hq.po_box, "99");
});

test("telUrl strips formatting; branches group by city with unknown last", () => {
  assert.equal(telUrl("+237 233 50 13 00"), "tel:+237233501300");
  assert.equal(telUrl("8033"), "tel:8033");
  assert.equal(telUrl("n/a"), null);
  const g = groupBranchesByCity(readDirectory(activa).branches);
  assert.deepEqual(g.map((x) => x.city), ["Douala", "Yaoundé", null]);
});

test("city filter and search cover HQ, legacy city and branch cities", () => {
  const rows = [activa, bare];
  assert.deepEqual(directoryCities(rows), ["Douala", "Yaoundé"]);
  assert.deepEqual(filterByCity(rows, "Douala").map((r) => r.id), ["1"]);
  assert.deepEqual(filterByCity(rows, "yaounde").map((r) => r.id), ["1", "2"]);
  assert.equal(filterByCity(rows, null).length, 2);
  assert.deepEqual(filterInsurers(rows, "LIFE", "").map((r) => r.id), ["2"]);
  assert.deepEqual(filterInsurers(rows, "all", "douala").map((r) => r.id), ["1"]);
});

test("directory strings exist in EN and FR and screens use them", () => {
  for (const k of ["headOffice", "poBox", "branchNetwork", "cityAll", "verificationVerified",
    "verificationPartial", "verificationHqBranchesPending", "verificationGroupNetwork", "sendEmail", "callNumber"]) {
    assert.ok(en[k] && fr[k], k);
  }
  const detail = read("app/institutions/insurer/[id].tsx");
  for (const s of [/mailto:/, /telUrl/, /groupBranchesByCity/, /poBox/, /readDirectory/]) assert.match(detail, s);
  const list = read("app/institutions/insurers.tsx");
  for (const s of [/filterByCity/, /cityAll/, /StatePanel/, /verification/]) assert.match(list, s);
});

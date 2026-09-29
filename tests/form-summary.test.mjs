import test from "node:test";
import assert from "node:assert/strict";
import { anyFilled, formatLongDate, humanizeCode, sectionPayload, summarizeFields } from "../src/lib/formSummary.ts";

const copy = { other: "Other", yes: "Yes", no: "No" };
const regions = { CENTRE: "Centre", LITTORAL: "Littoral" };
const relationships = { SPOUSE: { en: "Spouse", fr: "Conjoint(e)" } };
const resolve = (f, code) =>
  f.master?.list === "cameroon_region" ? regions[code] : f.master?.list === "relationship" ? relationships[code]?.en : undefined;

const fields = [
  { key: "date_of_birth", label: "Date of birth", labelFr: "Date de naissance", type: "date" },
  { key: "occupation", label: "Occupation", type: "select_master", master: { domain: "occupations", list: "occupation" } },
  { key: "region", label: "Region", type: "select_master", master: { domain: "geography", list: "cameroon_region" } },
  { key: "department", label: "Department", type: "select_master", submit: false, master: { domain: "geography", list: "cameroon_department" } },
  { key: "city", label: "City / town", type: "select_master", master: { domain: "geography", list: "city" } },
  { key: "address_line1", label: "Street / landmark", type: "text" },
  {
    key: "beneficiaries",
    label: "Beneficiaries",
    type: "repeater",
    itemFields: [
      { key: "name", label: "Full name", type: "text" },
      { key: "relationship", label: "Relationship", type: "select_master", master: { domain: "persons", list: "relationship" } },
      { key: "share_percent", label: "Share (%)", type: "number" },
    ],
  },
];

test("dates read as words and never shift with the time zone", () => {
  assert.equal(formatLongDate("1990-03-12", "en"), "12 March 1990");
  assert.equal(formatLongDate("1990-03-12T23:30:00Z", "fr"), "12 mars 1990");
  assert.equal(formatLongDate("", "en"), null);
  assert.equal(formatLongDate("garbage", "en"), null);
});

test("codes become labels; unknown codes are humanized, typed text kept", () => {
  assert.equal(humanizeCode("SMALL_TRADER"), "Small trader");
  assert.equal(humanizeCode("Douala"), "Douala");
  assert.equal(humanizeCode("123"), "123");
  const values = {
    date_of_birth: "1990-03-12",
    occupation: "OTHER",
    occupation_other: "Tailor",
    region: "LITTORAL",
    department: "WOURI",
    city: "DOUALA",
    address_line1: "Rue 1",
    beneficiaries: JSON.stringify([{ name: "Marie Ndongo", relationship: "SPOUSE", share_percent: 100 }]),
  };
  const rows = summarizeFields(fields, values, "en", resolve, copy);
  const by = Object.fromEntries(rows.map((r) => [r.key, r]));
  assert.equal(by.department, undefined, "helper pickers are not summarized");
  assert.equal(by.date_of_birth.value, "12 March 1990");
  assert.equal(by.occupation.value, "Tailor");
  assert.equal(by.region.value, "Littoral");
  assert.equal(by.city.value, "Douala");
  assert.equal(by.address_line1.value, "Rue 1");
  assert.deepEqual(by.beneficiaries.items, ["Marie Ndongo · Spouse · 100%"]);
});

test("empty values are null so the screen can prompt to add them", () => {
  const rows = summarizeFields(fields, { beneficiaries: "[]" }, "fr", resolve, copy);
  assert.ok(rows.every((r) => r.value === null));
  assert.equal(rows[0].label, "Date de naissance");
  assert.equal(anyFilled({ beneficiaries: "[]", city: " " }, ["beneficiaries", "city"]), false);
  assert.equal(anyFilled({ city: "DOUALA" }, ["city"]), true);
  assert.equal(anyFilled(null, ["city"]), false);
});

test("section payload keeps only the section's fields and clears emptied ones", () => {
  const payload = { date_of_birth: "1990-03-12", occupation: "OTHER", occupation_other: "Tailor", beneficiaries: [{ name: "X" }] };
  const only = ["date_of_birth", "occupation", "region", "department", "city", "address_line1"];
  assert.deepEqual(sectionPayload(payload, fields, only), {
    date_of_birth: "1990-03-12",
    occupation: "OTHER",
    occupation_other: "Tailor",
    region: null,
    city: null,
    address_line1: null,
  });
  assert.deepEqual(sectionPayload({}, fields, ["beneficiaries"]), { beneficiaries: [] });
});

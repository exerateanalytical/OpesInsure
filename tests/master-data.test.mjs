import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
// Dependency-free TypeScript, loaded with Node's type stripping.
import {
  allocationTotals,
  masterFacts,
  narrowByParent,
  normalizeText,
  searchMasterValues,
  validateMasterField,
  validateRepeater,
  visibleIf,
} from "../src/lib/masterFields.ts";
import { buildFacts, normalizeRiskSchema, validateStep } from "../src/lib/riskSchema.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const v = (code, en, fr, extra = {}) => ({ code, label: { en, fr }, ...extra });
const occupations = [
  v("MEDICAL_DOCTOR", "Medical doctor", "Médecin", { aliases: ["Physician", "Doctor"], parent: "ISCO_22" }),
  v("NURSE", "Nurse", "Infirmier / infirmière", { parent: "ISCO_22" }),
  v("ACCOUNTANT", "Accountant", "Comptable", { parent: "ISCO_24" }),
  v("MOTORCYCLE_TAXI_DRIVER", "Motorcycle taxi driver", "Conducteur de moto-taxi", { aliases: ["Benskineur"], parent: "ISCO_83" }),
  v("OTHER", "Other / Not listed", "Autre / Non répertorié", { is_other: true }),
];

test("search is accent-insensitive, bilingual, alias- and typo-tolerant with Other last", () => {
  assert.equal(normalizeText("Médecin"), "medecin");
  assert.equal(searchMasterValues(occupations, "medecin", "en")[0].code, "MEDICAL_DOCTOR");
  assert.equal(searchMasterValues(occupations, "physician", "fr")[0].code, "MEDICAL_DOCTOR");
  assert.equal(searchMasterValues(occupations, "benskineur", "fr")[0].code, "MOTORCYCLE_TAXI_DRIVER");
  assert.equal(searchMasterValues(occupations, "acountant", "en")[0].code, "ACCOUNTANT");
  const all = searchMasterValues(occupations, "", "en");
  assert.equal(all.length, occupations.length, "an empty query never hides values");
  assert.equal(all.at(-1).code, "OTHER");
  assert.equal(searchMasterValues(occupations, "zzzz", "en").at(-1).code, "OTHER");
});

test("hierarchies narrow children by parent, including multi-parent values", () => {
  assert.deepEqual(narrowByParent(occupations, "ISCO_22").map((x) => x.code), ["MEDICAL_DOCTOR", "NURSE", "OTHER"]);
  const makers = [v("CATERPILLAR", "Caterpillar", "Caterpillar", { parent: "EXCAVATOR", attributes: { categories: ["GENERATOR"] } })];
  assert.equal(narrowByParent(makers, "GENERATOR").length, 1);
});

test("visible_if supports equality, value lists, not_empty and contains", () => {
  assert.equal(visibleIf({ plan_type: "GROUP_CORPORATE" }, { plan_type: "FAMILY" }), false);
  assert.equal(visibleIf({ transport_mode: ["SEA", "MULTIMODAL"] }, { transport_mode: "SEA" }), true);
  assert.equal(visibleIf({ capacity: { not_empty: true } }, { capacity: "" }), false);
  assert.equal(visibleIf({ travel_options: { contains: "HIGH_RISK_SPORTS" } }, { travel_options: '["BAGGAGE","HIGH_RISK_SPORTS"]' }), true);
  assert.equal(visibleIf({ life_assured_is_policyholder: false }, { life_assured_is_policyholder: "false" }), true);
});

test("Other / Not listed requires the typed value and is submitted with it", () => {
  const f = { key: "building_type", label: "Property type", type: "select_master", required: true, otherAllowed: true };
  assert.match(validateMasterField(f, {}), /required/);
  assert.match(validateMasterField(f, { building_type: "OTHER" }), /not listed/);
  assert.equal(validateMasterField(f, { building_type: "OTHER", building_type_other: "Case" }), null);
  assert.deepEqual(masterFacts(f, { building_type: "OTHER", building_type_other: " Case " }), { building_type: "OTHER", building_type_other: "Case" });
  const multi = { key: "security_measures", label: "Security", type: "multi_select_master", required: true };
  assert.deepEqual(masterFacts(multi, { security_measures: '["CCTV","ALARM_SYSTEM"]' }), { security_measures: ["CCTV", "ALARM_SYSTEM"] });
});

test("beneficiary shares must total 100% per rank", () => {
  const field = {
    key: "beneficiaries", label: "Beneficiaries", type: "repeater", required: true, minItems: 1,
    allocation: { field: "share_pct", total: 100, group_by: "priority" },
    itemFields: [
      { key: "full_name", label: "Name", type: "text", required: true },
      { key: "relationship", label: "Relationship", type: "select_master", required: true },
      { key: "priority", label: "Rank", type: "select_master", required: true },
      { key: "share_pct", label: "Share", type: "number", required: true, min: 1, max: 100 },
    ],
  };
  const item = (name, prio, pct) => ({ full_name: name, relationship: "CHILD", priority: prio, share_pct: String(pct) });
  assert.match(validateRepeater(field, "[]").error, /at least 1/);
  assert.match(validateRepeater(field, JSON.stringify([item("A", "PRIMARY", 60), item("B", "PRIMARY", 30)])).error, /100% — currently 90%/);
  assert.equal(validateRepeater(field, JSON.stringify([item("A", "PRIMARY", 50), item("B", "PRIMARY", 50), item("C", "CONTINGENT", 100)])).error, null);
  assert.deepEqual(allocationTotals(field.allocation, [item("A", "PRIMARY", 33.33), item("B", "PRIMARY", 66.67)]), { PRIMARY: 100 });
  const facts = masterFacts(field, { beneficiaries: JSON.stringify([item("Ada", "PRIMARY", 100)]) });
  assert.deepEqual(facts.beneficiaries, [{ full_name: "Ada", relationship: "CHILD", priority: "PRIMARY", share_pct: 100 }]);
});

test("server master-data schemas parse into pickers, repeaters and conditional fields", () => {
  const payload = { data: {
    line_code: "LIFE", version: 2,
    steps: [{ key: "product", label: "Product", label_fr: "Produit" }, { key: "beneficiaries", label: "Beneficiaries" }],
    fields: [
      { key: "product_type", label: "Life product", label_fr: "Produit vie", type: "select_master", step: "product", required: true, source: { domain: "life", list: "product_type" }, other_allowed: true },
      { key: "life_assured_is_policyholder", label: "I am insured", type: "boolean", step: "product", required: true },
      { key: "life_assured_name", label: "Life assured", type: "text", step: "product", required: true, visible_if: { life_assured_is_policyholder: false } },
      { key: "beneficiaries", label: "Beneficiaries", type: "repeater", step: "beneficiaries", required: true, min_items: 1, allocation: { field: "share_pct", total: 100, group_by: "priority" },
        item_fields: [{ key: "full_name", label: "Name", type: "text", required: true }, { key: "priority", label: "Rank", type: "select_master", required: true, source: { domain: "life", list: "beneficiary_priority" } }, { key: "share_pct", label: "Share", type: "number", required: true }] },
    ],
  } };
  const schema = normalizeRiskSchema(payload, "LIFE");
  const [product, bens] = schema.steps;
  assert.equal(product.titleFr, "Produit");
  assert.deepEqual(product.fields[0].master, { domain: "life", list: "product_type" });
  assert.equal(product.fields[0].labelFr, "Produit vie");
  assert.equal(bens.fields[0].type, "repeater");
  assert.equal(bens.fields[0].itemFields[1].master.list, "beneficiary_priority");

  // Hidden fields are neither validated nor submitted.
  const errs = validateStep(product, { product_type: "TERM_LIFE", life_assured_is_policyholder: "true" });
  assert.deepEqual(errs, {});
  assert.ok(validateStep(product, { product_type: "TERM_LIFE", life_assured_is_policyholder: "false" }).life_assured_name);
  const facts = buildFacts(schema, { product_type: "TERM_LIFE", life_assured_is_policyholder: "true", life_assured_name: "X",
    beneficiaries: JSON.stringify([{ full_name: "Ada", priority: "PRIMARY", share_pct: "100" }]) });
  assert.equal(facts.product_type, "TERM_LIFE");
  assert.equal(facts.life_assured_name, undefined);
  assert.deepEqual(facts.beneficiaries, [{ full_name: "Ada", priority: "PRIMARY", share_pct: 100 }]);
});

test("master fields without a list fall back to free text", () => {
  const schema = normalizeRiskSchema({ steps: [{ key: "a", label: "A" }], fields: [{ key: "x", label: "X", type: "select_master", step: "a" }] }, "HOME");
  assert.equal(schema.steps[0].fields[0].type, "text");
});

test("EN and FR copy exist for every master-data string, and the wizard renders the pickers", () => {
  const en = read("src/i18n/en.ts");
  const fr = read("src/i18n/fr.ts");
  for (const key of ["mdOther", "mdSearch", "mdOtherHint", "mdUseThisValue", "mdAllocationTotal", "mdAdd", "mdLoadFailed", "mdNoMatch"]) {
    assert.ok(en.includes(`${key}:`), `en ${key}`);
    assert.ok(fr.includes(`${key}:`), `fr ${key}`);
  }
  assert.ok(fr.includes("Autre / Non répertorié"));
  const risk = read("app/quote/risk.tsx");
  assert.ok(risk.includes("<MasterSelectField") && risk.includes("<RepeaterField"));
  assert.ok(risk.includes("<VehiclePicker"), "vehicle picker untouched");
  const cache = read("src/lib/masterData.ts");
  assert.ok(cache.includes("/master-data/versions") && cache.includes("catalog_version"), "offline cache syncs on catalogue versions");
});

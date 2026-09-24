import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  filterByQuery,
  manualReviewPayload,
  modelYears,
  normalizeMakes,
  normalizeModels,
  normalizeReference,
  selectionFromManual,
  selectionToValues,
  validateManualEntry,
  emptyManualEntry,
} from "../src/lib/vehicles.ts";
import { buildFacts, localRiskSchema, normalizeRiskSchema, usageTypeFor, validateStep } from "../src/lib/riskSchema.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("vehicle payloads normalize from the public API shapes", () => {
  const makes = normalizeMakes({ data: [{ code: "TOYOTA", name: "Toyota", aliases: [], models_count: 25 }, { code: "", name: "x" }, null], meta: { total: 1 } });
  assert.deepEqual(makes.map((m) => m.code), ["TOYOTA"]);
  assert.equal(makes[0].models_count, 25);
  const models = normalizeModels({ make: { code: "TOYOTA" }, data: [{ code: "TOYOTA_LAND_CRUISER_PRADO", name: "Land Cruiser Prado", aliases: ["Prado", "LC Prado"] }] });
  assert.equal(models[0].aliases[0], "Prado");
  for (const junk of [null, undefined, "x", 5, {}]) assert.deepEqual(normalizeMakes(junk), []);

  const ref = normalizeReference({ data: { body_types: [{ code: "HATCHBACK", label: { en: "Hatchback", fr: "Berline à hayon" } }], model_years: { min: 1950, max: 2027 } } }, "fr");
  assert.equal(ref.body_types[0].label, "Berline à hayon");
  assert.deepEqual(ref.model_years, { min: 1950, max: 2027 });
  assert.equal(normalizeReference(null, "en", new Date("2026-06-01")).model_years.max, 2027);
});

test("model years run newest first from current+1 down to 1950", () => {
  const years = modelYears(undefined, new Date("2026-09-24"));
  assert.equal(years[0], "2027");
  assert.equal(years.at(-1), "1950");
  assert.equal(years.length, 78);
});

test("model search matches names and aliases (Prado → Land Cruiser Prado)", () => {
  const models = [
    { code: "TOYOTA_LAND_CRUISER", name: "Land Cruiser", aliases: [] },
    { code: "TOYOTA_LAND_CRUISER_PRADO", name: "Land Cruiser Prado", aliases: ["Prado", "LC Prado"] },
    { code: "TOYOTA_HILUX", name: "Hilux", aliases: ["Hi-Lux"] },
  ];
  assert.equal(filterByQuery(models, "prado")[0].code, "TOYOTA_LAND_CRUISER_PRADO");
  assert.equal(filterByQuery(models, "hi lux")[0].code, "TOYOTA_HILUX");
  assert.equal(filterByQuery(models, "").length, 3);
  assert.deepEqual(filterByQuery(models, "zzz"), []);
});

test("manual 'not listed' entry validates, builds the review payload and a snapshot selection", () => {
  const range = { min: 1950, max: 2027 };
  const empty = validateManualEntry(emptyManualEntry(), range);
  assert.equal(empty.make, "vehicleErrRequired");
  assert.equal(empty.model, "vehicleErrRequired");
  assert.equal(empty.year, "vehicleErrRequired");
  const entry = { ...emptyManualEntry(), make: " Zotye ", model: "T600", year: "2018", body_type: "SUV", vin: "lj123456", registration_number: "lt 123 ab", powertrain: "PETROL", vehicle_usage: "TAXI" };
  assert.deepEqual(validateManualEntry(entry, range), {});
  assert.equal(validateManualEntry({ ...entry, year: "1949" }, range).year, "vehicleErrYear");
  assert.equal(validateManualEntry({ ...entry, vin: "a!" }, range).vin, "vehicleErrVin");

  const payload = manualReviewPayload(entry, "asset-1");
  assert.deepEqual(payload, { make: "Zotye", model: "T600", model_year: 2018, body_type: "SUV", powertrain: "PETROL", usage: "TAXI", vin: "LJ123456", registration_number: "LT 123 AB", risk_asset_id: "asset-1" });
  const sel = selectionFromManual(entry, "rev-1");
  assert.equal(sel.manual, true);
  const values = selectionToValues(sel);
  assert.equal(values.make, "Zotye");
  assert.equal(values.make_code, "");
  assert.equal(values.vehicle_review_id, "rev-1");
});

test("MOTOR schema: make/model selectors, codes + snapshot facts, usage_type derived, conditional fields", () => {
  const server = normalizeRiskSchema(
    {
      data: {
        line_code: "MOTOR",
        steps: [{ key: "vehicle", label: "Vehicle" }, { key: "usage", label: "Usage" }],
        fields: [
          { key: "make_code", label: "Make", type: "VEHICLE_MAKE", step: "vehicle", required: true, text_key: "make", source: "/api/v1/public/vehicles/makes" },
          { key: "model_code", label: "Model", type: "VEHICLE_MODEL", step: "vehicle", required: true, text_key: "model", depends_on: "make_code" },
          { key: "battery_capacity_kwh", label: "Battery", type: "number", step: "vehicle", visible_when: { powertrain: ["PHEV", "BEV"] } },
          { key: "vehicle_usage", label: "Usage", type: "select", step: "usage", required: true, options: [{ value: "PRIVATE_PERSONAL", label: "Private" }, { value: "TAXI", label: "Taxi" }] },
        ],
      },
    },
    "MOTOR",
  );
  assert.equal(server.source, "server");
  assert.deepEqual(server.steps.map((s) => s.title), ["Vehicle", "Usage"]);
  const [make, model, battery] = server.steps[0].fields;
  assert.equal(make.type, "vehicle_make");
  assert.equal(make.textKey, "make");
  assert.equal(model.dependsOn, "make_code");
  assert.deepEqual(battery.visibleWhen, { powertrain: ["PHEV", "BEV"] });

  assert.ok(validateStep(server.steps[0], {}).make_code);
  // A manual entry (text only, pending review) satisfies the selector.
  assert.deepEqual(validateStep(server.steps[0], { make: "Zotye", model: "T600" }), {});

  const facts = buildFacts(server, { make_code: "TOYOTA", make: "Toyota", model_code: "TOYOTA_LAND_CRUISER_PRADO", model: "Land Cruiser Prado", battery_capacity_kwh: "60", vehicle_usage: "TAXI" });
  assert.equal(facts.make_code, "TOYOTA");
  assert.equal(facts.model, "Land Cruiser Prado");
  assert.equal(facts.battery_capacity_kwh, undefined, "hidden EV field is not submitted");
  assert.equal(facts.usage_type, "COMMERCIAL");
  assert.equal(usageTypeFor("PRIVATE_FAMILY"), "PRIVATE");

  const local = localRiskSchema("MOTOR");
  const keys = local.steps.flatMap((s) => s.fields.map((f) => f.key));
  for (const k of ["make_code", "model_code", "year", "body_type", "powertrain", "transmission", "vehicle_usage", "vehicle_class", "vin", "registration_number"]) assert.ok(keys.includes(k), k);
  const year = local.steps[0].fields.find((f) => f.key === "year");
  assert.equal(year.options.at(-1).value, "1950");
});

test("vehicle picker is wired into the quote wizard and the add-vehicle screen with EN/FR copy", () => {
  const client = read("src/api/client.ts");
  for (const path of ["/public/vehicles/makes", "/public/vehicles/reference", "/mobile/vehicles/master-review"]) assert.ok(client.includes(path), path);
  assert.match(read("app/quote/risk.tsx"), /VehiclePicker/);
  assert.match(read("app/assets/new.tsx"), /VehiclePicker/);
  assert.match(read("app/assets/new.tsx"), /createVehicle/);
  const picker = read("src/components/vehicles/VehiclePicker.tsx");
  for (const s of ["vehicleCantFind", "vehicleChineseChip", "vehicleLoadingMakes", "vehicleLoadError", "vehicleNoMakes", "chinese"]) assert.ok(picker.includes(s), s);
  assert.match(read("src/i18n/en.ts"), /vehicleCantFind: "Can't find your vehicle\?"/);
  assert.match(read("src/i18n/fr.ts"), /vehicleCantFind: "Vous ne trouvez pas votre véhicule \?"/);
});

import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
// Dependency-free TypeScript, loaded with Node's type stripping.
import { allFields, buildFacts, clearedDependents, normalizeRiskSchema, parseContractField, resolveDateBound, validateField, validateStep } from "../src/lib/riskSchema.ts";
import { apiPath, buildFormPayload, composeValue, endpointValues, initialFormValues, normalizeFormSchema, parseSubmitTo, profileToValues } from "../src/lib/inputForms.ts";
import { environmentBanner } from "../src/lib/environmentBanner.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
// Snapshot of the live GET /api/v1/forms/{form} contracts (InputFieldContract v1).
const forms = JSON.parse(read("tests/fixtures/forms-contract-v1.json"));
const field = (schema, key) => allFields(schema).find((f) => f.key === key);

test("form contracts parse: source.parent is the parent field, allow_other, helpers, compose, endpoint, free_text, date bounds", () => {
  const fnol = normalizeFormSchema({ data: forms.claim_fnol }, "claim_fnol");
  assert.deepEqual(fnol.submitTo, { method: "POST", path: "/mobile/claims" });
  assert.deepEqual(fnol.steps.map((s) => s.key), ["incident", "location", "details"]);
  const cause = field(fnol, "incident_type");
  assert.deepEqual(cause.master, { domain: "claims", list: "cause_of_loss" });
  assert.equal(cause.parentField, "claim_category");
  assert.equal(cause.otherAllowed, true);
  const category = field(fnol, "claim_category");
  assert.equal(category.submit, false);
  assert.equal(category.otherAllowed, false, "closed helper picker");
  const policy = field(fnol, "policy_id");
  assert.equal(policy.type, "select", "an endpoint picker never falls back to a text box");
  assert.equal(policy.endpoint, "/api/v1/policies");
  assert.equal(policy.valueKey, "id");
  const when = field(fnol, "incident_at");
  assert.equal(when.withTime, true);
  assert.equal(when.dateMax, "now");
  assert.equal(field(fnol, "incident_city").composeInto, "incident_location");
  assert.deepEqual(field(fnol, "incident_location").composeFrom, ["incident_city", "incident_department", "incident_region"]);
  const description = field(fnol, "description");
  assert.equal(description.freeText, "NARRATIVE");
  assert.equal(description.multiline, true);
  assert.equal(description.minLength, 10);
  assert.equal(description.maxLength, 5000);
  assert.equal(field(fnol, "estimated_loss_minor").min, 0);

  const profile = normalizeFormSchema(forms.customer_profile, "customer_profile");
  assert.deepEqual(profile.submitTo, { method: "PATCH", path: "/mobile/account/customer-profile" });
  const dob = field(profile, "date_of_birth");
  assert.equal(dob.dateMin, "1900-01-01");
  assert.equal(dob.dateMax, "today");
  const beneficiaries = field(profile, "beneficiaries");
  assert.equal(beneficiaries.type, "repeater");
  assert.equal(beneficiaries.itemFields.find((f) => f.key === "relationship").master.list, "relationship");
  assert.equal(beneficiaries.itemFields.find((f) => f.key === "name").freeText, "PERSON_NAME");

  const kyc = normalizeFormSchema(forms.kyc_identifier, "kyc_identifier");
  assert.equal(field(kyc, "identifier_country").defaultValue, "CM");
  assert.equal(initialFormValues(kyc).identifier_country, "CM");
  assert.equal(initialFormValues(kyc, { identifier_country: "GA" }).identifier_country, "GA");
});

test("every text box in the served forms carries a free_text reason", () => {
  for (const [name, raw] of Object.entries(forms)) {
    const schema = normalizeFormSchema(raw, name);
    const walk = (fields) =>
      fields.forEach((f) => {
        if (f.type === "text") assert.ok(f.freeText, `${name}.${f.key} renders a text box without free_text`);
        if (f.itemFields) walk(f.itemFields);
      });
    walk(allFields(schema));
  }
});

test("changing a parent clears its children recursively (region -> department -> city), Other text included", () => {
  const profile = normalizeFormSchema(forms.customer_profile, "customer_profile");
  assert.deepEqual(clearedDependents(allFields(profile), "region"), { department: "", department_other: "", city: "", city_other: "" });
  assert.deepEqual(clearedDependents(allFields(profile), "city"), {});
  const fnol = normalizeFormSchema(forms.claim_fnol, "claim_fnol");
  assert.deepEqual(Object.keys(clearedDependents(allFields(fnol), "claim_category")), ["incident_type", "incident_type_other"]);
});

test("FNOL payload: helpers dropped, location composed as Landmark, City, Department, Region; money in minor units", () => {
  const fnol = normalizeFormSchema(forms.claim_fnol, "claim_fnol");
  const values = {
    policy_id: "pol-1",
    claim_category: "MOTOR",
    incident_type: "OTHER",
    incident_type_other: "Hit by a falling mango tree",
    incident_at: "2026-09-24T10:30:00+01:00",
    severity: "MINOR",
    incident_region: "LITTORAL",
    incident_department: "WOURI",
    incident_city: "DOUALA",
    incident_location: "Near Akwa roundabout",
    police_report_filed: "false",
    police_reference: "should be dropped",
    estimated_loss_minor: "250000",
    description: "Tree fell on the car during the storm.",
  };
  const labels = { incident_region: "Littoral", incident_department: "Wouri", incident_city: "Douala" };
  const payload = buildFormPayload(fnol, values, labels);
  assert.deepEqual(payload, {
    policy_id: "pol-1",
    incident_type: "OTHER",
    incident_type_other: "Hit by a falling mango tree",
    incident_at: "2026-09-24T10:30:00+01:00",
    incident_location: "Near Akwa roundabout, Douala, Wouri, Littoral",
    police_report_filed: false,
    estimated_loss_minor: 25000000,
    description: "Tree fell on the car during the storm.",
  });
  // An "Other" city contributes its typed text; no landmark leaves just the places.
  const target = field(fnol, "incident_location");
  assert.equal(composeValue(target, allFields(fnol), { incident_city: "OTHER", incident_city_other: "Bonabéri", incident_region: "LITTORAL" }, { incident_region: "Littoral" }), "Bonabéri, Littoral");
});

test("profile payload keeps names as typed and types beneficiary shares; the server profile prefills the form", () => {
  const profile = normalizeFormSchema(forms.customer_profile, "customer_profile");
  const values = {
    date_of_birth: "1990-05-21",
    occupation: "OTHER",
    occupation_other: "Moto-taxi dispatcher",
    region: "CENTRE",
    department: "MFOUNDI",
    city: "YAOUNDE",
    address_line1: "Rue 1.234, Bastos",
    beneficiaries: JSON.stringify([
      { name: "Awa Ndiaye", relationship: "SPOUSE", share_percent: "60" },
      { name: "Paul Ndiaye", relationship: "OTHER", relationship_other: "Godson", share_percent: "40" },
    ]),
  };
  const payload = buildFormPayload(profile, values);
  assert.equal(payload.department, undefined, "helper department is not sent");
  assert.equal(payload.address_line1, "Rue 1.234, Bastos", "landmark keeps its case");
  assert.equal(payload.occupation_other, "Moto-taxi dispatcher");
  assert.deepEqual(payload.beneficiaries, [
    { name: "Awa Ndiaye", relationship: "SPOUSE", share_percent: 60 },
    { name: "Paul Ndiaye", relationship: "OTHER", relationship_other: "Godson", share_percent: 40 },
  ]);
  const errors = Object.assign({}, ...profile.steps.map((s) => validateStep(s, { ...values, beneficiaries: JSON.stringify([{ name: "A", relationship: "SPOUSE", share_percent: "50" }]) })));
  assert.match(errors.beneficiaries, /total 100/);

  const seeded = profileToValues({ date_of_birth: "1990-05-21T00:00:00Z", occupation: "NURSE", city: "DOUALA", region: "LITTORAL", address_line1: null, beneficiaries: [{ name: "Awa", relationship: "SPOUSE", share_percent: 100 }] });
  assert.equal(seeded.date_of_birth, "1990-05-21");
  assert.deepEqual(JSON.parse(seeded.beneficiaries), [{ name: "Awa", relationship: "SPOUSE", share_percent: "100" }]);
});

test("date bounds: today / now resolve at validation time; min/max_length and numeric bounds are enforced (EN/FR)", () => {
  const now = new Date(2026, 8, 25, 12, 0, 0);
  assert.equal(resolveDateBound("today", now), "2026-09-25");
  assert.equal(resolveDateBound("1900-01-01", now), "1900-01-01");
  const departure = parseContractField({ key: "departure_date", type: "date", min: "today", required: true });
  assert.match(validateField(departure, "2000-01-01"), /today or a later date/);
  assert.equal(validateField(departure, "2999-01-01"), null);
  const dob = parseContractField({ key: "dob", type: "date", min: "1900-01-01", max: "today" });
  assert.match(validateField(dob, "2999-01-01", "fr"), /futur/);
  assert.match(validateField(dob, "1850-01-01"), /on or after 1900-01-01/);
  const at = parseContractField({ key: "incident_at", type: "date", with_time: true, max: "now" });
  assert.equal(validateField(at, "2026-01-01T08:00:00+01:00"), null);
  assert.match(validateField(at, new Date(Date.now() + 3_600_000).toISOString()), /future/);
  const text = parseContractField({ key: "description", type: "text", min_length: 10, max_length: 20, free_text: { reason: "NARRATIVE" } });
  assert.match(validateField(text, "short"), /At least 10/);
  assert.match(validateField(text, "x".repeat(21)), /Too long/);
  const share = parseContractField({ key: "share", type: "number", min: 0.01, max: 100 });
  assert.match(validateField(share, "0", "fr"), /au moins 0.01/);
});

test("risk-schema v3 fields: allow_other alone opens Other, vehicle generation/variant stay in the picker, endpoint+source insurer picker", () => {
  const schema = normalizeRiskSchema(
    {
      data: {
        line_code: "MOTOR",
        steps: [{ key: "vehicle", label: "Vehicle" }, { key: "history", label: "History" }],
        fields: [
          { key: "make_code", step: "vehicle", type: "VEHICLE_MAKE", source: "/api/v1/public/vehicles/makes", text_key: "make", required: true, allow_other: true },
          { key: "vehicle_generation_code", step: "vehicle", type: "VEHICLE_GENERATION", depends_on: "model_code", required: false },
          { key: "vehicle_variant_code", step: "vehicle", type: "VEHICLE_VARIANT", depends_on: "vehicle_generation_code", required: false },
          { key: "registration_number", step: "vehicle", type: "text", required: true, free_text: { reason: "IDENTIFIER" } },
          { key: "previous_insurer", step: "history", type: "select_master", source: { domain: "institutions", list: "insurer", parent: null, parent_code: null }, endpoint: "/api/v1/public/institutions?type=insurer", allow_other: true },
          { key: "cargo_type", step: "history", type: "select_master", source: { domain: "cargo", list: "cargo_category", parent: null, parent_code: "ROAD" }, allow_other: true },
        ],
      },
    },
    "MOTOR",
  );
  const f = (k) => allFields(schema).find((x) => x.key === k);
  assert.equal(f("vehicle_generation_code").type, "vehicle_generation");
  assert.equal(f("vehicle_variant_code").type, "vehicle_variant");
  assert.equal(f("previous_insurer").endpoint, "/api/v1/public/institutions?type=insurer");
  assert.equal(f("previous_insurer").otherAllowed, true);
  assert.equal(f("cargo_type").parentCode, "ROAD");
  assert.equal(f("cargo_type").parentField, undefined);
  const values = { make_code: "TOYOTA", make: "Toyota", vehicle_generation_code: "COROLLA_E210", registration_number: "lt 123 ab", previous_insurer: "OTHER", previous_insurer_other: "Mutuelle du Sud" };
  assert.deepEqual(validateStep(schema.steps[1], { previous_insurer: "OTHER" }).previous_insurer, "Describe the value that is not listed.");
  const facts = buildFacts(schema, values);
  assert.equal(facts.vehicle_generation_code, "COROLLA_E210");
  assert.equal(facts.registration_number, "LT 123 AB", "identifiers are upper-cased");
  assert.equal(facts.previous_insurer, "OTHER");
  assert.equal(facts.previous_insurer_other, "Mutuelle du Sud");
});

test("endpoint pickers read policies pages, catalogue lines and the insurer register", () => {
  assert.equal(apiPath("/api/v1/policies"), "/policies");
  assert.equal(apiPath("/api/v1/public/institutions?type=insurer"), "/public/institutions?type=insurer");
  assert.deepEqual(parseSubmitTo("PATCH /api/v1/mobile/kyc/profile"), { method: "PATCH", path: "/mobile/kyc/profile" });
  assert.equal(parseSubmitTo("nonsense"), null);
  const policies = endpointValues({ data: { current_page: 1, data: [{ id: "p1", policy_number: "POL-2026-001011", status: "ACTIVE" }, { id: "p2", policy_number: "POL-2026-000900", status: "EXPIRED" }] } }, "id");
  assert.deepEqual(policies.map((p) => [p.code, p.label.en, p.attributes.status]), [["p1", "POL-2026-001011", "ACTIVE"], ["p2", "POL-2026-000900", "EXPIRED"]]);
  const lines = endpointValues({ data: [{ id: "x", code: "HOME", name: { en: "Home", fr: "Habitation" } }] }, "code");
  assert.deepEqual(lines[0].label, { en: "Home", fr: "Habitation" });
  const insurers = endpointValues([{ id: "i1", name: "ACTIVA Assurances", short_name: "ACTIVA" }], "id");
  assert.ok(insurers[0].aliases.includes("ACTIVA"));
});

test("environment banner only when the runtime bootstrap sets one", () => {
  assert.equal(environmentBanner(undefined), null);
  assert.equal(environmentBanner({ name: "production", demo_mode: false, banner: null }), null);
  assert.deepEqual(environmentBanner({ name: "production", demo_mode: true, banner: "DEMO" }), { kind: "demo" });
  assert.deepEqual(environmentBanner({ banner: "STAGING" }), { kind: "label", banner: "STAGING" });
});

test("screens render the server forms with the shared renderer; timezone, canonical endpoints and EN/FR copy are wired", () => {
  assert.match(read("app/account/profile.tsx"), /form="customer_profile"/);
  assert.match(read("app/account/profile.tsx"), /<TimezonePicker/);
  assert.match(read("app/account/profile.tsx"), /updateCustomerProfile/);
  assert.match(read("app/onboarding/kyc.tsx"), /form="kyc_identifier"/);
  // Batch 4: the KYC case engine takes explicit purposes (ID_FRONT, ID_BACK, PASSPORT, PROOF_OF_ADDRESS, RCCM, NIU).
  assert.match(read("app/onboarding/kyc.tsx"), /KYC_DOCUMENT_PURPOSES/);
  assert.match(read("app/claim/new.tsx"), /form="claim_fnol"/);
  assert.match(read("app/agent/leads/new.tsx"), /form="agent_lead"/);
  const form = read("src/components/forms/SchemaForm.tsx");
  assert.match(form, /\/forms\/\$\{form\}/);
  assert.match(form, /<ContractField/);
  // One picker component: endpoint and timezone pickers reuse MasterSelectField through its loader.
  assert.match(read("src/components/forms/ContractField.tsx"), /loader=\{/);
  assert.match(read("src/components/TimezonePicker.tsx"), /<MasterSelectField/);
  const client = read("src/api/client.ts");
  for (const path of ['"/settings/timezones"', '"/me/settings"', '"/mobile/account/customer-profile"', '"/master-data/suggestions"']) assert.ok(client.includes(path), path);
  assert.match(read("src/i18n/index.ts"), /timeZone/);
  assert.match(read("src/components/AppRuntime.tsx"), /environmentBanner\(runtime\?\.environment\)/);
  assert.match(read("src/lib/masterData.ts"), /screen: input\.screen/);
  const en = read("src/i18n/en.ts");
  const fr = read("src/i18n/fr.ts");
  for (const key of ["formLoadFailed", "formFixErrors", "timezoneTitle", "timezoneDefault", "envBannerDemo", "kycSaveDocument", "leadSave"]) {
    assert.ok(en.includes(`  ${key}:`), `en ${key}`);
    assert.ok(fr.includes(`  ${key}:`), `fr ${key}`);
  }
});

test("carrier issuance never sends a policy number: the server allocates it, an optional carrier reference may be sent", () => {
  const screen = read("app/carrier/issuance.tsx");
  const partner = read("src/api/partner.ts");
  assert.doesNotMatch(screen, /policy_number:/, "no policy_number in the approve payload");
  assert.match(screen, /carrier_reference: carrierReference\.trim\(\)/);
  assert.match(screen, /t\("caPolicyIssued", \{ number: r\.policy_number \}\)/, "shows the server-allocated number");
  assert.match(partner, /approveIssuance: \(id: string, payload: \{ carrier_reference\?: string \} = \{\}\)/);
});

import test from "node:test";
import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import { reviewRows, summarizeFields } from "../src/lib/formSummary.ts";
import { inProgressItems, inProgressSeeAll } from "../src/lib/homeFeed.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const copy = { other: "Other", yes: "Yes", no: "No", money: (n) => `${new Intl.NumberFormat("fr-CM", { maximumFractionDigits: 0 }).format(n)} FCFA` };
const none = () => undefined;

test("review rows: money as FCFA, vehicle names, option labels, dates in words", () => {
  const fields = [
    { key: "make_code", label: "Make", type: "vehicle_make", textKey: "make" },
    { key: "model_code", label: "Model", type: "vehicle_model", textKey: "model" },
    { key: "cover_type", label: "Cover level", type: "select", options: [{ value: "COMPREHENSIVE", label: "Comprehensive" }] },
    { key: "vehicle_value_minor", label: "Declared vehicle value (FCFA)", type: "money" },
    { key: "start_date", label: "Cover start date", type: "date" },
    { key: "vin", label: "VIN", type: "text" },
  ];
  const values = { make_code: "TOY", make: "Toyota", model_code: "COR", model: "Corolla", cover_type: "COMPREHENSIVE", vehicle_value_minor: "5000000", start_date: "2026-03-12" };
  const by = Object.fromEntries(reviewRows(fields, values, "en", none, copy).map((r) => [r.key, r]));
  assert.equal(by.make_code.value, "Toyota");
  assert.equal(by.model_code.value, "Corolla");
  assert.equal(by.cover_type.value, "Comprehensive");
  assert.match(by.vehicle_value_minor.value, /^5\s000\s000 FCFA$/u);
  assert.equal(by.start_date.value, "12 March 2026");
  assert.equal(by.vin.value, null, "empty optional answers read as not provided");
});

test("review rows: only what the customer saw; yes/no answers flagged so a No is visible", () => {
  const fields = [
    { key: "previously_insured", label: "Was the vehicle insured before?", type: "boolean" },
    { key: "previous_insurer", label: "Previous insurer", type: "text", visibleWhen: { previously_insured: ["true"] } },
    { key: "hospitalised", label: "Hospitalised in the last 5 years?", type: "boolean" },
  ];
  const rows = reviewRows(fields, { previously_insured: "false", hospitalised: "true" }, "en", none, copy);
  assert.deepEqual(rows.map((r) => r.key), ["previously_insured", "hospitalised"], "hidden conditional field left out");
  assert.equal(rows[0].answer, "no");
  assert.equal(rows[0].value, "No");
  assert.equal(rows[1].answer, "yes");
  // summarizeFields (profile summaries) keeps listing every field, as before.
  assert.equal(summarizeFields(fields, {}, "en", none, copy).length, 3);
});

test("money without the app formatter still reads as FCFA", () => {
  const rows = reviewRows([{ key: "v", label: "Value", type: "money" }], { v: "1 500 000" }, "en", none, { other: "", yes: "", no: "" });
  assert.equal(rows[0].value, "1500000 FCFA");
});

test("Home In progress: an application awaiting payment is an urgent row", () => {
  const NOW = new Date("2026-09-29T09:00:00Z");
  const { items, kinds, total } = inProgressItems(
    {
      quotes: [{ id: "q1", status: "OFFERED", offer_count: 2, created_at: "2026-09-28T09:00:00Z" }],
      applications: [{ id: "a1", status: "APPROVED", updated_at: "2026-09-27T09:00:00Z" }],
    },
    NOW,
  );
  assert.equal(total, 2);
  assert.deepEqual(kinds, { quote: 1, claim: 0, renewal: 0, application: 1 });
  assert.equal(items[1].kind, "application", "both urgent: newest first");
  assert.equal(items[0].key, "quote:q1");
  assert.equal(inProgressSeeAll({ quote: 0, claim: 0, renewal: 0, application: 1 }), "/proposals");
  assert.equal(inProgressSeeAll({ quote: 1, claim: 0, renewal: 0, application: 1 }), "/quotes");
  // Without applications the kinds keep their previous shape.
  assert.deepEqual(inProgressItems({}, NOW).kinds, { quote: 0, claim: 0, renewal: 0 });
});

test("one shared set of review components, built on the profile summary cards", () => {
  assert.ok(existsSync(new URL("../src/components/review/ReviewSummary.tsx", import.meta.url)));
  const review = read("src/components/review/ReviewSummary.tsx");
  for (const name of ["ReviewIntro", "ReviewSection", "ReviewRow", "ReviewRows", "SchemaReviewSection", "ReviewDocuments", "ReviewFooter"]) assert.match(review, new RegExp(`export function ${name}\\b`), name);
  assert.match(review, /from "@\/components\/forms\/SummaryCard"/, "reuses SummaryCard / SummaryField");
  assert.doesNotMatch(review, /forms\/SchemaForm/, "no require cycle with SchemaForm");
  assert.match(read("src/components/forms/SchemaSummary.tsx"), /export \{ SummaryCard, SummaryField, useMasterLabels \}/);
  assert.match(read("src/components/forms/SchemaForm.tsx"), /review\?: \{/);
});

test("every customer submit flow ends on a review", () => {
  const flows = [
    "app/quote/risk.tsx",
    "app/quote/questions.tsx",
    "app/claim/new/review.tsx",
    "app/claim/[id]/information.tsx",
    "app/claim/[id]/evidence.tsx",
    "app/claim/[id]/appeal.tsx",
    "app/claim/emergency.tsx",
    "app/services/new.tsx",
    "app/payments/[id]/refund.tsx",
    "app/support/new.tsx",
    "app/onboarding/kyc.tsx",
    "app/proposals/[id]/information.tsx",
    "app/checkout.tsx",
    "src/components/policies/BeneficiariesSection.tsx",
  ];
  for (const f of flows) assert.match(read(f), /@\/components\/review\/ReviewSummary/, f);
  assert.match(read("app/claim/new/incident.tsx"), /review=\{\{/);
  assert.match(read("app/account/beneficiaries.tsx"), /review=\{\{/);
  // Renewal pays with the shared consent rows (no local duplicate).
  assert.doesNotMatch(read("app/policy/[id]/renewal-review.tsx"), /function Consent\(/);
});

test("save screens check the details before saving (shared review components only)", () => {
  const screens = {
    "app/claim/[id]/incident.tsx": ["ReviewIntro", "ReviewSection", "ReviewRow", "ReviewFooter"],
    "app/assets/new.tsx": ["ReviewIntro", "ReviewSection", "ReviewRows", "ReviewFooter", "useReviewCopy"],
    "app/delivery/[id]/address.tsx": ["ReviewIntro", "ReviewSection", "ReviewRows", "ReviewFooter"],
    "app/account/profile.tsx": ["ReviewIntro", "ReviewSection", "ReviewRows"],
    "app/assets/[id]/scan.tsx": ["ReviewIntro", "ReviewSection", "ReviewRows", "ReviewDocuments", "ReviewFooter"],
    "app/claim/[id]/parties.tsx": ["ReviewSection", "ReviewRows"],
    "app/claim/[id]/inspection.tsx": ["ReviewSection", "ReviewRows"],
  };
  for (const [file, parts] of Object.entries(screens)) {
    const src = read(file);
    assert.match(src, /from "@\/components\/review\/ReviewSummary"/, file);
    for (const p of parts) assert.match(src, new RegExp(`<${p}\\b|\\b${p}\\(`), `${file} uses ${p}`);
    assert.match(src, /const \[reviewing, setReviewing\] = useState\(false\)/, `${file} has a review step`);
    assert.match(src, /setReviewing\(false\)/, `${file}: Edit returns to the form`);
    assert.doesNotMatch(src, /function Review[A-Z]\w*\(/, `${file}: no local review component`);
  }
  // Vehicle facts read as names/labels through formSummary, not raw codes.
  assert.match(read("app/assets/new.tsx"), /reviewRows\(/);
  // Personal information: the server-form section passes review through, and the e-mail change keeps its step-up.
  const profile = read("app/account/profile.tsx");
  assert.match(profile, /<EditableSchemaSection[\s\S]*review=\{\{/);
  assert.match(profile, /withStepUp\(accountProfileStepUpPurpose/);
  assert.match(read("src/components/forms/SchemaSummary.tsx"), /review=\{review\}/);
  assert.match(read("src/components/forms/SchemaForm.tsx"), /review\.continueLabel \?\? t\("reviewContinue"\)/);
});

test("review-before-save copy exists in English and French", () => {
  const en = read("src/i18n/en.ts");
  const fr = read("src/i18n/fr.ts");
  for (const k of ["reviewBeforeSave", "reviewSaveIntro", "incidentReviewLocation", "assetReviewType", "assetConfirmAdd", "scanReviewPhoto", "scanReviewFields", "partiesConfirmAdd", "inspReviewTitle", "inspReviewCurrent", "inspReviewRequested", "contactEmailStepUpNote"]) {
    assert.match(en, new RegExp(`\\n  ${k}: "`), `en ${k}`);
    assert.match(fr, new RegExp(`\\n  ${k}: "`), `fr ${k}`);
  }
});

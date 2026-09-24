import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import { POLICY_HEADER_TERM_CODES, policyHeaderLabels } from "../src/lib/regulatoryTerms.ts";

const root = fileURLToPath(new URL("..", import.meta.url));
const read = (p) => readFileSync(join(root, p), "utf8");

const terms = [
  { code: "POLICY", category: "CONTRACT", label: "Police d'assurance" },
  { code: "POLICYHOLDER", category: "PARTY", label: "Souscripteur" },
  { code: "INSURED", category: "PARTY", label: "Assuré" },
  { code: "PREMIUM", category: "PREMIUM", label: "Prime" },
];

test("French policy header labels come from the CIMA terminology service", () => {
  assert.deepEqual(policyHeaderLabels("fr", terms), {
    policy: "Police d'assurance",
    policyholder: "Souscripteur",
    insured: "Assuré",
    totalPremium: "Prime totale",
  });
  assert.deepEqual([...POLICY_HEADER_TERM_CODES], ["POLICY", "POLICYHOLDER", "INSURED", "PREMIUM"]);
});

test("labels fall back safely offline and keep English wording in English", () => {
  assert.equal(policyHeaderLabels("fr", null).policy, "Police d'assurance");
  assert.equal(policyHeaderLabels("fr", [{ code: "POLICY", category: "CONTRACT", label: null }]).policy, "Police d'assurance");
  assert.equal(policyHeaderLabels("en", terms).totalPremium, "Total premium");
});

test("the regulatory API uses the public terminology endpoint and the policy detail never shows CIMA branch codes", () => {
  const api = read("src/api/regulatory.ts");
  assert.match(api, /\/public\/regulatory\/terms\?/);
  assert.match(api, /anonymous: true/);
  const view = read("src/components/policies/PolicyDetailView.tsx");
  assert.match(view, /policyHeaderLabels\(f\.language, terms\)/);
  assert.doesNotMatch(view, /CIMA_\d|Regulatory branch|branch_code/);
});

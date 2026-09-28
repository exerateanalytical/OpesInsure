import test from "node:test";
import assert from "node:assert/strict";
import { profileCompletion } from "../src/lib/profileCompletion.ts";

const NOW = Date.parse("2026-09-28T10:00:00Z");
const user = { phone_e164: "+237670000000", phone_verified_at: "2026-09-01", email: null };
const profile = { date_of_birth: "1990-01-01", address_line1: "Rue 1", city: "Douala" };
const req = (satisfied) => [{ requirement_code: "NATIONAL_ID", mandatory: true, satisfied }];
const approved = { identifiers: [{}], submission: { status: "APPROVED", expires_at: "2027-09-01", requirements: req(true), documents: [{}] } };

const state = (c, key) => c.steps.find((s) => s.key === key)?.state;

test("unknown while any source is missing: never claims a percentage", () => {
  assert.equal(profileCompletion({ user, profile, kyc: null }), null);
  assert.equal(profileCompletion({ user, profile: null, kyc: approved }), null);
  assert.equal(profileCompletion({ user: null, profile, kyc: approved }), null);
});

test("complete only with personal details, verified contacts and an approved verification", () => {
  const c = profileCompletion({ user, profile, kyc: approved }, NOW);
  assert.equal(c.complete, true);
  assert.equal(c.percent, 100);
  assert.deepEqual(c.steps.map((s) => s.key), ["personal", "phone", "identifier", "documents", "verified"]);
});

test("an in-review submission is not complete; verification shows as waiting", () => {
  const c = profileCompletion({ user, profile, kyc: { identifiers: [{}], submission: { status: "SUBMITTED", requirements: req(true), documents: [{}] } } }, NOW);
  assert.equal(c.complete, false);
  assert.equal(state(c, "documents"), "done");
  assert.equal(state(c, "verified"), "waiting");
});

test("an expired or rejected verification is not complete and documents must be redone", () => {
  for (const status of ["REJECTED", "EXPIRED"]) {
    const c = profileCompletion({ user, profile, kyc: { identifiers: [{}], submission: { status, requirements: req(true), documents: [{}] } } }, NOW);
    assert.equal(c.complete, false, status);
    assert.equal(state(c, "documents"), "todo", status);
    assert.equal(state(c, "verified"), "todo", status);
  }
  const lapsed = profileCompletion({ user, profile, kyc: { ...approved, submission: { ...approved.submission, expires_at: "2026-09-01" } } }, NOW);
  assert.equal(lapsed.complete, false);
});

test("missing mandatory documents or personal details keep it incomplete", () => {
  const draft = profileCompletion({ user, profile, kyc: { identifiers: [{}], submission: { status: "DRAFT", requirements: req(false), documents: [{}] } } }, NOW);
  assert.equal(state(draft, "documents"), "todo");
  const noCity = profileCompletion({ user, profile: { ...profile, city: " " }, kyc: approved }, NOW);
  assert.equal(noCity.complete, false);
  assert.equal(state(noCity, "personal"), "todo");
});

test("more information requested sends documents back to todo", () => {
  const c = profileCompletion({ user, profile, kyc: { identifiers: [{}], submission: { status: "MORE_INFO_REQUIRED", requirements: req(true), documents: [{}] } } }, NOW);
  assert.equal(state(c, "documents"), "todo");
  assert.equal(c.complete, false);
});

test("an email on the account must be verified; no email is not required", () => {
  const unverified = profileCompletion({ user: { ...user, email: "a@b.cm", email_verified_at: null }, profile, kyc: approved }, NOW);
  assert.equal(state(unverified, "email"), "todo");
  assert.equal(unverified.complete, false);
  const verified = profileCompletion({ user: { ...user, email: "a@b.cm", email_verified_at: "2026-09-02" }, profile, kyc: approved }, NOW);
  assert.equal(verified.complete, true);
});

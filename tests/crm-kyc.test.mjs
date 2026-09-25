import test from "node:test";
import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import { canSubmitKyc, daysUntil, KYC_DOCUMENT_PURPOSES, kycPhase, suggestedPurposes } from "../src/lib/kyc.ts";
import {
  assetTypesForLine,
  beneficiaryPayload,
  duplicateAssetId,
  groupSearch,
  isOpenLead,
  leadMoveError,
  leadMoves,
  searchHitRoute,
  validateBeneficiaries,
} from "../src/lib/crm.ts";
import { API_ERROR_COPY, isKycReviewInProgress } from "../src/lib/apiErrors.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const NOW = Date.parse("2026-09-25T10:00:00Z");

test("KYC phases cover every case-engine status", () => {
  const cases = {
    NOT_STARTED: ["start", true],
    DRAFT: ["draft", true],
    SUBMITTED: ["in_review", false],
    REVIEWING: ["in_review", false],
    MORE_INFO_REQUIRED: ["more_info", true],
    PENDING_APPROVAL: ["pending_approval", false],
    APPROVED: ["approved", false],
    REJECTED: ["rejected", true],
    EXPIRED: ["expired", true],
  };
  for (const [status, [phase, editable]] of Object.entries(cases)) {
    const r = kycPhase(status, null, NOW);
    assert.equal(r.phase, phase, status);
    assert.equal(r.editable, editable, status);
  }
  assert.equal(kycPhase(null).phase, "start");
  // APPROVED past expires_at is treated as expired (renew).
  assert.deepEqual(kycPhase("APPROVED", "2026-09-01T00:00:00Z", NOW), { phase: "expired", editable: true, tone: "danger" });
  assert.equal(kycPhase("APPROVED", "2027-09-01T00:00:00Z", NOW).phase, "approved");
});

test("KYC requirements drive suggested purposes and the submit gate", () => {
  assert.deepEqual([...KYC_DOCUMENT_PURPOSES], ["ID_FRONT", "ID_BACK", "PASSPORT", "PROOF_OF_ADDRESS", "RCCM", "NIU"]);
  assert.deepEqual(suggestedPurposes(["NATIONAL_ID", "PROOF_OF_ADDRESS", "UNKNOWN"]), ["ID_FRONT", "ID_BACK", "PROOF_OF_ADDRESS"]);
  assert.deepEqual(suggestedPurposes(["BUSINESS_REGISTRATION", "TAX_ID_CERTIFICATE"]), ["RCCM", "NIU"]);
  const missing = [{ requirement_code: "NATIONAL_ID", mandatory: true, satisfied: false }];
  const optional = [{ requirement_code: "PROOF_OF_ADDRESS", mandatory: false, satisfied: false }];
  assert.equal(canSubmitKyc(true, 1, missing), false);
  assert.equal(canSubmitKyc(true, 1, optional), true);
  assert.equal(canSubmitKyc(true, 0, []), false);
  assert.equal(canSubmitKyc(false, 3, []), false);
  assert.equal(daysUntil("2026-10-05T10:00:00Z", NOW), 10);
  assert.equal(daysUntil(null, NOW), null);
});

test("409 KYC_REVIEW_IN_PROGRESS has EN/FR copy and triggers a reload", () => {
  assert.equal(API_ERROR_COPY.KYC_REVIEW_IN_PROGRESS, "errKycReviewInProgress");
  assert.ok(isKycReviewInProgress("kyc_review_in_progress"));
  const kyc = read("app/onboarding/kyc.tsx");
  assert.match(kyc, /isKycReviewInProgress\(code\)\) void q\.reload\(\)/);
  for (const k of ["kyc_level", "missing_requirements", "expires_at", "kycResubmit"]) assert.ok(kyc.includes(k), k);
});

test("lead pipeline only offers the server's next_statuses; LOST needs a reason", () => {
  assert.deepEqual(leadMoves("QUALIFIED", ["QUOTE", "NEGOTIATION", "LOST"]), ["QUOTE", "NEGOTIATION", "LOST"]);
  assert.deepEqual(leadMoves("NEW", ["CONTACTED", "CONVERTED"]), ["CONTACTED"]);
  assert.deepEqual(leadMoves("CONVERTED", ["NEW"]), []);
  assert.deepEqual(leadMoves("NEW", undefined), []);
  assert.equal(leadMoveError("LOST", "  "), "reason");
  assert.equal(leadMoveError("LOST", "Too expensive"), null);
  assert.equal(leadMoveError("QUOTE", ""), null);
  assert.ok(isOpenLead("NEGOTIATION"));
  assert.ok(!isOpenLead("LOST"));
});

test("beneficiary shares mirror the server rules (primary 100 %)", () => {
  const ok = [
    { designation: "PRIMARY", full_name: "Awa", allocation_pct: "60" },
    { designation: "PRIMARY", full_name: "Paul", allocation_pct: "40" },
  ];
  assert.deepEqual(validateBeneficiaries(ok), []);
  assert.deepEqual(validateBeneficiaries([{ designation: "PRIMARY", full_name: "A", allocation_pct: "90" }]), [{ code: "primaryTotal", total: 90 }]);
  assert.deepEqual(validateBeneficiaries([{ designation: "CONTINGENT", full_name: "A", allocation_pct: "100" }]).map((i) => i.code), ["none"]);
  const withContingent = [...ok, { designation: "CONTINGENT", full_name: "C", allocation_pct: "50" }];
  assert.deepEqual(validateBeneficiaries(withContingent), [{ code: "contingentTotal", total: 50 }]);
  assert.deepEqual(validateBeneficiaries([{ designation: "PRIMARY", full_name: "", allocation_pct: "0" }]).map((i) => i.code), ["share", "name", "primaryTotal"]);
  // Decimal comma and thirds: 33,33 + 33,33 + 33,34 = 100.
  const thirds = ["33,33", "33,33", "33,34"].map((p, i) => ({ designation: "PRIMARY", full_name: `P${i}`, allocation_pct: p }));
  assert.deepEqual(validateBeneficiaries(thirds), []);
  assert.equal(beneficiaryPayload(thirds)[2].allocation_pct, 33.34);
  assert.equal(beneficiaryPayload(ok)[0].revocable, true);
});

test("search results group by type and deep-link per portal", () => {
  const groups = groupSearch({
    query: "LT",
    results: [
      { type: "vehicles", id: "v1", title: "LT 123", subtitle: null, status: "ACTIVE", score: 0.5 },
      { type: "policies", id: "p1", title: "POL-1", subtitle: null, status: "ACTIVE", score: 0.2 },
      { type: "policies", id: "p2", title: "POL-2", subtitle: null, status: "ACTIVE", score: 0.9 },
    ],
  });
  assert.deepEqual(groups.map((g) => g.type), ["policies", "vehicles"]);
  assert.deepEqual(groups[0].hits.map((h) => h.id), ["p2", "p1"]);
  assert.deepEqual(groupSearch({ query: "x", results: { claims: [{ id: "c1", title: "C", subtitle: null, status: null, score: 1 }] } })[0].type, "claims");
  assert.deepEqual(groupSearch(null), []);
  assert.equal(searchHitRoute({ type: "policies", id: "p1" }, "customer"), "/policy/p1");
  assert.equal(searchHitRoute({ type: "claims", id: "c1" }, "carrier"), "/carrier/claims/c1");
  assert.equal(searchHitRoute({ type: "customers", id: "tc1", party_id: "pa1" }, "broker"), "/broker/clients/tc1?partyId=pa1");
  assert.equal(searchHitRoute({ type: "vehicles", id: "a1" }, "customer"), "/assets/a1");
  assert.equal(searchHitRoute({ type: "policies", id: "p1" }, "agent"), null);
  const api = read("src/api/crm.ts");
  assert.match(api, /types\[\]/);
  assert.match(read("app/_layout.tsx"), /name="search"/);
});

test("insured-object types come from /risk-asset-types; duplicate vehicle offers the existing asset", () => {
  const types = [
    { code: "VEHICLE", category: "VEHICLE", label: "Vehicle", line_code: "MOTOR" },
    { code: "CARGO", category: "CARGO", label: "Cargo", line_code: "CARGO" },
  ];
  assert.ok(assetTypesForLine(types, "CARGO").includes("CARGO"));
  assert.ok(assetTypesForLine([], "MOTOR").includes("VEHICLE"));
  const id = "3f2b8a4c-1d2e-4f5a-9b6c-7d8e9f0a1b2c";
  assert.equal(duplicateAssetId({ status: 409, message: `Ce véhicule (vin) est déjà assuré sous l’objet ${id}.` }), id);
  assert.equal(duplicateAssetId({ status: 409, message: "x", fields: { registration_number: [`as asset ${id}`] } }), id);
  assert.equal(duplicateAssetId({ status: 422, message: id }), null);
  assert.equal(duplicateAssetId({ status: 409, message: "no id" }), null);
  const screen = read("app/assets/new.tsx");
  assert.match(screen, /RiskAssetTypesApi\.list/);
  assert.match(screen, /assetOpenExisting/);
  assert.match(read("app/quote/risk.tsx"), /assetTypesForLine/);
});

test("Batch 4 screens are wired to their endpoints", () => {
  const api = read("src/api/crm.ts");
  for (const path of ["/mobile/partner/agent/leads/${leadId}/activities", "/crm/leads", "/assign", "/beneficiaries/history", "/risk-asset-types", "/overview"])
    assert.ok(api.includes(path), path);
  assert.match(read("app/agent/leads/[id].tsx"), /LeadActivityApi\.agentList/);
  assert.match(read("app/agent/leads/[id].tsx"), /next_statuses/);
  assert.match(read("app/broker/leads/[id].tsx"), /LeadDirectoryApi\.assign/);
  assert.match(read("src/components/policies/PolicyDetailView.tsx"), /<BeneficiariesSection/);
  for (const f of ["app/agent/clients/[id].tsx", "app/broker/clients/[id].tsx"]) assert.match(read(f), /<Customer360Panel/);
  for (const r of ["broker/leads/index", "broker/leads/[id]"]) assert.ok(read("app/_layout.tsx").includes(`name="${r}"`), r);
  assert.ok(existsSync(new URL("../app/search.tsx", import.meta.url)));
});

test("Batch 4 keys exist in EN and FR", () => {
  const en = read("src/i18n/en.ts");
  const fr = read("src/i18n/fr.ts");
  const keys = [
    "errKycReviewInProgress",
    ...["REVIEWING", "MORE_INFO_REQUIRED", "PENDING_APPROVAL", "EXPIRED"].map((s) => `kycStatus_${s}`),
    ...["start", "draft", "more_info", "in_review", "pending_approval", "approved", "rejected", "expired"].map((p) => `kycPhase_${p}`),
    ...KYC_DOCUMENT_PURPOSES.map((p) => `kycPurpose_${p}`),
    ...["NEW", "CONTACTED", "QUALIFIED", "QUOTE", "NEGOTIATION", "CONVERTED", "LOST"].map((s) => `leadStatus_${s}`),
    ...["none", "primaryTotal", "contingentTotal", "share", "name"].map((c) => `benErr_${c}`),
    ...["customers", "policies", "claims", "quotes", "documents", "vehicles", "risk_assets"].map((c) => `searchType_${c}`),
    "assetDuplicateTitle",
    "c360Title",
  ];
  for (const k of keys) {
    assert.match(en, new RegExp(`^  ${k}:`, "m"), `en ${k}`);
    assert.match(fr, new RegExp(`^  ${k}:`, "m"), `fr ${k}`);
  }
});

test("Batch 4 stays JS-only (OTA-safe)", () => {
  const pkg = JSON.parse(read("package.json"));
  assert.equal(pkg.version, "1.3.0");
  assert.equal(JSON.parse(read("app.json")).expo.version, "1.3.0");
});

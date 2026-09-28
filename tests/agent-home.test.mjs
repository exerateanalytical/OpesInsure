import test from "node:test";
import assert from "node:assert/strict";
import {
  claimsNeedingInfo,
  kycActions,
  metricCount,
  needsAttention,
  pipelineCounts,
  quoteFollowUps,
  recentActivity,
  returnedApplications,
} from "../src/lib/agentHome.ts";

const NOW = Date.parse("2026-09-28T10:00:00Z");

test("quote follow-ups: priced and unexpired only", () => {
  const quotes = [
    { id: "1", customer_name: "A", status: "SENT", expires_at: "2026-10-05" },
    { id: "2", customer_name: "B", status: "VIEWED", expires_at: "2026-09-01" },
    { id: "3", customer_name: "C", status: "ACCEPTED" },
    { id: "4", customer_name: "D", status: "calculated", expires_at: null },
  ];
  assert.equal(quoteFollowUps(quotes, NOW), 2);
  assert.equal(quoteFollowUps(null, NOW), 0);
});

test("returned applications and claims needing info", () => {
  const props = ["INFORMATION_REQUIRED", "COUNTEROFFERED", "SUBMITTED", "ISSUED"].map((status, i) => ({ id: `${i}`, proposal_number: `P${i}`, customer_name: "X", status }));
  assert.equal(returnedApplications(props), 2);
  const claims = ["EVIDENCE_PENDING", "SUBMITTED", "PAID"].map((status, i) => ({ id: `${i}`, claim_number: `C${i}`, customer_name: "X", status }));
  assert.equal(claimsNeedingInfo(claims), 1);
});

test("KYC actions: missing items, inactive record, mandate lapsing", () => {
  assert.equal(kycActions(null, NOW), 0);
  assert.equal(kycActions({ status: "ACTIVE", compliance_items: [{ status: "VERIFIED" }] }, NOW), 0);
  assert.equal(kycActions({ status: "ACTIVE", compliance_items: [{ status: "MISSING" }, { status: "SUBMITTED" }] }, NOW), 1);
  assert.equal(kycActions({ status: "DRAFT", compliance_items: [] }, NOW), 1);
  assert.equal(kycActions({ status: "ACTIVE", compliance_items: [], mandate_expires_at: "2026-10-10" }, NOW), 1);
  assert.equal(kycActions({ status: "ACTIVE", compliance_items: [], mandate_expires_at: "2027-06-01" }, NOW), 0);
});

test("metric count parses numeric values", () => {
  const m = [{ label: "Renewals due", value: "3" }, { label: "Clients", value: "1 204" }];
  assert.equal(metricCount(m, "Renewals due"), 3);
  assert.equal(metricCount(m, "Clients"), 1204);
  assert.equal(metricCount(m, "Missing"), 0);
});

test("needs attention drops zeros and keeps priority order", () => {
  const out = needsAttention({ renewals: 2, kyc: 1, quotes: 0, returned: 3, claims: 0 });
  assert.deepEqual(out.map((x) => x.key), ["kyc", "returned", "renewals"]);
});

test("pipeline counts", () => {
  const c = pipelineCounts(
    {
      leads: [{ id: "1", full_name: "L", status: "NEW" }, { id: "2", full_name: "M", status: "LOST" }],
      quotes: [{ id: "q", customer_name: "A", status: "SENT" }],
      proposals: [{ id: "p", proposal_number: "P", customer_name: "A", status: "UNDER_REVIEW" }, { id: "r", proposal_number: "R", customer_name: "A", status: "MORE_INFORMATION" }],
    },
    NOW,
  );
  assert.deepEqual(c, { leads: 1, quotes: 1, applications: 1, returned: 1 });
});

test("recent activity: newest first, capped, skips undated, routes proposals", () => {
  const out = recentActivity(
    {
      leads: [{ id: "l", full_name: "Lead", status: "NEW", updated_at: "2026-09-20T00:00:00Z" }],
      quotes: [{ id: "q", customer_name: "Q", status: "SENT", created_at: "2026-09-27T00:00:00Z" }, { id: "q2", customer_name: "Q2", status: "SENT", created_at: null }],
      proposals: [{ id: "p", proposal_number: "P1", customer_name: "P", status: "ISSUED", policy_id: "pol", decided_at: "2026-09-26T00:00:00Z" }],
      claims: [{ id: "c", claim_number: "C1", customer_name: "C", status: "SUBMITTED", submitted_at: "2026-09-25T00:00:00Z" }],
    },
    3,
  );
  assert.deepEqual(out.map((a) => a.id), ["quote:q", "proposal:p", "claim:c"]);
  assert.equal(out[1].href, "/agent/policies/pol");
});

import test from "node:test";
import assert from "node:assert/strict";
import {
  homeLayout,
  homePolicies,
  inProgressItems,
  inProgressSeeAll,
  isOpenQuote,
  IN_PROGRESS_LIMIT,
} from "../src/lib/homeFeed.ts";

const NOW = new Date("2026-09-29T09:00:00Z");
const inDays = (d) => new Date(NOW.getTime() + d * 86_400_000).toISOString();
const daysAgo = (d) => new Date(NOW.getTime() - d * 86_400_000).toISOString();

test("open quotes: resumable or an open legacy status", () => {
  assert.equal(isOpenQuote({ id: "1", status: "OFFERED" }), true);
  assert.equal(isOpenQuote({ id: "2", status: "pending_rating" }), true);
  assert.equal(isOpenQuote({ id: "3", status: "ACCEPTED" }), false);
  assert.equal(isOpenQuote({ id: "4", status: "EXPIRED", can_resume: true }), true);
});

test("home policies: ACTIVE and EXPIRING only, at most two", () => {
  const out = homePolicies([
    { id: "a", status: "ACTIVE" },
    { id: "b", status: "CANCELLED" },
    { id: "c", status: "expiring" },
    { id: "d", status: "ACTIVE" },
  ]);
  assert.deepEqual(out.map((p) => p.id), ["a", "c"]);
});

test("in progress merges quotes, active claims and renewals due; drops the rest", () => {
  const { items, total, kinds } = inProgressItems(
    {
      quotes: [
        { id: "q1", status: "DRAFT", created_at: daysAgo(2) },
        { id: "q2", status: "ACCEPTED", created_at: daysAgo(1) },
      ],
      claims: [
        { id: "c1", status: "SUBMITTED", created_at: daysAgo(1) },
        { id: "c2", status: "PAID", created_at: daysAgo(1) },
      ],
      policies: [
        { id: "p1", status: "ACTIVE", coverage_ends_at: inDays(21) },
        { id: "p2", status: "ACTIVE", coverage_ends_at: inDays(200) },
      ],
    },
    NOW,
  );
  assert.equal(total, 3);
  assert.deepEqual(kinds, { quote: 1, claim: 1, renewal: 1 });
  // Renewal counts as "now", then newest first.
  assert.deepEqual(items.map((i) => i.key), ["renewal:p1", "claim:c1", "quote:q1"]);
  assert.equal(items[0].days, 21);
});

test("urgent items lead: close renewals, evidence requests, offers ready", () => {
  const { items } = inProgressItems(
    {
      quotes: [
        { id: "new", status: "DRAFT", created_at: daysAgo(1) },
        { id: "offers", status: "OFFERED", created_at: daysAgo(9), offer_count: 3 },
      ],
      claims: [{ id: "ev", status: "EVIDENCE_PENDING", created_at: daysAgo(20) }],
      policies: [
        { id: "late", status: "ACTIVE", coverage_ends_at: inDays(25) },
        { id: "soon", status: "ACTIVE", coverage_ends_at: inDays(3) },
        { id: "sooner", status: "ACTIVE", coverage_ends_at: inDays(1) },
      ],
    },
    NOW,
  );
  assert.deepEqual(items.map((i) => i.key), [
    "renewal:sooner",
    "renewal:soon",
    "quote:offers",
    "claim:ev",
    "renewal:late",
  ]);
});

test("in progress is capped at five rows but counts everything", () => {
  const quotes = Array.from({ length: 8 }, (_, i) => ({ id: `q${i}`, status: "DRAFT", created_at: daysAgo(i) }));
  const { items, total, kinds } = inProgressItems({ quotes }, NOW);
  assert.equal(IN_PROGRESS_LIMIT, 5);
  assert.equal(items.length, 5);
  assert.equal(total, 8);
  assert.equal(kinds.quote, 8);
  assert.deepEqual(items.map((i) => i.quote.id), ["q0", "q1", "q2", "q3", "q4"]);
});

test("see all: Policies tab for renewals, else Claims tab, else /quotes", () => {
  assert.equal(inProgressSeeAll({ quote: 2, claim: 1, renewal: 1 }), "/(customer)/(tabs)/policies");
  assert.equal(inProgressSeeAll({ quote: 2, claim: 1, renewal: 0 }), "/(customer)/(tabs)/claims");
  assert.equal(inProgressSeeAll({ quote: 2, claim: 0, renewal: 0 }), "/quotes");
});

const base = { policiesReady: true, quotesReady: true, claimsReady: true, policiesError: false, activityError: false, policyCount: 0, inProgressCount: 0 };

test("layout: skeletons until the first load finishes", () => {
  assert.deepEqual(homeLayout({ ...base, policiesReady: false, quotesReady: false }), { firstQuote: false, policies: "skeleton", inProgress: "skeleton" });
  assert.deepEqual(homeLayout({ ...base, claimsReady: false, policyCount: 1 }), { firstQuote: false, policies: "list", inProgress: "skeleton" });
});

test("layout: a brand-new customer gets one first-quote card", () => {
  assert.deepEqual(homeLayout(base), { firstQuote: true, policies: "hidden", inProgress: "hidden" });
});

test("layout: lists when there is content, hidden empty sections otherwise", () => {
  assert.deepEqual(homeLayout({ ...base, policyCount: 2, inProgressCount: 3 }), { firstQuote: false, policies: "list", inProgress: "list" });
  assert.deepEqual(homeLayout({ ...base, policyCount: 1 }), { firstQuote: false, policies: "list", inProgress: "hidden" });
  assert.deepEqual(homeLayout({ ...base, inProgressCount: 1 }), { firstQuote: false, policies: "hidden", inProgress: "list" });
});

test("layout: one retry row per section on failure, never the first-quote card", () => {
  assert.deepEqual(homeLayout({ ...base, policiesError: true }), { firstQuote: false, policies: "error", inProgress: "error" });
  assert.deepEqual(homeLayout({ ...base, activityError: true, policyCount: 1 }), { firstQuote: false, policies: "list", inProgress: "error" });
  assert.deepEqual(homeLayout({ ...base, activityError: true, inProgressCount: 2 }), { firstQuote: false, policies: "hidden", inProgress: "list" });
});

// Phase-1 fix S (2026-09-30): scoped staff workspace, claims module, cursor-paged staff lists and per-id details.
import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { cursorPager, nextCursorOf, withCursor } from "../src/lib/cursorPages.ts";
import { adjusterEventPath, adjusterEventReady, canDo, decisionReady, workspaceModuleRoute, WORKSPACE_CLAIM_STATUSES } from "../src/lib/workspaceClaims.ts";
import { roleToPortal, workspacePortal } from "../src/lib/portalRouting.ts";

const root = new URL("../", import.meta.url);
const read = (path) => readFileSync(new URL(path, root), "utf8");

test("withCursor appends cursor and limit to any path", () => {
  assert.equal(withCursor("/mobile/carrier/claims", null), "/mobile/carrier/claims");
  assert.equal(withCursor("/mobile/carrier/claims", null, 50), "/mobile/carrier/claims?limit=50");
  assert.equal(withCursor("/mobile/workspace/claims?status=SUBMITTED", "b246MA", 25), "/mobile/workspace/claims?status=SUBMITTED&cursor=b246MA&limit=25");
});

test("nextCursorOf reads meta.next_cursor and the module data.next_cursor", () => {
  assert.equal(nextCursorOf({ data: [], meta: { next_cursor: "abc" } }), "abc");
  assert.equal(nextCursorOf({ data: { rows: [], next_cursor: "xyz" } }), "xyz");
  assert.equal(nextCursorOf({ data: [], meta: { next_cursor: null } }), null);
  assert.equal(nextCursorOf(null), null);
});

test("cursorPager walks pages with the cursor each page returned and restarts on page 1", async () => {
  const seen = [];
  const pages = { first: { items: [{ id: "a" }, { id: "b" }], next: "c2" }, c2: { items: [{ id: "c" }], next: null } };
  const pager = cursorPager(async (cursor) => {
    seen.push(cursor);
    return pages[cursor ?? "first"];
  });
  const p1 = await pager(1);
  assert.deepEqual(p1.items.map((x) => x.id), ["a", "b"]);
  assert.equal(p1.info.hasMore, true);
  const p2 = await pager(2);
  assert.deepEqual(p2.items.map((x) => x.id), ["c"]);
  assert.equal(p2.info.hasMore, false);
  const p3 = await pager(3); // never reached: empty last page, no request
  assert.deepEqual(p3.items, []);
  await pager(1);
  assert.deepEqual(seen, [null, "c2", null]);
});

test("the claims module opens the dedicated claims screen; other modules keep the table", () => {
  assert.deepEqual(workspaceModuleRoute("claims", "claims"), { pathname: "/workspace/[role]/claims", params: { role: "claims" } });
  assert.deepEqual(workspaceModuleRoute("operations", "policies"), { pathname: "/workspace/[role]/module/[module]", params: { role: "operations", module: "policies" } });
  assert.match(read("app/workspace/[role].tsx"), /workspaceModuleRoute\(String\(role\),module\.key\)/);
});

test("claim actions come from the server and adjuster moves map to the adjuster endpoints", () => {
  assert.equal(canDo(["transition"], "transition"), true);
  assert.equal(canDo(["transition"], "propose_decision"), false);
  assert.equal(canDo(undefined, "assign_to_me"), false);
  assert.equal(adjusterEventPath("a1", "schedule_inspection"), "/claims/adjuster/assignments/a1/inspection");
  assert.equal(adjusterEventPath("a1", "submit_report"), "/claims/adjuster/assignments/a1/report");
  assert.equal(adjusterEventPath("a1", "accept_report"), null); // insurer-side move, never offered to the adjuster
  assert.equal(adjusterEventReady("decline", { text: "no" }), false);
  assert.equal(adjusterEventReady("decline", { text: "Conflict of interest" }), true);
  assert.equal(adjusterEventReady("submit_report", { text: "short", amountMinor: 100 }), false);
  assert.equal(adjusterEventReady("submit_report", { text: "Front bumper and headlamp replaced, labour 4h.", amountMinor: 250000 }), true);
  assert.equal(adjusterEventReady("schedule_inspection", { when: "2001-01-01T10:00" }), false);
  assert.equal(decisionReady("DECLINE", "NOT_COVERED", "Loss outside the insured perils.", null), true);
  assert.equal(decisionReady("APPROVE", "COVERED", "Loss inside the insured perils.", 0), false);
  assert.ok(WORKSPACE_CLAIM_STATUSES.includes("CARRIER_REVIEW"));
});

test("the five limited roles keep their workspace portal (the limited card is only a fallback)", () => {
  for (const role of ["CUSTOMER_SERVICE", "REINSURANCE_OFFICER", "BRANCH_MANAGER"]) assert.equal(roleToPortal(role), "operations", role);
  assert.equal(roleToPortal("CASHIER"), "finance");
  assert.equal(roleToPortal("ADJUSTER"), "claims");
  assert.equal(workspacePortal({ role_code: "CUSTOMER_SERVICE", permissions: ["workspace.read"] }), "operations");
  const catalogue = read("../app/Application/Identity/RoleCatalogue.php");
  for (const constant of ["CUSTOMER_SERVICE_PERMISSIONS", "REINSURANCE_OFFICER_PERMISSIONS", "BRANCH_MANAGER_PERMISSIONS", "CASHIER_PERMISSIONS", "ADJUSTER_PERMISSIONS"]) {
    const line = catalogue.split("\n").find((l) => l.includes(`public const ${constant} = [`));
    assert.ok(line && line.includes("'workspace.read'"), constant);
  }
  assert.match(read("app/workspace/[role].tsx"), /!canRead\?<Card>/);
});

test("staff detail screens load their record by id, not from the first page of the list", () => {
  for (const file of [
    "app/carrier/policies/[id].tsx",
    "app/carrier/partners/[id].tsx",
    "app/carrier/products/[id].tsx",
    "app/carrier/payments/[id].tsx",
    "app/broker/production/[id].tsx",
    "app/broker/receivables/[id].tsx",
    "app/broker/compliance/[id].tsx",
  ]) {
    const src = read(file);
    assert.doesNotMatch(src, /useListRecord\(/, file);
    assert.match(src, /useRecord\(/, file);
  }
  assert.doesNotMatch(read("app/carrier/proposals/[id].tsx"), /proposals\.find\(/);
  for (const file of ["app/carrier/policies.tsx", "app/carrier/claims.tsx", "app/carrier/partners.tsx", "app/carrier/proposals.tsx", "app/carrier/issuance.tsx", "app/carrier/referrals/index.tsx"]) {
    const src = read(file);
    assert.match(src, /useCursorList\(/, file);
    assert.match(src, /<LoadMore \{\.\.\.q\.more\} \/>/, file);
  }
  const api = read("src/api/workspace.ts");
  for (const path of ["/mobile/partner/carrier/policies/", "/mobile/partner/carrier/products/", "/mobile/partner/carrier/partners/", "/mobile/partner/carrier/payments/", "/mobile/partner/carrier/proposals/", "/mobile/broker/production/", "/mobile/broker/receivables/", "/mobile/broker/compliance/"]) {
    assert.ok(api.includes(path), path);
  }
});

test("the workspace module table and the claims screens load more on scroll and have no raw English", () => {
  const moduleSrc = read("app/workspace/[role]/module/[module].tsx");
  assert.match(moduleSrc, /onEndReached=/);
  assert.match(moduleSrc, /workspaceModulePager/);
  assert.match(read("app/workspace/[role]/claims/index.tsx"), /onEndReached=/);
  for (const file of ["app/workspace/[role]/claims/index.tsx", "app/workspace/[role]/claims/[id].tsx"]) {
    const src = read(file);
    assert.doesNotMatch(src, /label="[A-Z][a-z]/, file);
    assert.doesNotMatch(src, />[A-Z][a-z]+( [a-z]+)*</, file);
  }
});

test("workspace claims copy has EN and FR keys", () => {
  const en = read("src/i18n/en.ts");
  const fr = read("src/i18n/fr.ts");
  const keys = [...en.matchAll(/\n  (wsc[A-Za-z_]+): "/g)].map((m) => m[1]);
  assert.ok(keys.length >= 50);
  for (const key of keys) assert.match(fr, new RegExp(`\\n  ${key}: "`), `fr ${key}`);
  for (const src of [read("app/workspace/[role]/claims/index.tsx"), read("app/workspace/[role]/claims/[id].tsx")]) {
    for (const [, key] of src.matchAll(/t\("(wsc[A-Za-z_]+)"/g)) assert.ok(keys.includes(key), key);
  }
});

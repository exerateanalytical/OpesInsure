// CAR-003..014 / ISS-001..003: carrier module visibility, specialist routing,
// shared detail layout and server-authoritative issuance.
import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, existsSync } from "node:fs";
import {
  allowedCarrierModules,
  canUseCarrierModule,
  carrierShellAllowed,
  specialistCarrierModules,
} from "../src/lib/carrierAccess.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("wildcard grants every carrier module; none without permissions", () => {
  assert.equal(allowedCarrierModules(["*"]).length, 11);
  assert.deepEqual(allowedCarrierModules([]), []);
  assert.deepEqual(allowedCarrierModules(null), []);
});

test("restricted staff only see modules their permissions cover (CAR-013)", () => {
  const finance = ["carrier.finance.read"];
  assert.deepEqual(allowedCarrierModules(finance).sort(), ["bordereaux", "payments", "settlements"]);
  assert.equal(canUseCarrierModule(finance, "claims"), false);
});

test("specialist roles enter the carrier shell only with carrier permissions (CAR-014)", () => {
  assert.equal(carrierShellAllowed("carrier", []), true);
  assert.equal(carrierShellAllowed("finance", []), false);
  assert.equal(carrierShellAllowed("finance", ["carrier.finance.read"]), true);
  assert.deepEqual(specialistCarrierModules("claims", ["carrier.claims.read", "carrier.dashboard.read"]), ["claims", "policies"]);
  assert.equal(carrierShellAllowed("customer", ["*"]), false);
  assert.equal(carrierShellAllowed("agent", ["*"]), false);
});

test("every carrier list row opens a detail route registered under the carrier guard", () => {
  const layout = read("app/_layout.tsx");
  for (const r of ["proposals", "products", "issuance", "policies", "payments", "partners", "bordereaux"]) {
    assert.ok(existsSync(new URL(`../app/carrier/${r}/[id].tsx`, import.meta.url)), r);
    assert.match(layout, new RegExp(`carrier/${r}/\\[id\\]`));
  }
  assert.match(layout, /guard=\{carrier\}/);
});

test("detail screens use the shared DetailScreen (403/404/offline/stale states)", () => {
  const layout = read("src/components/detail/DetailLayout.tsx");
  for (const k of ["dtForbiddenTitle", "dtMissingTitle", "dtOfflineTitle", "dtStale", "dtSyncPending"]) assert.match(layout, new RegExp(k));
  for (const r of ["proposals", "products", "issuance", "policies", "payments", "partners", "bordereaux"]) {
    assert.match(read(`app/carrier/${r}/[id].tsx`), /DetailScreen/);
  }
});

test("issuance is decided in the detail with confirmation; no policy number from the client (ISS-001/003)", () => {
  const d = read("app/carrier/issuance/[id].tsx");
  assert.match(d, /confirm:/);
  assert.match(d, /stepUpPurpose/);
  // Launch fix 2026-09-29: the server capabilities drive the buttons (src/lib/carrierDecisions.ts issuanceActions).
  assert.match(d, /issuanceActions\(item, canDecide\)/);
  assert.match(read("src/lib/carrierDecisions.ts"), /item\.capabilities/);
  assert.doesNotMatch(d, /policy_number:/);
  assert.doesNotMatch(read("app/carrier/issuance.tsx"), /approveIssuance/);
  for (const dir of ["app/agent", "app/broker"]) {
    // Intermediaries never call the carrier issuance endpoints.
    assert.doesNotMatch(read(`${dir}/index.tsx`), /approveIssuance|\/issuance\//);
  }
});

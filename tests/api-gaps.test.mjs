import test from "node:test";
import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("phase 4 endpoints are wired through typed contracts", () => {
  const extra = read("src/api/extra.ts");
  for (const path of [
    "/mobile/broker/statements",
    "/mobile/broker/commission-accruals",
    "/mobile/carrier/settlements/",
    "/mobile/carrier/bordereaux",
    "/timeline",
    "/evidence",
    "/public/institutions",
  ])
    assert.ok(extra.includes(path), `missing ${path}`);
  assert.match(read("app/broker/receivables.tsx"), /BrokerFinanceApi\.statements/);
  assert.match(read("app/broker/receivables.tsx"), /BrokerFinanceApi\.accruals/);
  assert.match(read("app/carrier/bordereaux.tsx"), /CarrierFinanceApi\.bordereaux/);
  assert.match(read("app/carrier/settlement/[id].tsx"), /CarrierFinanceApi\.settlement/);
  assert.match(read("app/claim/[id].tsx"), /ClaimRecordsApi\.timeline/);
  assert.match(read("app/claim/[id].tsx"), /ClaimRecordsApi\.evidence/);
  assert.match(read("app/agent/wallet.tsx"), /AgentApi\.withdrawals/);
  const layout = read("app/_layout.tsx");
  assert.match(layout, /carrier\/bordereaux/);
  assert.match(layout, /carrier\/settlement\/\[id\]/);
});

test("institution directory comes from the backend, not bundled data", () => {
  assert.equal(existsSync(new URL("../src/data/insurers.ts", import.meta.url)), false);
  assert.equal(existsSync(new URL("../src/data/brokers.ts", import.meta.url)), false);
  for (const f of [
    "app/institutions/insurers.tsx",
    "app/institutions/brokers.tsx",
    "app/institutions/insurer/[id].tsx",
    "app/institutions/broker/[id].tsx",
  ])
    assert.match(read(f), /InstitutionsApi/);
});

test("customer group has a single guard (R7)", () => {
  const layout = read("app/_layout.tsx");
  assert.match(layout, /guard=\{customer\}>\s*<Stack\.Screen name="\(customer\)" \/>/);
  assert.doesNotMatch(read("app/(customer)/_layout.tsx"), /Redirect/);
});

test("leftovers: tokens, fonts, responsive grids, cleanup", () => {
  assert.doesNotMatch(read("app/index.tsx"), /#[0-9A-Fa-f]{6}/);
  assert.doesNotMatch(read("package.json"), /manrope/);
  assert.match(read("app/workspace/[role].tsx"), /useColumns\(/);
  assert.doesNotMatch(read("app/workspace/[role].tsx"), /48%/);
  assert.match(read("app/workspace/[role]/module/[module].tsx"), /width < 600/);
  assert.match(read("app/welcome.tsx"), /\[width\]\)/);
  assert.match(read("src/components/onboarding/OnboardingParts.tsx"), /minItem: 150/);
  assert.equal(
    existsSync(new URL("../OPESINSURE_CAMEROON_DEMO_DATA_v1.json", import.meta.url)),
    false,
  );
});

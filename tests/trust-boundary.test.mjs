import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, existsSync } from "node:fs";
const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
test("payment completion is server authoritative", () => {
  const payment = read("app/payment.tsx");
  assert.doesNotMatch(payment, /I have approved/);
  assert.match(payment, /status\s*===\s*["']SUCCEEDED["']/);
});
test("policy confirmation requires issued policy payload", () => {
  const confirmation = read("app/confirmation.tsx");
  assert.match(confirmation, /POLICY_ISSUED/);
  assert.match(confirmation, /next\.policy/);
  assert.doesNotMatch(confirmation, /OI-CM-2026/);
});
test("tokens and pending payment use secure storage", () => {
  const client = read("src/api/client.ts");
  assert.match(client, /expo-secure-store/);
  assert.match(client, /pending_payment_id/);
});
test("workspaces come from authenticated bootstrap", () => {
  const session = read("src/store/session.ts");
  assert.match(session, /bootstrap\.workspaces/);
  assert.match(session, /membership_id/);
  assert.equal(
    existsSync(new URL("../src/auth/permissions.ts", import.meta.url)),
    false,
  );
});
test("required mobile integration contracts are declared", () => {
  const client = read("src/api/client.ts");
  for (const route of [
    "/auth/mobile/otp/request",
    "/auth/mobile/otp/verify",
    "/auth/mobile/refresh",
    "/auth/mobile/session",
    "/mobile/purchases/",
  ])
    assert.match(client, new RegExp(route.replaceAll("/", "\\/")));
});
test("transaction and claim routes are customer protected", () => {
  const layout = read("app/_layout.tsx");
  for (const route of [
    "quote/product",
    "checkout",
    "payment",
    "confirmation",
    "policy/[id]",
    "claim/new",
    "claim/[id]",
  ])
    assert.match(layout, new RegExp(route.replace(/[\[\]]/g, "\\$&")));
  assert.match(layout, /Stack\.Protected guard=\{customer\}/);
});
test("public verification and claims use API contracts", () => {
  const client = read("src/api/client.ts");
  assert.match(client, /\/public\/insurance\/verify/);
  assert.match(client, /ClaimsApi/);
  assert.match(client, /api<Claim>\(\s*["']\/claims/);
});
test("every insurance product has a dedicated risk schema", () => {
  const risk = read("app/quote/risk.tsx");
  for (const product of ["motor", "health", "travel", "home", "life"])
    assert.match(risk, new RegExp(`${product}:`));
});
test("claim evidence and policy servicing are API backed", () => {
  const client = read("src/api/client.ts");
  for (const contract of [
    "uploadEvidence",
    "submitDeclaration",
    "appeal",
    "certificate",
    "renewalQuote",
    "service-requests",
  ])
    assert.match(client, new RegExp(contract));
});
test("runtime protects offline, notification and biometric boundaries", () => {
  const runtime = read("src/components/AppRuntime.tsx");
  assert.match(runtime, /getNetworkStateAsync/);
  assert.match(runtime, /LocalAuthentication/);
  assert.match(runtime, /safePaths/);
});
test("partner workspace modules require server permissions", () => {
  const workspace = read("app/workspace/[role].tsx");
  assert.match(workspace, /workspace\.permissions\.includes/);
  assert.match(workspace, /WorkspaceApi\.dashboard/);
});
test("Cameroon demo dataset covers every mobile persona", () => {
  const demo = JSON.parse(
    read("src/data/demo/opesinsure-cameroon-demo.v1.json"),
  );
  assert.equal(demo.meta.production_forbidden, true);
  assert.equal(demo.demo_auth.accounts.length, 11);
  assert.equal(demo.workspaces.length, 11);
  for (const key of [
    "quotes",
    "offers",
    "proposals",
    "payments",
    "policies",
    "claims",
    "claim_evidence",
    "claim_timeline",
    "finance",
    "operations",
    "risk_and_compliance",
    "screen_scenarios",
  ])
    assert.ok(demo[key], `missing ${key}`);
});
test("demo mode is explicit and separate from production API mode", () => {
  const client = read("src/api/client.ts");
  const adapter = read("src/demo/api.ts");
  assert.match(client, /EXPO_PUBLIC_DEMO_MODE === "true"/);
  assert.match(adapter, /fixed_otp/);
  assert.match(adapter, /Demo adapter has no fixture/);
});
test("customer core completion routes are protected and API backed", () => {
  const layout = read("app/_layout.tsx");
  const client = read("src/api/client.ts");
  for (const route of [
    "onboarding/kyc",
    "assets/index",
    "payments/index",
    "wallet/index",
    "delivery/[id]",
    "quote/questions",
  ])
    assert.match(layout, new RegExp(route.replace(/[\[\]]/g, "\\$&")));
  for (const api of [
    "KycApi",
    "AssetsApi",
    "DisclosureApi",
    "PaymentsApi",
    "WalletApi",
  ])
    assert.match(client, new RegExp(`export const ${api}`));
});
test("customer core demo fixtures cover all five flows", () => {
  const demo = JSON.parse(
    read("src/data/demo/opesinsure-cameroon-demo.v1.json"),
  );
  for (const key of [
    "kyc_profiles",
    "asset_documents",
    "disclosure_sessions",
    "payment_receipts",
    "refund_requests",
    "policy_documents",
    "sticker_deliveries",
  ])
    assert.ok(demo[key], `missing ${key}`);
});

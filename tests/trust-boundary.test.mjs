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
test("patch two customer lifecycle routes are protected and API backed", () => {
  const layout = read("app/_layout.tsx");
  const client = read("src/api/client.ts");
  for (const route of [
    "quotes/index",
    "documents/[id]",
    "services/index",
    "notifications/index",
    "support/index",
  ])
    assert.match(layout, new RegExp(route.replace(/[\[\]]/g, "\\$&")));
  for (const api of [
    "QuotesApi",
    "DocumentsApi",
    "PolicyServicesApi",
    "NotificationsApi",
    "SupportApi",
  ])
    assert.match(client, new RegExp(`export const ${api}`));
});
test("secure document access and customer service mutations remain server mediated", () => {
  const client = read("src/api/client.ts");
  assert.match(client, /documents\/\$\{id\}\/access/);
  assert.match(client, /policy-service-requests/);
  assert.match(client, /support\/cases/);
  assert.match(client, /idempotent:\s*true/);
});
test("patch two demo fixtures cover lifecycle states", () => {
  const demo = JSON.parse(
    read("src/data/demo/opesinsure-cameroon-demo.v1.json"),
  );
  for (const key of [
    "quote_history",
    "secure_documents",
    "policy_service_cases",
    "customer_notifications",
    "support_cases",
  ])
    assert.ok(
      Array.isArray(demo[key]) && demo[key].length > 0,
      `missing ${key}`,
    );
});
test("patch three claims completion routes are customer protected", () => {
  const layout = read("app/_layout.tsx");
  for (const route of [
    "claim/emergency",
    "claim/[id]/incident",
    "claim/[id]/parties",
    "claim/[id]/checklist",
    "claim/[id]/inspection",
    "claim/[id]/repair",
    "claim/[id]/settlement",
    "claim/[id]/settlement-payment",
  ])
    assert.match(layout, new RegExp(route.replace(/[\[\]]/g, "\\$&")));
});
test("claims completion decisions and assistance are API mediated", () => {
  const client = read("src/api/client.ts");
  for (const contract of [
    "ClaimsCompletionApi",
    "evidenceRequirements",
    "rescheduleInspection",
    "decideSettlement",
    "requestEmergencyAssistance",
  ])
    assert.match(client, new RegExp(contract));
  assert.match(client, /settlement\/decision/);
  assert.match(client, /idempotent:\s*true/);
});
test("patch three demo data covers operational claim lifecycle", () => {
  const demo = JSON.parse(
    read("src/data/demo/opesinsure-cameroon-demo.v1.json"),
  );
  for (const key of [
    "claim_incidents",
    "claim_parties",
    "claim_evidence_requirements",
    "claim_inspections",
    "claim_repairs",
    "claim_settlements",
  ])
    assert.ok(
      Array.isArray(demo[key]) && demo[key].length > 0,
      `missing ${key}`,
    );
});
test("settlement payment screen warns against advance fee fraud", () => {
  const screen = read("app/claim/[id]/settlement-payment.tsx");
  assert.match(screen, /never asks you to pay a fee/i);
  assert.match(screen, /server/i);
});
test("patch four agent routes use a dedicated role guard", () => {
  const layout = read("app/_layout.tsx");
  const role = read("app/(auth)/role.tsx");
  assert.match(layout, /Stack\.Protected guard=\{agent\}/);
  for (const route of [
    "agent/index",
    "agent/onboarding",
    "agent/clients/index",
    "agent/sales/new",
    "agent/renewals",
    "agent/wallet",
    "agent/withdrawal",
    "agent/offline",
  ])
    assert.match(layout, new RegExp(route));
  assert.match(role, /portal === "agent"/);
});
test("agent operations are typed and server mediated", () => {
  const client = read("src/api/client.ts");
  for (const contract of [
    "AgentApi",
    "createClient",
    "requestPayment",
    "requestWithdrawal",
    "retryOffline",
  ])
    assert.match(client, new RegExp(contract));
  assert.match(client, /Idempotency-Key/);
});
test("agent UI protects client payment and origin ownership", () => {
  const sale = read("app/agent/sales/new.tsx");
  const client = read("app/agent/clients/new.tsx");
  assert.match(sale, /never collect or enter/i);
  assert.match(client, /cannot overwrite/i);
  assert.match(client, /consent/i);
});
test("patch four demo data covers field operations", () => {
  const demo = JSON.parse(
    read("src/data/demo/opesinsure-cameroon-demo.v1.json"),
  );
  assert.ok(demo.agent_mobile.profile);
  for (const key of ["clients", "sales", "renewals", "offline_queue"])
    assert.ok(
      Array.isArray(demo.agent_mobile[key]) &&
        demo.agent_mobile[key].length > 0,
      `missing ${key}`,
    );
});
test("patch five isolates broker and carrier routes with dedicated guards", () => {
  const layout = read("app/_layout.tsx");
  const role = read("app/(auth)/role.tsx");
  assert.match(layout, /Stack\.Protected guard=\{broker\}/);
  assert.match(layout, /Stack\.Protected guard=\{carrier\}/);
  for (const route of [
    "broker/index",
    "broker/clients",
    "broker/compliance",
    "carrier/index",
    "carrier/referrals/index",
    "carrier/issuance",
    "carrier/claims",
    "carrier/settlements",
  ])
    assert.match(layout, new RegExp(route.replace(/[\[\]]/g, "\\$&")));
  assert.match(role, /router\.replace\("\/broker"\)/);
  assert.match(role, /router\.replace\("\/carrier"\)/);
});
test("broker and carrier mobile operations use typed API contracts", () => {
  const client = read("src/api/client.ts");
  for (const contract of [
    "BrokerApi",
    "togglePublication",
    "CarrierApi",
    "decideReferral",
  ])
    assert.match(client, new RegExp(contract));
  assert.match(client, /idempotent:\s*true/);
});
test("carrier underwriting decisions require confirmation and a note", () => {
  const screen = read("app/carrier/referrals/[id].tsx");
  assert.match(screen, /Alert\.alert/);
  assert.match(screen, /disabled=\{note\.length\s*<\s*5\}/);
});
test("patch five demo data covers broker and carrier operations", () => {
  const demo = JSON.parse(
    read("src/data/demo/opesinsure-cameroon-demo.v1.json"),
  );
  for (const root of ["broker_mobile", "carrier_mobile"]) assert.ok(demo[root]);
  for (const key of [
    "clients",
    "production",
    "renewals",
    "receivables",
    "compliance",
    "publications",
  ])
    assert.ok(Array.isArray(demo.broker_mobile[key]), `missing broker ${key}`);
  for (const key of ["referrals", "issuance", "claims", "settlements"])
    assert.ok(
      Array.isArray(demo.carrier_mobile[key]),
      `missing carrier ${key}`,
    );
});

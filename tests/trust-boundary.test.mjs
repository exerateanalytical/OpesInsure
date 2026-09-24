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
  assert.match(client, /api<Claim>\(\s*["'`]\/mobile\/claims/);
});
test("every insurance product has a dedicated risk schema", () => {
  // Local fallback schemas (the server risk-schema endpoint wins when present).
  const risk = read("src/lib/riskSchema.ts");
  for (const product of ["MOTOR", "HEALTH", "TRAVEL", "HOME", "LIFE", "BUSINESS", "ACCIDENT"])
    assert.match(risk, new RegExp(`${product}: \\[`));
  assert.match(read("app/quote/risk.tsx"), /CatalogueApi\.riskSchema/);
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
  const resilience = read("src/store/resilience.ts");
  assert.match(resilience, /getNetworkStateAsync/);
  assert.match(runtime, /LocalAuthentication/);
  // Notification taps only open validated in-app routes (allow-list).
  assert.match(runtime, /resolveNotificationTarget/);
  assert.match(read("src/lib/customerLogic.ts"), /ALLOWED_PREFIXES/);
});
test("partner workspace modules require server permissions", () => {
  const workspace = read("app/workspace/[role].tsx");
  assert.match(workspace, /perms\.includes\(module\.permission\)/);
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
test("there is no in-app fake API: every build talks to the real server", () => {
  const client = read("src/api/client.ts");
  assert.doesNotMatch(client, /demoApi|EXPO_PUBLIC_DEMO_MODE/);
  assert.ok(!existsSync(new URL("../src/demo/api.ts", import.meta.url)));
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
  assert.match(layout, /portal === "agent"/);
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
  assert.match(role, /router\.replace\(portalRoute\(/);
  const session = read("src/store/session.ts");
  assert.match(session, /return "\/broker"/);
  assert.match(session, /return "\/carrier"/);
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
test("patch six provides a device-encrypted offline vault and clears it on logout", () => {
  const vault = read("src/offline/vault.ts");
  const session = read("src/store/session.ts");
  assert.match(vault, /expo-secure-store/);
  assert.match(vault, /WHEN_UNLOCKED_THIS_DEVICE_ONLY/);
  assert.match(vault, /maxSecurePayloadBytes/);
  assert.match(session, /OfflineVault\.clearSensitiveData/);
});
test("patch six synchronization remains server mediated and conflict aware", () => {
  const client = read("src/api/client.ts");
  const store = read("src/store/resilience.ts");
  assert.match(client, /export const SyncApi/);
  assert.match(client, /\/mobile\/sync\/operations/);
  assert.match(store, /apiError\.status === 409/);
  assert.match(store, /409 \? "CONFLICT"/);
  assert.match(client, /idempotent:\s*true/);
});
test("patch six exposes protected sync and low-data controls", () => {
  const layout = read("app/_layout.tsx");
  const sync = read("app/sync/index.tsx");
  const settings = read("app/account/data-usage.tsx");
  assert.match(layout, /Stack\.Protected guard=\{authenticated\}/);
  assert.match(layout, /sync\/index/);
  assert.match(settings, /wifi_only_uploads/);
  assert.match(settings, /compress_images/);
  assert.match(sync, /transactionsPaused/);
});
test("patch six has bilingual resilience copy and accessibility semantics", () => {
  const strings = read("src/i18n/fr.ts");
  const ui = read("src/components/ui.tsx");
  const fixtures = JSON.parse(read("src/data/demo/resilience.v1.json"));
  assert.match(strings, /Centre de synchronisation/);
  assert.match(strings, /Brouillons hors connexion sécurisés/);
  assert.match(ui, /maxFontSizeMultiplier/);
  assert.match(ui, /accessibilityLiveRegion/);
  assert.equal(fixtures.meta.production_forbidden, true);
  assert.ok(fixtures.operations.some((item) => item.state === "CONFLICT"));
});
test("patch seven fails closed for unsafe production configuration", () => {
  const environment = read("src/config/environment.ts");
  assert.match(environment, /DEMO_MODE_FORBIDDEN/);
  assert.match(environment, /HTTPS_API_REQUIRED/);
  assert.match(environment, /NON_PRODUCTION_API_HOST/);
  assert.match(environment, /PRODUCTION_CHANNEL_REQUIRED/);
});
test("patch seven enforces server-driven release and maintenance gates", () => {
  const runtime = read("src/store/runtime.ts");
  const client = read("src/api/client.ts");
  const gate = read("src/components/RuntimeGate.tsx");
  assert.match(client, /\/mobile\/runtime\/bootstrap/);
  assert.match(runtime, /force_update/);
  assert.match(runtime, /minimum_version/);
  assert.match(runtime, /maintenance\.active/);
  assert.match(gate, /Update required/);
});
test("patch seven requires purpose-bound step-up grants for sensitive actions", () => {
  const client = read("src/api/client.ts");
  const screen = read("app/security/step-up.tsx");
  for (const purpose of [
    "PAYMENT_REFUND_REQUEST",
    "COMMISSION_WITHDRAWAL",
    "CLAIM_SETTLEMENT_DECISION",
  ]) assert.match(client, new RegExp(purpose));
  assert.match(client, /X-Step-Up-Grant/);
  assert.match(client, /WHEN_UNLOCKED_THIS_DEVICE_ONLY/);
  assert.match(screen, /StepUpApi\.verify/);
});
test("patch seven expires invalid sessions and hides inactive-app content", () => {
  const client = read("src/api/client.ts");
  const runtime = read("src/components/AppRuntime.tsx");
  assert.match(client, /SESSION_EXPIRED/);
  assert.match(client, /sessionExpiredListeners/);
  assert.match(runtime, /privacyCovered/);
  assert.match(runtime, /t\("privacyCover"\)/);
  assert.match(read("src/i18n/en.ts"), /Sensitive information is hidden/);
});
test("patch seven telemetry rejects common PII fields", () => {
  const telemetry = read("src/security/telemetry.ts");
  assert.match(telemetry, /name\|email\|phone\|token\|address\|document/);
  assert.match(telemetry, /Telemetry must never block/);
  assert.doesNotMatch(telemetry, /console\.log/);
});
test("patch seven demo fixtures and service-status routes are isolated", () => {
  const fixture = JSON.parse(read("src/data/demo/production-readiness.v1.json"));
  const layout = read("app/_layout.tsx");
  assert.equal(fixture.meta.production_forbidden, true);
  assert.equal(fixture.step_up_scenarios.length, 3);
  assert.match(layout, /security\/step-up/);
  assert.match(layout, /system\/status/);
});
test("patch eight defines production EAS profiles and dynamic native configuration", () => {
  const eas = JSON.parse(read("eas.json"));
  const config = read("app.config.js");
  assert.equal(eas.build.production.channel, "production");
  assert.equal(eas.build.production.env.EXPO_PUBLIC_SHOW_DEMO_LOGIN, "false");
  assert.match(config, /runtimeVersion/);
  assert.match(config, /associatedDomains/);
  assert.match(config, /autoVerify:\s*true/);
});
test("patch eight hardens Android native release builds", () => {
  const plugin = read("plugins/withOpesInsureSecurity.js");
  assert.match(plugin, /FLAG_SECURE/);
  assert.match(plugin, /android:allowBackup/);
  assert.match(plugin, /android:usesCleartextTraffic/);
  assert.match(plugin, /withMainActivity/);
});
test("patch eight keeps device integrity server mediated", () => {
  const client = read("src/api/client.ts");
  const screen = read("app/security/device-status.tsx");
  assert.match(client, /DeviceSecurityApi/);
  assert.match(client, /device-attestation\/nonce/);
  assert.match(client, /device-attestation\/assess/);
  assert.match(screen, /UNAVAILABLE_MANAGED_RUNTIME/);
  assert.match(screen, /never claims.*JavaScript check proves device integrity/i);
});
test("patch eight verified links retain signing placeholders as release blockers", () => {
  const android = read("store/associations/assetlinks.json");
  const apple = read("store/associations/apple-app-site-association");
  const doctor = read("scripts/release-doctor.mjs");
  assert.match(android, /REPLACE_WITH_PLAY_APP_SIGNING_SHA256/);
  assert.match(apple, /REPLACE_WITH_APPLE_TEAM_ID/);
  assert.match(doctor, /ANDROID_ASSOCIATION_PLACEHOLDER/);
  assert.match(doctor, /process\.exit\(1\)/);
});
test("patch eight includes automated device flows and staged rollback controls", () => {
  assert.match(read("maestro/smoke-customer.yaml"), /appId: com\.opesware\.opesinsure/);
  assert.match(read("maestro/smoke-security.yaml"), /Run server device check/);
  const rollback = read("release/STAGED_ROLLOUT_AND_ROLLBACK.md");
  assert.match(rollback, /5%, 20%, 50% and 100%/);
  assert.match(rollback, /backend remains authoritative/i);
});
test("patch eight tracks external MASVS evidence without claiming completion", () => {
  const tracker = JSON.parse(read("security/OWASP_MASVS_EVIDENCE_TRACKER.json"));
  assert.equal(tracker.release_blocking, true);
  assert.equal(tracker.status, "EVIDENCE_REQUIRED");
  assert.ok(tracker.controls.every((control) => control.status !== "COMPLETE"));
});

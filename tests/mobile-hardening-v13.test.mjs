// 1.3.0 hardening (docs/audit/MOBILE_INDUSTRY_STANDARD_AUDIT_V1.md):
// behaviour tests for the pure helpers + guard-rail source checks.
import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  LOCK_DEFAULTS,
  backoffDelay,
  isIdleExpired,
  lockSuspended,
  resolveLockPolicy,
  runtimeCheckDue,
  shouldRelock,
  withoutRelock,
} from "../src/lib/appLock.ts";
import { SECURE_CHUNK_CHARS, chunkKey, countKey, joinChunks, splitChunks } from "../src/lib/chunking.ts";
import { API_ERROR_COPY, apiErrorCopyKey, isStaleRecord } from "../src/lib/apiErrors.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const json = (p) => JSON.parse(read(p));

// --- P0-1 app lock: overlay, grace period, idle timeout -----------------------

test("relock only after the grace period, never while suspended", () => {
  const grace = 60_000;
  assert.equal(shouldRelock(null, 1_000_000, grace), false);
  assert.equal(shouldRelock(1_000_000, 1_000_000 + 30_000, grace), false, "camera round-trip keeps state");
  assert.equal(shouldRelock(1_000_000, 1_000_000 + 60_000, grace), true);
  assert.equal(shouldRelock(1_000_000, 1_000_000 + 600_000, grace, true), false, "picker in progress");
});

test("idle timeout ends the session after the configured time", () => {
  assert.equal(isIdleExpired(null, 5, 10), false);
  assert.equal(isIdleExpired(0, 899_999, 900_000), false);
  assert.equal(isIdleExpired(0, 900_000, 900_000), true);
});

test("lock policy: server value wins, then build env, then defaults; idle >= grace", () => {
  assert.deepEqual(resolveLockPolicy(null, {}), {
    relockGraceMs: LOCK_DEFAULTS.relockGraceSeconds * 1000,
    idleTimeoutMs: LOCK_DEFAULTS.idleTimeoutSeconds * 1000,
  });
  assert.equal(resolveLockPolicy(null, { relockGraceSeconds: "120" }).relockGraceMs, 120_000);
  assert.equal(resolveLockPolicy({ relock_grace_seconds: 30 }, { relockGraceSeconds: "120" }).relockGraceMs, 30_000);
  assert.equal(resolveLockPolicy({ session_idle_timeout_seconds: 300 }, {}).idleTimeoutMs, 300_000);
  assert.equal(resolveLockPolicy({ relock_grace_seconds: 900, session_idle_timeout_seconds: 60 }, {}).idleTimeoutMs, 900_000);
  assert.equal(resolveLockPolicy({ relock_grace_seconds: -5 }, { relockGraceSeconds: "abc" }).relockGraceMs, 60_000);
});

test("withoutRelock suspends the lock during an app-initiated flow", async () => {
  assert.equal(lockSuspended(), false);
  let during = null;
  const value = await withoutRelock(async () => {
    during = lockSuspended();
    return 42;
  });
  assert.equal(value, 42);
  assert.equal(during, true);
  assert.equal(lockSuspended(), true, "stays on briefly for the late AppState event");
});

test("AppRuntime keeps the navigation stack mounted and overlays the cover/lock", () => {
  const runtime = read("src/components/AppRuntime.tsx");
  assert.doesNotMatch(runtime, /if \(privacyCovered\)\s*\n?\s*return/);
  assert.doesNotMatch(runtime, /if \(locked\)\s*\n?\s*return/);
  assert.match(runtime, /StyleSheet\.absoluteFill, styles\.privacy/);
  assert.match(runtime, /StyleSheet\.absoluteFill, styles\.lock/);
  assert.match(runtime, /<View style=\{styles\.flex\}>\{children\}<\/View>/);
  assert.match(runtime, /shouldRelock\(since, Date\.now\(\), policyRef\.current\.relockGraceMs, lockSuspended\(\)\)/);
  assert.match(runtime, /isIdleExpired/);
  assert.match(runtime, /importantForAccessibility=\{overlay \? "no-hide-descendants" : "auto"\}/);
  // Pickers and camera run without re-lock.
  for (const f of ["app/claim/[id]/evidence.tsx", "app/onboarding/kyc.tsx"])
    assert.match(read(f), /withoutRelock\(\(\) => ImagePicker\.launchCameraAsync/);
});

// --- P1-9 runtime polling ----------------------------------------------------

test("no 10 s polling: runtime checks on foreground, network backoff while offline", () => {
  const runtime = read("src/components/AppRuntime.tsx");
  assert.doesNotMatch(runtime, /setInterval\(\(\) => void check\(\), 10000\)/);
  assert.match(runtime, /checkRuntimeIfDue/);
  assert.match(read("src/store/runtime.ts"), /runtimeCheckDue\(get\(\)\.lastCheckedAt, Date\.now\(\)\)/);
  assert.equal(runtimeCheckDue(null, 0), true);
  assert.equal(runtimeCheckDue(0, 60_000), false);
  assert.equal(runtimeCheckDue(0, 300_000), true);
  assert.equal(backoffDelay(0), 5000);
  assert.equal(backoffDelay(1), 10_000);
  assert.equal(backoffDelay(30), 300_000);
});

// --- P0-2 load states ---------------------------------------------------------

test("audited screens load through useLoad/StatePanel, never a bare .then(setX)", () => {
  const screens = [
    "app/services/index.tsx",
    "app/services/[id].tsx",
    "app/assets/index.tsx",
    "app/assets/[id].tsx",
    "app/delivery/[id].tsx",
    "app/delivery/[id]/address.tsx",
    "app/claim/[id]/checklist.tsx",
    "app/claim/[id]/incident.tsx",
    "app/claim/[id]/inspection.tsx",
    "app/claim/[id]/parties.tsx",
    "app/claim/[id]/repair.tsx",
    "app/claim/[id]/settlement.tsx",
    "app/claim/[id]/settlement-payment.tsx",
    "app/account/notifications.tsx",
  ];
  for (const f of screens) {
    const src = read(f);
    assert.doesNotMatch(src, /Api\.\w+\([^)]*\)\.then\(set/, `${f} still uses .then(setX)`);
    assert.match(src, /useLoad\(/, `${f} must use useLoad`);
    assert.match(src, /ErrorState|StatePanel/, `${f} must render an error+retry state`);
  }
});

// --- Security -----------------------------------------------------------------

test("tokens and PII caches are device-only SecureStore, not AsyncStorage", () => {
  const client = read("src/api/client.ts");
  assert.match(client, /SecureStore\.setItemAsync\(keys\.access, access, DEVICE_ONLY\)/);
  assert.match(client, /SecureStore\.setItemAsync\(keys\.refresh, refresh, DEVICE_ONLY\)/);
  assert.match(read("src/security/secureJson.ts"), /WHEN_UNLOCKED_THIS_DEVICE_ONLY/);
  const session = read("src/store/session.ts");
  assert.doesNotMatch(session, /AsyncStorage\.setItem\(CACHE_KEY/);
  assert.match(session, /SecureJson\.write\(CACHE_KEY, bootstrap\)/);
  assert.match(session, /AsyncStorage\.removeItem\(LEGACY_CACHE_KEY\)/);
  const prefs = read("src/store/preferences.ts");
  assert.match(prefs, /SecureJson\.write\(keys\.profileExtras, extras\)/);
  assert.match(prefs, /SecureJson\.remove\(keys\.profileExtras\)/);
});

test("biometric lock is offered to every role, not only customers", () => {
  const layout = read("app/_layout.tsx");
  const authed = layout.slice(layout.indexOf("<Stack.Protected guard={authenticated}>"), layout.indexOf("<Stack.Protected guard={customer}>"));
  assert.match(authed, /account\/security/);
  assert.match(read("src/components/portal/PortalShell.tsx"), /router\.push\("\/account\/security"/);
});

// --- Reliability: offline queue ------------------------------------------------

test("secure chunking round-trips values larger than one SecureStore item", () => {
  const big = JSON.stringify({ ops: Array.from({ length: 200 }, (_, i) => ({ id: `op-${i}`, payload: "x".repeat(40) })) });
  assert.ok(big.length > 6000);
  const parts = splitChunks(big);
  assert.ok(parts.length > 3);
  assert.ok(parts.every((p) => p.length <= SECURE_CHUNK_CHARS));
  assert.equal(joinChunks(parts), big);
  assert.equal(joinChunks([parts[0], null]), null, "a missing chunk is corrupt, not partial data");
  assert.deepEqual(splitChunks(""), [""]);
  assert.equal(chunkKey("k", 2), "k.c2");
  assert.equal(countKey("k"), "k.n");
  const vault = read("src/offline/vault.ts");
  assert.match(vault, /SecureJson\.write\(queueKey, queue\)/);
  assert.match(vault, /MAX_QUEUE_OPERATIONS = 50/);
  assert.match(vault, /OfflineQueueFullError/);
  assert.doesNotMatch(vault, /maxSecurePayloadBytes = 6000/);
});

// --- Backend error codes --------------------------------------------------------

test("new backend error codes map to specific EN/FR copy", () => {
  const en = read("src/i18n/en.ts");
  const fr = read("src/i18n/fr.ts");
  for (const code of ["STALE_RECORD", "DUPLICATE_SUBMISSION", "AUTHORITY_EXCEEDED", "PAYMENT_OK_ISSUANCE_FAILED", "INTEGRATION_UNAVAILABLE"]) {
    const key = apiErrorCopyKey(code);
    assert.ok(key, `${code} has no copy`);
    assert.match(en, new RegExp(`\\n  ${key}: "`), `en.ts missing ${key}`);
    assert.match(fr, new RegExp(`\\n  ${key}: "`), `fr.ts missing ${key}`);
  }
  assert.equal(apiErrorCopyKey("stale_record"), API_ERROR_COPY.STALE_RECORD);
  assert.equal(apiErrorCopyKey("SOMETHING_ELSE"), null);
  assert.equal(apiErrorCopyKey(undefined), null);
  assert.equal(isStaleRecord("STALE_RECORD"), true);
  // ApiError picks the localized message; i18n registers the localizer.
  assert.match(read("src/api/client.ts"), /super\(localizeError\?\.\(code, status\) \?\? message\)/);
  assert.match(read("src/i18n/index.ts"), /setApiErrorLocalizer\(/);
  assert.match(read("src/components/StatePanel.tsx"), /apiErrorCopyKey\(e\.code\)/);
});

// --- OTA / release --------------------------------------------------------------

test("release config: one version, appVersion runtime, separate APK channel", () => {
  const pkg = json("package.json");
  const app = json("app.json");
  const eas = json("eas.json");
  assert.equal(pkg.version, "1.3.0");
  assert.equal(app.expo.version, pkg.version);
  assert.deepEqual(app.expo.runtimeVersion, { policy: "appVersion" });
  const config = read("app.config.js");
  assert.match(config, /require\("\.\/package\.json"\)/);
  assert.match(config, /runtimeVersion: \{ policy: "appVersion" \}/);
  assert.doesNotMatch(config, /version: "1\.2\.2"/);
  assert.doesNotMatch(read("src/config/environment.ts"), /"1\.1\.0"/);
  assert.equal(eas.build.production.channel, "production");
  assert.equal(eas.build["production-apk"].channel, "production-apk");
  assert.ok(!app.expo.android.permissions.includes("android.permission.USE_FINGERPRINT"));
  assert.match(read(".gitignore"), /^credentials\/$/m);
});

test("production bundles fall back to the production API host (OTA without env is safe)", () => {
  const env = read("src/config/environment.ts");
  assert.match(env, /PRODUCTION_API_BASE_URL = "https:\/\/insurance\.opesdatacenter\.tech\/api\/v1"/);
  assert.match(env, /environment === "production" \? PRODUCTION_API_BASE_URL : ""/);
  assert.match(read("src/api/client.ts"), /const API_URL = environmentConfig\.apiBaseUrl/);
});

// --- i18n / a11y / Play ---------------------------------------------------------

test("shared panels and the crash screen are localized", () => {
  const panel = read("src/components/StatePanel.tsx");
  assert.doesNotMatch(panel, /We could not load this information/);
  assert.doesNotMatch(panel, /label="Retry"/);
  const boundary = read("src/components/ProductionErrorBoundary.tsx");
  assert.doesNotMatch(boundary, />Something went wrong</);
  assert.match(boundary, /translateNow\("goHome"\)/);
  assert.doesNotMatch(read("src/components/ui.tsx"), /accessibilityLabel="Go back"/);
});

test("low-contrast grey tokens meet AA", () => {
  const tokens = read("src/theme/tokens.ts");
  const hex = (name) => tokens.match(new RegExp(`${name}: '(#[0-9A-F]{6})'`))[1];
  const lum = (h) => {
    const c = [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16) / 255).map((x) => (x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4));
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  };
  const ratio = (a, b) => (Math.max(lum(a), lum(b)) + 0.05) / (Math.min(lum(a), lum(b)) + 0.05);
  assert.ok(ratio(hex("neutral500"), "#FFFFFF") >= 4.5);
  assert.ok(ratio(hex("neutral500"), hex("neutral50")) >= 4.5);
  assert.ok(ratio(hex("neutral400"), "#FFFFFF") >= 3);
});

test("privacy policy and account deletion links are reachable in-app for every role", () => {
  const links = read("src/components/LegalLinks.tsx");
  assert.match(links, /links\.privacy/);
  assert.match(links, /links\.accountDeletion/);
  assert.match(read("src/config/environment.ts"), /\/account\/delete/);
  assert.match(read("app/account/privacy.tsx"), /<LegalLinks \/>/);
  assert.match(read("src/components/portal/PortalShell.tsx"), /<LegalLinks \/>/);
});

test("largest lists are virtualized", () => {
  for (const f of ["app/(customer)/(tabs)/policies.tsx", "app/payments/index.tsx", "app/notifications/index.tsx", "app/quotes/index.tsx", "app/services/index.tsx"])
    assert.match(read(f), /<FlatList/, `${f} is not virtualized`);
  assert.match(read("app/(customer)/(tabs)/claims.tsx"), /<SectionList/);
});

test("carrier amounts parse FR and EN input and never produce NaN", async () => {
  const { parseAmountMinor } = await import("../src/lib/purchase.ts");
  assert.equal(parseAmountMinor("1 000,50"), 100050);
  assert.equal(parseAmountMinor("1 000,50"), 100050);
  assert.equal(parseAmountMinor("1.000,50"), 100050);
  assert.equal(parseAmountMinor("1,000.50"), 100050);
  assert.equal(parseAmountMinor("250 000"), 25000000);
  assert.equal(parseAmountMinor("1.000.000"), 100000000);
  assert.equal(parseAmountMinor("1000.5"), 100050);
  assert.equal(parseAmountMinor("abc"), null);
  assert.equal(parseAmountMinor(""), null);
  assert.match(read("app/carrier/claims/[id].tsx"), /parseAmountMinor\(amount\)/);
});

test("every t(\"key\") in app/ and src/ exists in en.ts and fr.ts", async () => {
  const { readdirSync, statSync } = await import("node:fs");
  const { join } = await import("node:path");
  const { fileURLToPath } = await import("node:url");
  const root = fileURLToPath(new URL("..", import.meta.url));
  const walk = (dir) =>
    readdirSync(join(root, dir)).flatMap((name) => {
      const rel = `${dir}/${name}`;
      return statSync(join(root, rel)).isDirectory() ? walk(rel) : rel.endsWith(".tsx") || rel.endsWith(".ts") ? [rel] : [];
    });
  const en = read("src/i18n/en.ts");
  const fr = read("src/i18n/fr.ts");
  const keys = (src) => new Set([...src.matchAll(/^ {2}(\w+):/gm)].map((m) => m[1]));
  const enKeys = keys(en);
  const frKeys = keys(fr);
  for (const f of [...walk("app"), ...walk("src")]) {
    const src = read(f);
    for (const m of src.matchAll(/\b(?:t|tr|translateNow)\("([A-Za-z0-9_]+)"/g)) {
      assert.ok(enKeys.has(m[1]), `${f}: unknown key ${m[1]}`);
      assert.ok(frKeys.has(m[1]), `${f}: key ${m[1]} missing in fr.ts`);
    }
  }
});

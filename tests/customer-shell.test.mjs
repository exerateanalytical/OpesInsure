import test from "node:test";
import assert from "node:assert/strict";
import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
// Pure TypeScript modules (no imports) loaded through Node's type stripping.
import {
  CLAIM_STATUSES,
  claimActionAllowed,
  claimTone,
  claimTracker,
  isActiveClaim,
  normalizeClaimStatus,
} from "../src/lib/claimStatus.ts";
import {
  daysUntil,
  formatCountdown,
  isLockout,
  isRenewalDue,
  lockoutSeconds,
  matchesQuery,
  resolveNotificationTarget,
} from "../src/lib/customerLogic.ts";
import { en } from "../src/i18n/en.ts";
import { fr } from "../src/i18n/fr.ts";

const root = fileURLToPath(new URL("..", import.meta.url));
const read = (p) => readFileSync(join(root, p), "utf8");

// --- Claims -----------------------------------------------------------------
test("claim statuses mirror the backend state machine", () => {
  const machine = join(root, "..", "app", "Domain", "Claims", "ClaimStateMachine.php");
  if (existsSync(machine)) {
    const php = readFileSync(machine, "utf8");
    const backend = new Set([...php.matchAll(/'([A-Z_]+)'/g)].map((m) => m[1]));
    for (const s of backend) assert.ok(CLAIM_STATUSES.includes(s), `app misses backend status ${s}`);
  }
  assert.ok(!CLAIM_STATUSES.includes("REJECTED"));
  assert.ok(!CLAIM_STATUSES.includes("SETTLED"));
  assert.equal(normalizeClaimStatus("REJECTED"), "DECLINED");
  assert.equal(normalizeClaimStatus("settled"), "PAID");
  assert.equal(normalizeClaimStatus("NONSENSE"), null);
  assert.equal(claimTone("DECLINED"), "danger");
  assert.equal(claimTone("PAID"), "success");
  assert.equal(claimTone("EVIDENCE_PENDING"), "warning");
});

test("claim actions are gated by status", () => {
  assert.ok(claimActionAllowed("appeal", "DECLINED"));
  assert.ok(claimActionAllowed("appeal", "PARTIALLY_APPROVED"));
  assert.ok(claimActionAllowed("appeal", "REJECTED"), "legacy name still maps");
  for (const s of ["SUBMITTED", "ASSESSMENT", "APPROVED", "PAID", "CLOSED", "DISPUTED"])
    assert.ok(!claimActionAllowed("appeal", s), `appeal must be hidden for ${s}`);
  assert.ok(claimActionAllowed("evidence", "EVIDENCE_PENDING"));
  for (const s of ["DECLINED", "PAID", "CLOSED", "APPROVED"])
    assert.ok(!claimActionAllowed("evidence", s), `evidence closed for ${s}`);
  assert.ok(claimActionAllowed("settlement", "APPROVED"));
  assert.ok(!claimActionAllowed("settlement", "SUBMITTED"));
  assert.ok(!claimActionAllowed("message", "CLOSED"));
  assert.ok(!claimActionAllowed("incident", undefined));
  assert.ok(isActiveClaim("CARRIER_REVIEW"));
  assert.ok(!isActiveClaim("PAID"));
  assert.ok(!isActiveClaim("CLOSED"));
});

test("claim tracker maps backend statuses onto the fixed seven steps", () => {
  assert.deepEqual(claimTracker("SUBMITTED"), ["current", "upcoming", "upcoming", "upcoming", "upcoming", "upcoming", "upcoming"]);
  assert.deepEqual(claimTracker("ACKNOWLEDGED").slice(0, 3), ["done", "done", "current"]);
  const info = claimTracker("EVIDENCE_PENDING");
  assert.equal(info[4], "attention");
  assert.equal(info[2], "current");
  assert.equal(claimTracker("CARRIER_REVIEW")[3], "current");
  assert.equal(claimTracker("DECLINED")[5], "current");
  assert.equal(claimTracker("PAYMENT_PENDING")[6], "current");
  assert.ok(claimTracker("PAID").every((s) => s === "done"));
  assert.equal(claimTracker("PAID").length, 7);
});

test("claim screens no longer test the wrong status names", () => {
  for (const f of ["app/claim/[id].tsx", "app/(customer)/(tabs)/claims.tsx", "app/claim/[id]/appeal.tsx"]) {
    const src = read(f);
    assert.doesNotMatch(src, /status\s*===\s*["']SETTLED["']/, f);
    assert.doesNotMatch(src, /claim\.status\s*===\s*["']REJECTED["']/, f);
    assert.match(src, /claimStatus/, f);
  }
  const detail = read("app/claim/[id].tsx");
  assert.match(detail, /claimActionAllowed\(a\.action, claim\.status\)/);
  assert.match(detail, /ClaimTracker/);
  assert.match(detail, /evidenceRequirements/);
  assert.match(detail, /pathname: "\/support\/new"/);
});

test("claim evidence uses the real upload contracts, including video", () => {
  const api = read("src/api/customer.ts");
  assert.match(api, /"\/mobile\/documents"/);
  assert.match(api, /file_base64/);
  assert.match(api, /"\/mobile\/uploads"/);
  assert.match(api, /chunks\/\$\{index\}/);
  assert.match(api, /upload_session_id/);
  assert.match(api, /video\/mp4/);
  const evidence = read("app/claim/[id]/evidence.tsx");
  assert.match(evidence, /"videos"/);
  assert.match(evidence, /uploadClaimEvidence/);
  const fresh = read("app/claim/new.tsx");
  assert.match(fresh, /DateTimeField/);
  assert.match(fresh, /toCameroonIso/);
  assert.doesNotMatch(fresh, /placeholder="2026-/);
});

// --- Deep links, timers, renewals ---------------------------------------------
test("notification targets are allow-listed in-app routes", () => {
  assert.equal(resolveNotificationTarget({ path: "/claim/abc" }), "/claim/abc");
  assert.equal(resolveNotificationTarget({ target: "opesinsure://policy/9" }), "/policy/9");
  assert.equal(
    resolveNotificationTarget({ url: "https://insurance.opesdatacenter.tech/app/claims" }),
    "/(customer)/(tabs)/claims",
  );
  assert.equal(resolveNotificationTarget({ path: "/payments/1?x=1" }), "/payments/1?x=1");
  for (const bad of [
    "https://evil.example/claim/1",
    "javascript:alert(1)",
    "//evil.example",
    "/claim/../agent",
    "/agent/wallet",
    "/carrier",
    "",
  ])
    assert.equal(resolveNotificationTarget({ path: bad }), null, bad);
  assert.equal(resolveNotificationTarget(null), null);
  assert.equal(resolveNotificationTarget({ path: 42 }), null);
});

test("countdowns, lockouts and renewal windows", () => {
  assert.equal(formatCountdown(65), "1:05");
  assert.equal(formatCountdown(-3), "0:00");
  assert.ok(isLockout({ status: 429 }));
  assert.ok(isLockout({ status: 423 }));
  assert.ok(isLockout({ status: 401, code: "ACCOUNT_LOCKED" }));
  assert.ok(!isLockout({ status: 422, code: "VALIDATION" }));
  assert.equal(lockoutSeconds({ retryAfter: 90 }), 90);
  assert.equal(lockoutSeconds({}), 60);
  const now = new Date("2026-09-24T10:00:00Z");
  assert.equal(daysUntil("2026-10-04T10:00:00Z", now), 10);
  assert.ok(isRenewalDue({ status: "ACTIVE", coverage_ends_at: "2026-10-20T00:00:00Z" }, now));
  assert.ok(!isRenewalDue({ status: "ACTIVE", coverage_ends_at: "2026-12-20T00:00:00Z" }, now));
  assert.ok(!isRenewalDue({ status: "EXPIRED", coverage_ends_at: "2026-10-01T00:00:00Z" }, now));
  assert.ok(!isRenewalDue({ status: "ACTIVE", coverage_ends_at: "2026-09-01T00:00:00Z" }, now));
  assert.ok(matchesQuery("moto", "Motor", "car"));
  assert.ok(!matchesQuery("zzz", "Motor"));
});

// --- i18n -----------------------------------------------------------------------
test("French catalogue is complete and keeps placeholders", () => {
  for (const [key, value] of Object.entries(en)) {
    assert.equal(typeof fr[key], "string", `fr missing ${key}`);
    assert.ok(fr[key].trim().length > 0, `fr empty ${key}`);
    const ph = (s) => [...s.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(",");
    assert.equal(ph(fr[key]), ph(value), `placeholders differ for ${key}`);
  }
  assert.deepEqual(Object.keys(fr).sort(), Object.keys(en).sort());
});

test("French uses CIMA insurance terminology", () => {
  const all = Object.values(fr).join("\n");
  for (const term of ["Contrat", "Sinistre", "prime", "franchise", "Attestation", "Courtier", "Assureur", "Agent général"])
    assert.match(all, new RegExp(term, "i"), term);
  assert.equal(fr.policies, "Contrats");
  assert.equal(fr.claims, "Sinistres");
  assert.equal(fr.claimStatus_DECLINED, "Refusé");
});

const walk = (dir) =>
  readdirSync(join(root, dir)).flatMap((name) => {
    const rel = `${dir}/${name}`;
    return statSync(join(root, rel)).isDirectory() ? walk(rel) : [rel];
  });

test("every t(\"key\") used in the customer shell exists in the catalogue", () => {
  const files = [
    "app/_layout.tsx",
    "app/index.tsx",
    "app/welcome.tsx",
    ...walk("app/(auth)"),
    ...walk("app/(customer)/(tabs)").filter((f) => !f.endsWith("compare.tsx") && !f.endsWith("policies.tsx")),
    ...walk("app/claim"),
    ...walk("app/notifications"),
    ...walk("app/support"),
    "app/account/profile.tsx",
    "app/account/security.tsx",
    "app/account/privacy.tsx",
    "app/onboarding/kyc.tsx",
    "src/components/AppRuntime.tsx",
    "src/components/auth/LockoutNotice.tsx",
    "src/components/claims/ClaimTracker.tsx",
  ].filter((f) => f.endsWith(".tsx"));
  for (const f of files) {
    const src = read(f);
    for (const m of src.matchAll(/\bt\("([A-Za-z0-9_]+)"/g))
      assert.ok(m[1] in en, `${f}: unknown key ${m[1]}`);
  }
});

test("rewritten customer screens carry no hard-coded English headings", () => {
  for (const f of [
    "app/(customer)/(tabs)/index.tsx",
    "app/(customer)/(tabs)/explore.tsx",
    "app/(customer)/(tabs)/claims.tsx",
    "app/claim/[id].tsx",
    "app/notifications/index.tsx",
    "app/support/new.tsx",
    "app/account/security.tsx",
  ]) {
    const src = read(f);
    assert.doesNotMatch(src, /(title|label|placeholder)="[A-Z][a-z]+ [a-z]/, f);
  }
});

// --- Shell, session, first run ---------------------------------------------------
test("customer tabs are Home | Explore | Policies | Claims | Profile", () => {
  const tabs = read("app/(customer)/(tabs)/_layout.tsx");
  const order = [...tabs.matchAll(/name="([a-z]+)"/g)].map((m) => m[1]);
  assert.deepEqual(order, ["index", "explore", "policies", "claims", "profile", "compare"]);
  assert.match(tabs, /name="compare" options=\{\{ href: null \}\}/);
  assert.ok(existsSync(join(root, "app/(customer)/(tabs)/explore.tsx")));
  assert.ok(existsSync(join(root, "app/(customer)/(tabs)/profile.tsx")));
  assert.ok(!existsSync(join(root, "app/(customer)/(tabs)/account.tsx")));
  assert.match(read("app/(customer)/(tabs)/explore.tsx"), /CustomerApi\.institutions/);
  assert.match(read("src/api/customer.ts"), /\/public\/institutions/);
});

test("home leads with Compare Insurance and covers every checklist card", () => {
  const home = read("app/(customer)/(tabs)/index.tsx");
  assert.match(home, /t\("compareInsurance"\)/);
  assert.match(home, /SearchBar/);
  for (const key of ["homeActivePolicies", "homeRenewals", "homeQuotesInProgress", "homeActiveClaims", "notifications", "homeHelp"])
    assert.match(home, new RegExp(`t\\("${key}"\\)`), key);
  assert.match(home, /isRenewalDue/);
  assert.match(home, /takePendingOnboarding/);
  const cats = read("src/components/customer/categories.ts");
  for (const id of ["motor", "health", "travel", "home", "business", "life", "accident", "more"])
    assert.match(cats, new RegExp(`id: "${id}"`));
});

test("session survives network errors and only drops on rejected credentials", () => {
  const session = read("src/store/session.ts");
  assert.match(session, /isCredentialFailure\(error\)/);
  assert.match(session, /error\.status === 401/);
  assert.match(session, /cachedBootstrap\(\)/);
  assert.match(session, /offline: true/);
  // The old catch-all cleared tokens on any failure.
  assert.doesNotMatch(session, /\} catch \(error\) \{\s*await TokenVault\.clear\(\);/);
  assert.match(session, /\/auth\/mobile\/logout-all|logoutAll/);
  assert.match(read("src/api/customer.ts"), /"\/auth\/mobile\/logout-all"/);
  assert.match(read("app/account/security.tsx"), /signOutEverywhere/);
});

test("first run is remembered and Get Started opens sign-up", () => {
  const welcome = read("app/welcome.tsx");
  assert.match(welcome, /markOnboardingSeen/);
  assert.match(welcome, /label=\{t\("getStarted"\)\}[\s\S]{0,80}leave\("\/\(auth\)\/sign-up"\)/);
  const splash = read("app/index.tsx");
  assert.match(splash, /onboardingSeen\(\)/);
  assert.match(splash, /seen \? "\/\(auth\)\/sign-in" : "\/welcome"/);
  assert.match(read("app/(auth)/sign-up.tsx"), /setPendingOnboarding\(true\)/);
  assert.match(read("app/onboarding/kyc.tsx"), /kycSkip/);
});

test("splash is white with the heritage art and a native white splash", () => {
  const splash = read("app/index.tsx");
  assert.doesNotMatch(splash, /LinearGradient|navy950, colors\.navy900/);
  assert.match(splash, /backgroundColor: colors\.white/);
  assert.match(splash, /splash_map\.png/);
  assert.match(splash, /splash_network_arcs\.png/);
  const app = JSON.parse(read("app.json"));
  assert.equal(app.expo.splash.backgroundColor, "#FFFFFF");
  const plugin = app.expo.plugins.find((p) => Array.isArray(p) && p[0] === "expo-splash-screen");
  assert.equal(plugin[1].backgroundColor, "#FFFFFF");
  assert.match(read("src/components/BrandMark.tsx"), /assets\/icon\.png/);
});

test("push token registration and biometric lock at cold start", () => {
  const push = read("src/notifications/push.ts");
  assert.match(push, /getExpoPushTokenAsync\(\{ projectId \}\)/);
  assert.match(push, /eas\?\.projectId/);
  assert.match(read("src/api/customer.ts"), /provider: "expo"/);
  const runtime = read("src/components/AppRuntime.tsx");
  assert.match(runtime, /registerForPush\(\)/);
  assert.match(runtime, /getLastNotificationResponseAsync/);
  assert.match(runtime, /previous === "booting"/);
  assert.match(runtime, /if \(restored\) await unlock\(\)/);
});

test("support: FAQ, context-linked tickets and escalation", () => {
  assert.ok(existsSync(join(root, "app/support/faq.tsx")));
  const fresh = read("app/support/new.tsx");
  assert.match(fresh, /claimId/);
  assert.match(fresh, /paymentId/);
  assert.match(fresh, /PRIVACY_REQUEST/);
  const detail = read("app/support/[id].tsx");
  assert.match(detail, /priority: "HIGH"/);
  assert.match(detail, /form\.append\("file"/);
  const layout = read("app/_layout.tsx");
  for (const route of ["support/faq", "account/privacy", "proposals/index", "proposals/\\[id\\]", "quote/compare"])
    assert.match(layout, new RegExp(`name="${route}"`), route);
});

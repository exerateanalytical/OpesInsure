import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { scrubBreadcrumb, scrubEvent, scrubString } from "../src/security/crashScrubber.ts";
import { openQueue, queueHealth, sealQueue } from "../src/offline/queueCipher.ts";
import { forgetProposalSlots, rememberAttemptKey } from "../src/lib/purchase.ts";
import { splitChunks } from "../src/lib/chunking.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

// --- OPS-05 crash scrubber -------------------------------------------------

test("scrubber masks phones, emails, ids and contract numbers", () => {
  const s = scrubString(
    "Payer +237 6 77 12 34 56 / 677123456 / 00237699887766 jean@example.cm CNI 123456789012 POL-2026-000002 CLM-DEMO-0001 PRP/2026/0042 Bearer abc.def",
  );
  for (const leak of ["677", "699887766", "jean@", "123456789012", "POL-2026", "CLM-DEMO", "PRP/2026", "abc.def"])
    assert.ok(!s.includes(leak), `${leak} leaked: ${s}`);
});

test("beforeSend drops user, request body, headers, cookies, KYC and frame vars", () => {
  const out = scrubEvent({
    user: { id: "u-1", username: "Jean" },
    request: { method: "POST", url: "https://x/api/v1/claims/123?phone=677123456", data: { national_id: "1" }, headers: { Authorization: "Bearer t" }, cookies: "s=1" },
    message: "failed for 677123456",
    exception: { values: [{ type: "TypeError", value: "policy POL-2026-000002 bad", stacktrace: { frames: [{ filename: "a.js", vars: { phone: "677123456" } }] } }] },
    extra: { full_name: "Jean", kyc_status: "X", screen: "/claims/:id" },
    contexts: { form: { phone_e164: "+237677123456", note: "call 677123456" } },
    breadcrumbs: [{ category: "fetch", data: { url: "https://x/a?token=1", method: "POST", status_code: 500, request_body: "{}" } }],
    tags: { role: "AGENT" },
  });
  const json = JSON.stringify(out);
  assert.equal(out.user, undefined);
  assert.deepEqual(Object.keys(out.request).sort(), ["method", "url"]);
  for (const leak of ["677123456", "Jean", "Bearer t", "POL-2026", "national_id\":\"1", "token=1", "request_body"])
    assert.ok(!json.includes(leak), `${leak} leaked`);
  assert.equal(out.exception.values[0].stacktrace.frames[0].vars, undefined);
  assert.equal(out.extra.screen, "/claims/:id");
  assert.equal(out.tags.role, "AGENT");
});

test("breadcrumbs keep only method/status/masked url", () => {
  const b = scrubBreadcrumb({ category: "xhr", data: { url: "https://x/p/42?q=1", method: "GET", status_code: 200, body: "x" } });
  assert.deepEqual(b.data, { method: "GET", status_code: 200, url: "https://x/p/:id" });
  assert.equal(scrubBreadcrumb(null), null);
});

test("Sentry is DSN-gated, privacy-configured and guarded", () => {
  const src = read("src/security/crashReporting.ts");
  assert.match(src, /if \(!dsn/);
  assert.match(src, /sendDefaultPii: false/);
  assert.match(src, /require\("@sentry\/react-native"\)/);
  assert.match(src, /beforeSend: .*scrubEvent/);
  assert.doesNotMatch(src, /setUser|user_id/);
  assert.doesNotMatch(read("app.json") + read("eas.json"), /sentry\.io\/\d|ingest\.sentry/);
});

// --- OPS-07 local data -----------------------------------------------------

test("payment attempt keys are forgotten per proposal and both keys cleared on logout", () => {
  let map = rememberAttemptKey({}, "p1:1:mtn_momo:237677", "u1");
  map = rememberAttemptKey(map, "p2:1:mtn_momo:237677", "u2");
  assert.deepEqual(forgetProposalSlots(map, "p1"), { "p2:1:mtn_momo:237677": "u2" });
  const session = read("src/store/session.ts");
  assert.equal(session.match(/RecentProposals\.clear\(\)/g)?.length, 2);
  assert.equal(session.match(/PaymentAttemptKeys\.clear\(\)/g)?.length, 2);
  const store = read("src/store/insurance.ts");
  assert.match(store, /POLICY_ISSUED"\)\s*\n\s*await PaymentAttemptKeys\.forgetProposal/);
  // Kept while issuance is pending: a retry then replays the same payment instead of charging again.
  assert.doesNotMatch(store, /ISSUANCE_PENDING"[^\n]*forgetProposal|ISSUANCE_PENDING" \|\| purchase\?\.status === "POLICY_ISSUED"/);
});

// --- OPS-08 offline queue --------------------------------------------------

const op = (i) => ({
  id: `3f1c2a4e-9b7d-4c1e-8a2b-${String(i).padStart(12, "0")}`,
  kind: i % 2 ? "DRAFT" : "MUTATION",
  resource: i % 2 ? "Claim incident draft" : "Consented client registration",
  resource_id: "9a8b7c6d-5e4f-4a3b-2c1d-0e9f8a7b6c5d",
  method: i % 2 ? "PUT" : "POST",
  path: i % 2 ? "/mobile/claims/9a8b7c6d-5e4f-4a3b-2c1d-0e9f8a7b6c5d/incident" : "/mobile/partner/agent/clients",
  payload: i % 2
    ? { claim_id: "9a8b7c6d-5e4f-4a3b-2c1d-0e9f8a7b6c5d", incident_type: "COLLISION", police_report_number: "PR-2026-00412", latitude: 4.0511, longitude: 9.7679, injuries_reported: false, vehicle_drivable: true, towing_required: false, declaration_confirmed: true }
    : { full_name: "Ngono Mballa Marie-Claire", phone_e164: "+237677123456", city: "Douala", consent_confirmed: true },
  state: "PENDING",
  attempts: 0,
  created_at: "2026-09-27T08:15:00.000Z",
  updated_at: "2026-09-27T08:15:00.000Z",
});

test("50 queued actions exceed SecureStore's per-item guidance many times over", () => {
  const bytes = Buffer.byteLength(JSON.stringify(Array.from({ length: 50 }, (_, i) => op(i))));
  const chunks = splitChunks(JSON.stringify(Array.from({ length: 50 }, (_, i) => op(i)))).length;
  console.log(`[OPS-08] 50 actions = ${bytes} bytes = ${chunks} SecureStore chunks rewritten per enqueue`);
  assert.ok(bytes > 2048 * 5);
});

test("queue envelope round-trips, rejects tampering and the wrong key", () => {
  const key = "ab".repeat(64);
  const iv = "cd".repeat(16);
  const queue = JSON.stringify(Array.from({ length: 300 }, (_, i) => op(i)));
  const sealed = sealQueue(queue, key, iv);
  assert.ok(!sealed.includes("+237677123456"));
  assert.equal(openQueue(sealed, key), queue);
  const parts = sealed.split(":");
  parts[2] = parts[2].replace(/^./, (c) => (c === "A" ? "B" : "A"));
  assert.equal(openQueue(parts.join(":"), key), null);
  assert.equal(openQueue(sealed, "ef".repeat(64)), null);
  assert.equal(openQueue("garbage", key), null);
});

test("a field day fits under the cap; nudge fires before it", async () => {
  const src = read("src/offline/vault.ts");
  const max = Number(src.match(/MAX_QUEUE_OPERATIONS = (\d+)/)[1]);
  const maxBytes = Number(src.match(/maxSecurePayloadBytes = ([\d_]+)/)[1].replaceAll("_", ""));
  assert.ok(max >= 200);
  assert.ok(Buffer.byteLength(JSON.stringify(Array.from({ length: max }, (_, i) => op(i)))) < maxBytes);
  assert.equal(queueHealth(0, max).level, "empty");
  assert.equal(queueHealth(10, max).level, "pending");
  assert.equal(queueHealth(Math.ceil(max * 0.8), max).level, "near_cap");
  assert.equal(queueHealth(max, max).level, "full");
  // Migration from v2/v1 keystore queue and full cleanup on logout.
  assert.match(src, /readLegacyQueues/);
  assert.match(src, /SecureJson\.remove\(queueKey\)/);
  assert.match(src, /deleteItemAsync\(cipherKeyKey\)/);
  assert.match(src, /FileSystem\.deleteAsync\(path/);
  assert.match(read("app/agent/index.tsx"), /<OfflineQueueNudge \/>/);
});

// --- OPS-09 ABI split ------------------------------------------------------

test("store profile is an AAB and the arm64 APK profile narrows ABIs only", () => {
  const eas = JSON.parse(read("eas.json"));
  assert.equal(eas.build.production.android.buildType, "app-bundle");
  const arm = eas.build["production-apk-arm64"];
  assert.equal(arm.extends, "production-apk");
  assert.equal(arm.channel, eas.build["production-apk"].channel);
  const archs = (env) =>
    JSON.parse(
      execFileSync(process.execPath, ["-e", "const c=require('./app.config.js')();process.stdout.write(JSON.stringify(c.plugins.find(p=>p[0]==='expo-build-properties')[1].android.buildArchs))"], {
        cwd: new URL("..", import.meta.url),
        env: { ...process.env, EXPO_ANDROID_BUILD_ARCHS: env },
      }).toString(),
    );
  assert.deepEqual(archs(arm.env.EXPO_ANDROID_BUILD_ARCHS), ["arm64-v8a"]);
  assert.deepEqual(archs(""), ["arm64-v8a", "armeabi-v7a"]);
});

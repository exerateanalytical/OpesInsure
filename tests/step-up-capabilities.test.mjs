// Mobile audit A1/B: step-up purpose selection + retry, capabilities and
// allowed_actions gating, carrier specialists, device headers, telemetry.
import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  accountProfileStepUpPurpose,
  agentProfileStepUpPurpose,
  isStepUpRequired,
  runWithStepUp,
  STEP_UP_CANCELLED,
} from "../src/lib/stepUpFlow.ts";
import {
  allowedAction,
  capabilityAllows,
  gateWithCapability,
  hrefVisible,
  moduleForHref,
  parseCapabilities,
} from "../src/lib/capabilities.ts";
import { canUseCarrierModule, carrierShellAllowed, specialistCarrierModules } from "../src/lib/carrierAccess.ts";
import { isSecurityNotification, resolveNotificationTarget } from "../src/lib/customerLogic.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("purpose selection: only a changed payout number / e-mail needs a grant", () => {
  assert.equal(agentProfileStepUpPurpose({ momo_phone_e164: "+237650000000" }, { momo_phone_e164: "+237 650 000 000" }), null);
  assert.equal(agentProfileStepUpPurpose({ momo_phone_e164: "+237650000000" }, { momo_phone_e164: "+237670000000" }), "PAYOUT_DESTINATION_CHANGE");
  assert.equal(agentProfileStepUpPurpose(null, { momo_phone_e164: "+237670000000" }), "PAYOUT_DESTINATION_CHANGE");
  assert.equal(accountProfileStepUpPurpose("A@x.cm", "a@x.cm"), null);
  assert.equal(accountProfileStepUpPurpose(null, null), null);
  assert.equal(accountProfileStepUpPurpose("a@x.cm", "b@x.cm"), "PROFILE_SECURITY_CHANGE");
  assert.equal(accountProfileStepUpPurpose("a@x.cm", null), "PROFILE_SECURITY_CHANGE");
});

test("isStepUpRequired recognises the server answer (401 code, 403 code, 428)", () => {
  assert.equal(isStepUpRequired({ status: 401, code: "STEP_UP_REQUIRED" }), true);
  assert.equal(isStepUpRequired({ status: 403, code: "STEP_UP_REQUIRED" }), true);
  assert.equal(isStepUpRequired({ status: 428, code: "PRECONDITION" }), true);
  assert.equal(isStepUpRequired({ status: 401, code: "SESSION_EXPIRED" }), false);
  assert.equal(isStepUpRequired(null), false);
});

const deps = (over = {}) => {
  const log = [];
  return {
    log,
    hasGrant: async (p) => (log.push(`has:${p}`), over.has ?? false),
    obtain: async (p) => (log.push(`obtain:${p}`), (over.outcomes ?? ["granted"]).shift() ?? "granted"),
    clear: async () => void log.push("clear"),
  };
};

test("runWithStepUp: no purpose runs straight through", async () => {
  const d = deps();
  assert.equal(await runWithStepUp(null, async () => "ok", d), "ok");
  assert.deepEqual(d.log, []);
});

test("runWithStepUp: obtains a grant first, cancelled stops the call", async () => {
  const d = deps({ outcomes: ["cancelled"] });
  let calls = 0;
  const out = await runWithStepUp("SIGN_OUT_EVERYWHERE", async () => ++calls, d);
  assert.equal(out, STEP_UP_CANCELLED);
  assert.equal(calls, 0);
});

test("runWithStepUp: existing grant is reused without a challenge", async () => {
  const d = deps({ has: true });
  assert.equal(await runWithStepUp("SIGN_OUT_EVERYWHERE", async (g) => g, d), true);
  assert.deepEqual(d.log, ["has:SIGN_OUT_EVERYWHERE"]);
});

test("runWithStepUp: purpose unknown to an older server -> call goes ahead without grant", async () => {
  const d = deps({ outcomes: ["unsupported"] });
  assert.equal(await runWithStepUp("PROFILE_SECURITY_CHANGE", async (g) => g, d), false);
});

test("runWithStepUp: server asks (STEP_UP_REQUIRED) -> clear, step up, retry once", async () => {
  const d = deps({ has: true });
  let calls = 0;
  const out = await runWithStepUp(
    "SIGN_OUT_EVERYWHERE",
    async () => {
      calls += 1;
      if (calls === 1) throw Object.assign(new Error("verify"), { status: 401, code: "STEP_UP_REQUIRED" });
      return "done";
    },
    d,
  );
  assert.equal(out, "done");
  assert.equal(calls, 2);
  assert.deepEqual(d.log, ["has:SIGN_OUT_EVERYWHERE", "clear", "obtain:SIGN_OUT_EVERYWHERE"]);
});

test("runWithStepUp: retries only once and never swallows other errors", async () => {
  const d = deps({ has: true });
  let calls = 0;
  await assert.rejects(
    runWithStepUp("SIGN_OUT_EVERYWHERE", async () => {
      calls += 1;
      throw Object.assign(new Error("verify"), { status: 401, code: "STEP_UP_REQUIRED" });
    }, d),
  );
  assert.equal(calls, 2);
  const other = deps({ has: true });
  await assert.rejects(runWithStepUp("X", async () => { throw Object.assign(new Error("boom"), { status: 500, code: "E" }); }, other));
  assert.ok(!other.log.includes("clear"));
});

test("client: a 401 STEP_UP_REQUIRED never signs the user out; grants attach per purpose", () => {
  const client = read("src/api/client.ts");
  assert.match(client, /peek\?\.code === "STEP_UP_REQUIRED"/);
  assert.ok(client.indexOf('peek?.code === "STEP_UP_REQUIRED"') < client.indexOf("await rotate()"));
  assert.match(client, /stepUpIfGranted: "PROFILE_SECURITY_CHANGE"/);
  assert.match(client, /stepUpIfGranted: "PAYOUT_DESTINATION_CHANGE"/);
  assert.match(read("src/api/customer.ts"), /stepUpIfGranted: "SIGN_OUT_EVERYWHERE"/);
  assert.match(read("app/account/security.tsx"), /withStepUp\(STEP_UP_PURPOSES\.signOutEverywhere/);
  assert.match(read("app/account/profile.tsx"), /accountProfileStepUpPurpose/);
  assert.match(read("app/agent/onboarding.tsx"), /agentProfileStepUpPurpose/);
});

test("capabilities: parsed defensively; absent -> fallback, present -> narrows only", () => {
  assert.equal(parseCapabilities(null), null);
  assert.equal(parseCapabilities({}), null);
  const caps = parseCapabilities({ data_scope: "OWN", modules: { claims: { view: true, actions: ["create", 3] }, staff: { view: false, actions: ["x"] } } });
  assert.deepEqual(caps.modules.claims.actions, ["create"]);
  assert.equal(capabilityAllows(caps, "claims"), true);
  assert.equal(capabilityAllows(caps, "claims", "create"), true);
  assert.equal(capabilityAllows(caps, "claims", "withdraw"), false);
  assert.equal(capabilityAllows(caps, "staff", "x"), false);
  assert.equal(capabilityAllows(caps, "leads"), null);
  assert.equal(capabilityAllows(null, "claims"), null);
  assert.equal(gateWithCapability(true, null, "claims", "withdraw"), true);
  assert.equal(gateWithCapability(true, caps, "claims", "withdraw"), false);
  assert.equal(gateWithCapability(false, caps, "claims", "create"), false);
  assert.equal(moduleForHref("/agent/wallet"), "commissions");
  assert.equal(moduleForHref("/search?role=agent"), undefined);
  assert.equal(hrefVisible(caps, "/broker/staff"), false);
  assert.equal(hrefVisible(caps, "/broker/leads"), true);
});

test("allowed_actions: listed only when present, current rule when absent", () => {
  assert.equal(allowedAction({}, "renew", true), true);
  assert.equal(allowedAction(null, "renew", false), false);
  assert.equal(allowedAction({ allowed_actions: [] }, "renew", true), false);
  assert.equal(allowedAction({ allowed_actions: ["renew"] }, "renew", true), true);
  assert.equal(allowedAction({ allowed_actions: ["renew"] }, "renew", false), false);
});

test("carrier specialists (FINANCE_OFFICER / CLAIMS_OFFICER) reach their carrier modules", () => {
  const finance = ["carrier.dashboard.read", "carrier.finance.read"];
  const claims = ["carrier.dashboard.read", "carrier.claims.read"];
  assert.deepEqual(specialistCarrierModules("finance", finance).sort(), ["bordereaux", "payments", "policies", "settlements"]);
  assert.deepEqual(specialistCarrierModules("claims", claims).sort(), ["claims", "policies"]);
  assert.equal(carrierShellAllowed("finance", finance), true);
  assert.equal(carrierShellAllowed("claims", claims), true);
  // Capabilities narrow, never widen.
  assert.equal(canUseCarrierModule(claims, "claims", { modules: { claims: { view: false } } }), false);
  assert.equal(canUseCarrierModule([], "claims", { modules: { claims: { view: true } } }), false);
  assert.match(read("src/lib/portalRouting.ts"), /case "FINANCE_OFFICER":\s*\n?\s*return "finance";/);
});

test("security notifications open the security centre", () => {
  assert.equal(isSecurityNotification({ type: "SECURITY" }), true);
  assert.equal(resolveNotificationTarget({ type: "SECURITY" }), "/account/security");
  assert.equal(resolveNotificationTarget({ type: "SECURITY", path: "https://evil.example" }), "/account/security");
  assert.equal(resolveNotificationTarget({ type: "CLAIM", path: "/claim/1" }), "/claim/1");
});

test("device headers, push telemetry fallback, attestation CONFIG_REQUIRED, location", () => {
  const client = read("src/api/client.ts");
  assert.match(client, /"X-Device-Model"/);
  assert.match(client, /"X-OS-Version"/);
  assert.match(client, /"X-App-Version"/);
  assert.match(client, /\.\.\.deviceHeaders\(\)/);
  assert.match(read("src/security/telemetry.ts"), /"PUSH_REGISTRATION_FAILED"/);
  assert.match(read("src/notifications/push.ts"), /captureWithFallback\("PUSH_REGISTRATION_FAILED", "API_ERROR"/);
  assert.match(read("app/security/device-status.tsx"), /CONFIG_REQUIRED/);
  assert.match(read("src/store/insurance.ts"), /latitude: coords\.latitude/);
  assert.match(read("app/account/profile.tsx"), /claimCoordinates\(fixRef\.current\)/);
});

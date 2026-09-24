import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const eas = JSON.parse(readFileSync(new URL("../eas.json", import.meta.url), "utf8"));
const envSrc = readFileSync(new URL("../src/config/environment.ts", import.meta.url), "utf8");

test("production APK passes the runtime production-configuration gate", () => {
  const p = eas.build["production-apk"];
  const env = { ...eas.build[p.extends]?.env, ...p.env };
  assert.equal(env.EXPO_PUBLIC_APP_ENV, "production");
  assert.equal(env.EXPO_PUBLIC_RELEASE_CHANNEL, "production");
  assert.match(env.EXPO_PUBLIC_API_BASE_URL, /^https:\/\//);
  // Showing the server-gated demo login must not count as demo mode.
  assert.match(envSrc, /const demoMode = environment === "demo";/);
  assert.doesNotMatch(envSrc, /demoMode = process\.env\.EXPO_PUBLIC_SHOW_DEMO_LOGIN/);
});

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
  // Demo mode is removed: only staging/production are valid app environments.
  assert.doesNotMatch(envSrc, /demoMode|showDemoLogin|SHOW_DEMO_LOGIN/);
  assert.match(envSrc, /\(\["staging", "production"\] as string\[\]\)\.includes\(environment\)/);
  assert.match(envSrc, /INVALID_APP_ENV/);
});

test("no EAS profile carries demo mode", () => {
  for (const [name, p] of Object.entries(eas.build)) {
    assert.doesNotMatch(name, /demo/i, name);
    assert.doesNotMatch(p.channel ?? "", /demo/i, name);
    assert.ok(!("EXPO_PUBLIC_SHOW_DEMO_LOGIN" in (p.env ?? {})), `${name}: SHOW_DEMO_LOGIN`);
    if (p.env?.EXPO_PUBLIC_APP_ENV) assert.ok(["staging", "production"].includes(p.env.EXPO_PUBLIC_APP_ENV), name);
  }
  for (const name of ["development", "preview", "production", "production-apk"]) assert.ok(eas.build[name], name);
});

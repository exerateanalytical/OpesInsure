import test from "node:test";
import assert from "node:assert/strict";
import { createRequire } from "node:module";
import { readFileSync } from "node:fs";

const require = createRequire(import.meta.url);
const root = new URL("../", import.meta.url);
const eas = JSON.parse(readFileSync(new URL("eas.json", root), "utf8"));
const configPath = require.resolve("../app.config.js");

const profileEnv = (name) => {
  const profile = eas.build[name];
  return { ...(profile.extends ? profileEnv(profile.extends) : {}), ...(profile.env ?? {}) };
};

function evaluate(env) {
  const saved = { ...process.env };
  try {
    for (const key of Object.keys(process.env)) if (key.startsWith("EXPO_PUBLIC_") || key === "GOOGLE_SERVICES_JSON") delete process.env[key];
    Object.assign(process.env, env);
    delete require.cache[configPath];
    delete require.cache[require.resolve("../app.json")];
    return require("../app.config.js")();
  } finally {
    for (const key of Object.keys(process.env)) if (!(key in saved)) delete process.env[key];
    Object.assign(process.env, saved);
  }
}

test("app.json carries no unresolved ${...} placeholders", () => {
  assert.doesNotMatch(readFileSync(new URL("app.json", root), "utf8"), /\$\{/);
});

for (const name of Object.keys(eas.build)) {
  test(`app config for eas profile "${name}" has no \${...} placeholders`, () => {
    const config = evaluate(profileEnv(name));
    assert.doesNotMatch(JSON.stringify(config), /\$\{/);
    assert.equal(config.extra.apiBaseUrl, undefined, "extra.apiBaseUrl is unused; the API URL comes from EXPO_PUBLIC_API_BASE_URL");
    assert.ok(config.extra.eas.projectId);
  });
}

test("googleServicesFile comes from the GOOGLE_SERVICES_JSON EAS file env var and is optional", () => {
  const withFile = evaluate({ ...profileEnv("production"), GOOGLE_SERVICES_JSON: "/tmp/eas/google-services.json" });
  assert.equal(withFile.android.googleServicesFile, "/tmp/eas/google-services.json");
  const without = evaluate(profileEnv("production"));
  // Absent unless a local ./google-services.json exists (never committed).
  if (without.android.googleServicesFile) assert.equal(without.android.googleServicesFile, "./google-services.json");
});

test("push registration passes projectId, creates channels first and reports failures", () => {
  const push = readFileSync(new URL("src/notifications/push.ts", root), "utf8");
  assert.match(push, /getExpoPushTokenAsync\(\{ projectId \}\)/);
  assert.ok(push.indexOf("await ensureChannels()") < push.indexOf("requestPermissionsAsync"));
  assert.match(push, /addPushTokenListener/);
  assert.match(push, /PUSH_REGISTRATION_FAILED/);
  assert.doesNotMatch(readFileSync(new URL("app/account/notifications.tsx", root), "utf8"), /getDevicePushTokenAsync/);
});

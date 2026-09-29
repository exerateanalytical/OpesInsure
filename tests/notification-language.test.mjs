// Owner report: "Notifications are not translated in the app." The server
// renders each notification from a stable code in the language the app asks
// for (Accept-Language) and push/SMS in users.locale; the app must send its
// language, refetch when it changes, and keep users.locale in step.
import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("every API request carries the app language", () => {
  const client = read("src/api/client.ts");
  assert.match(client, /export const setApiLanguage = /);
  assert.match(client, /"Accept-Language": currentApiLanguage\(\)/);
  assert.match(read("src/i18n/index.ts"), /setApiLanguage\(\(\) => useSession\.getState\(\)\.language\)/);
  // The notification shape carries the stable code (older servers omit it).
  assert.match(client, /code\?: string \| null;/);
});

test("notification lists refetch when the language changes", () => {
  for (const [file, call] of [
    ["app/notifications/index.tsx", "CustomerApi.notifications(), [language]"],
    ["app/(customer)/(tabs)/index.tsx", "CustomerApi.notifications(), [language]"],
    ["app/notifications/[id].tsx", "NotificationsApi.markRead(id), [id, language]"],
    ["app/agent/notifications.tsx", "NotificationsApi.list(), [language]"],
    ["src/components/portal/PortalShell.tsx", "NotificationsApi.list(), [language]"],
  ]) {
    assert.ok(read(file).includes(call), `${file} refetches on language change`);
  }
});

test("push language follows the app language, not only an explicit choice", () => {
  const runtime = read("src/components/AppRuntime.tsx");
  assert.match(runtime, /AccountApi\.setLocale\(language\)/);
  assert.match(runtime, /s\.bootstrap\?\.user\?\.locale/);
});

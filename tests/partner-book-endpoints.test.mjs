import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("partner book endpoints are wired", () => {
  const api = read("src/api/partner.ts");
  for (const path of [
    "/mobile/partner/agent/proposals",
    "/mobile/partner/agent/claims",
    "/mobile/partner/broker/proposals",
    "/mobile/partner/agent/clients/${encodeURIComponent(customerId)}/documents",
    "/mobile/partner/broker/clients/${encodeURIComponent(customerId)}/documents",
  ]) assert.ok(api.includes(path), path);
  const layout = read("app/_layout.tsx");
  for (const r of ["agent/proposals", "agent/claims", "broker/proposals"]) assert.match(layout, new RegExp(`name="${r}"`));
});

test("proposals use the server policy_id, not wallet cross-matching", () => {
  for (const f of ["app/proposals/index.tsx", "app/proposals/[id].tsx"]) {
    const s = read(f);
    assert.doesNotMatch(s, /WalletApi/, f);
    assert.match(s, /policy_id/, f);
  }
});

test("carrier_logo_url is typed and preferred, initials fallback kept", () => {
  const c = read("src/api/client.ts");
  assert.ok((c.match(/carrier_logo_url\?: string \| null/g) ?? []).length >= 4);
  assert.match(read("src/lib/renewal.ts"), /carrier_logo_url/);
  assert.match(read("src/components/InstitutionMark.tsx"), /initials/);
});

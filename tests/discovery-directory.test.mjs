import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, existsSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import {
  createTtlCache,
  directoryOfType,
  isDemoInstitution,
  withoutDemo,
  DIRECTORY_TTL_MS,
} from "../src/lib/institutions.ts";
import { productFamilyLabel, productFamilyLabels } from "../src/lib/productFamilies.ts";

const root = fileURLToPath(new URL("..", import.meta.url));
const read = (p) => readFileSync(join(root, p), "utf8");

test("demo and synthetic institutions never reach the directory lists", () => {
  const rows = [
    { id: "1", type: "insurer", name: "ACTIVA", is_demo: false, data_origin: "REGULATORY" },
    { id: "2", type: "broker", name: "Demo Broker Staff", is_demo: true, data_origin: "DEMO_SYNTHETIC" },
    { id: "3", type: "broker", name: "OpesInsure Demo", is_demo: false, data_origin: "DEMO_SYNTHETIC" },
    { id: "4", type: "broker", name: "ASSUR EXPERT", is_demo: false },
  ];
  assert.equal(isDemoInstitution(rows[1]), true);
  assert.equal(isDemoInstitution(rows[2]), true);
  assert.equal(isDemoInstitution(rows[3]), false);
  assert.deepEqual(withoutDemo(rows).map((r) => r.id), ["1", "4"]);
  assert.deepEqual(directoryOfType(rows, "broker").map((r) => r.id), ["4"]);
  assert.deepEqual(directoryOfType(rows, "insurer").map((r) => r.id), ["1"]);
  assert.deepEqual(directoryOfType(rows, "all").map((r) => r.id), ["1", "4"]);
});

test("directory cache shares one request, expires after the TTL and forgets failures", async () => {
  let now = 0;
  let calls = 0;
  const cache = createTtlCache(DIRECTORY_TTL_MS, () => now);
  const load = () => {
    calls++;
    return Promise.resolve([calls]);
  };
  const [a, b] = await Promise.all([cache.get("all", load), cache.get("all", load)]);
  assert.equal(calls, 1);
  assert.deepEqual(a, b);
  now = DIRECTORY_TTL_MS - 1;
  await cache.get("all", load);
  assert.equal(calls, 1);
  now = DIRECTORY_TTL_MS;
  await cache.get("all", load);
  assert.equal(calls, 2);

  let fail = true;
  const flaky = () => (fail ? Promise.reject(new Error("offline")) : Promise.resolve(["ok"]));
  await assert.rejects(cache.get("x", flaky));
  await new Promise((r) => setTimeout(r, 0));
  fail = false;
  assert.deepEqual(await cache.get("x", flaky), ["ok"]);
});

test("one call path: both directory APIs are thin wrappers over the shared store", () => {
  const customer = read("src/api/customer.ts");
  const extra = read("src/api/extra.ts");
  assert.match(customer, /institutions: \(type\?: "insurer" \| "broker"\): Promise<Institution\[\]> => loadDirectory\(type \?\? "all"\)/);
  assert.match(extra, /list: \(type: "insurer" \| "broker"\): Promise<Institution\[\]> => loadDirectory\(type\)/);
  assert.match(extra, /show: \(id: string\): Promise<Institution> => loadInstitution\(id\)/);
  const store = read("src/api/directory.ts");
  // No other API module calls the directory endpoints itself.
  for (const f of ["src/api/customer.ts", "src/api/extra.ts", "src/api/client.ts"]) assert.doesNotMatch(read(f), /api<[^>]*>\(`?"?\/public\/institutions/);
  assert.match(store, /isDemoInstitution\(row\)/);
});

test("insurer product families are localized, brand names kept", () => {
  assert.equal(productFamilyLabel("Motor", "fr"), "Automobile");
  assert.equal(productFamilyLabel("Travel/assistance", "fr"), "Voyage et assistance");
  assert.equal(productFamilyLabel("Multirisque Habitation", "en"), "Home multirisk");
  assert.equal(productFamilyLabel("Sécur'Etudes", "fr"), "Sécur'Etudes");
  assert.deepEqual(productFamilyLabels(["Motor", "Automobile", "Auto", "Health", 3, ""], "fr"), ["Automobile", "Santé"]);
  for (const f of ["app/institutions/insurers.tsx", "app/institutions/insurer/[id].tsx"])
    assert.match(read(f), /productFamilyLabels\(insurer\.product_families, language\)/);
  assert.match(read("app/institutions/insurers.tsx"), /instFamiliesListNote/);
});

test("discovery polish: short names, broker register no., singular counts, search fallbacks", () => {
  const explore = read("app/(customer)/(tabs)/explore.tsx");
  assert.match(explore, /\{p\.short_name \?\? p\.name\}/);
  assert.match(explore, /p\.type === "broker"\s*\? \[p\.regulator_number/);
  assert.match(explore, /\{meta \? <Text/);
  assert.match(read("app/quote/product/[id].tsx"), /count === 1 \? t\("productsCountOne"\)/);
  assert.match(read("app/institutions/insurers.tsx"), /products\.length === 1 \? t\("productsCountOne"\)/);
  const search = read("app/search.tsx");
  assert.match(search, /const searched = !state\.loading && !!asked && \(!!state\.data \|\| !!state\.error\)/);
  assert.match(search, /router\.push\(customer \? "\/quote\/product" : "\/support"\)/);
  const profile = read("src/components/institutions/InstitutionProfile.tsx");
  assert.match(profile, /instCompareInsurers/);
  assert.match(profile, /start\(onlyProduct\.line_code, onlyProduct\.name\)/);
  for (const f of ["src/i18n/en.ts", "src/i18n/fr.ts"]) assert.match(read(f), /instCompareInsurers:/);
  assert.equal(existsSync(join(root, "src/components/InsuranceCards.tsx")), false);
});

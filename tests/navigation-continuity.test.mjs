// 1.3.x owner-reported fixes: return-from-another-app continuity, welcome
// pager, demo account picker, all-insurer offers + filters, heritage identity.
import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  hydrateStartStatus,
  resolveSystemPath,
  statusAfterNetworkFailure,
} from "../src/lib/navigationContinuity.ts";
import { clampPage, nextPage, pageFromOffset, previousPage, tapAllowed } from "../src/lib/pager.ts";
import { DEMO_FALLBACK_OTP, demoCredential, normalizeDemoDirectory } from "../src/lib/demoLogin.ts";
import { coverLevel, filterOffers, insurerSummary, sortOffers, compareRows } from "../src/lib/purchase.ts";

const read = (p) => readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

// --- 1. Returning from another app keeps the user's screen -------------------

test("a signed-in user is re-hydrated silently (no guard flip)", () => {
  assert.equal(hydrateStartStatus("authenticated"), "authenticated");
  assert.equal(hydrateStartStatus("anonymous"), "booting");
  assert.equal(hydrateStartStatus("error"), "booting");
  assert.equal(statusAfterNetworkFailure("authenticated"), "authenticated");
  assert.equal(statusAfterNetworkFailure("booting"), "error");
  const session = read("src/store/session.ts");
  assert.match(session, /set\(\{ status: hydrateStartStatus\(previous\), error: null \}\)/);
  assert.doesNotMatch(session, /async hydrate\(\) \{\s*set\(\{ status: "booting"/);
});

test("URL events while running (initial=false) never open invitation unless asked", () => {
  // Returning via launcher / payment app / bare scheme: stay where you are.
  for (const path of ["/", "", "opesinsure://", "opesinsure:///", "https://insurance.opesdatacenter.tech/app", "/app", "/random"])
    assert.equal(resolveSystemPath(path, false), "", `kept screen for ${JSON.stringify(path)}`);
  // An explicit invitation link still opens it (user intent).
  assert.equal(resolveSystemPath("https://insurance.opesdatacenter.tech/app/invitation?token=abc", false), "/(auth)/invitation?token=abc");
  assert.equal(resolveSystemPath("opesinsure://invitation?token=abc", false), "/(auth)/invitation?token=abc");
  assert.equal(resolveSystemPath("/invitations/accept?token=x", false), "/(auth)/invitation?token=x");
  assert.equal(resolveSystemPath("opesinsure://verify", false), "/verify");
  // Cold start keeps the 1.3.0 mapping.
  assert.equal(resolveSystemPath("https://insurance.opesdatacenter.tech/app/verify", true), "/verify");
  assert.equal(resolveSystemPath("/", true), "/");
  assert.equal(resolveSystemPath("/sign-in", true), "/(auth)/sign-in");
  assert.match(read("app/+native-intent.tsx"), /resolveSystemPath\(path, initial\)/);
});

test("guard fallback lands on index (router), never on the invitation screen", () => {
  const layout = read("app/_layout.tsx");
  const screens = [...layout.matchAll(/<Stack\.Screen name="([^"]+)"/g)].map((m) => m[1]);
  assert.equal(screens[0], "index", "index must be the first declared screen");
  // invitation is only reachable by an explicit push / deep link.
  const pushes = [
    ...read("app/welcome.tsx").matchAll(/invitation/g),
    ...read("app/(auth)/sign-in.tsx").matchAll(/router\.push\("\/\(auth\)\/invitation"\)/g),
  ];
  assert.ok(pushes.length > 0);
  for (const f of ["src/components/AppRuntime.tsx", "src/store/session.ts", "app/index.tsx", "app/_layout.tsx"])
    assert.doesNotMatch(read(f), /router\.(replace|push)\([^)]*invitation/, `${f} must not route to invitation`);
});

test("runtime gate raised on resume overlays the stack instead of unmounting it", () => {
  const runtime = read("src/components/AppRuntime.tsx");
  assert.match(runtime, /if \(blocked && !navigationMounted\.current\)/);
  assert.match(runtime, /overlay === "gate"/);
  assert.match(runtime, /StyleSheet\.absoluteFill, styles\.gate/);
});

// --- 2. Welcome pager ---------------------------------------------------------

test("welcome pager: clamped pages, rapid taps, back, measured width", () => {
  assert.equal(nextPage(2, 3), 2, "never past the last slide");
  assert.equal(nextPage(0, 3), 1);
  assert.equal(previousPage(0, 3), 0);
  assert.equal(clampPage(Number.NaN, 3), 0);
  assert.equal(pageFromOffset(719, 360, 3), 2);
  assert.equal(pageFromOffset(5000, 360, 3), 2);
  assert.equal(pageFromOffset(100, 0, 3), 0, "unmeasured pager");
  assert.equal(tapAllowed(1000, 1100), false, "double tap swallowed");
  assert.equal(tapAllowed(1000, 1500), true);
  const welcome = read("app/welcome.tsx");
  assert.match(welcome, /goTo\(nextPage\(pageRef\.current, count\)\)/);
  assert.doesNotMatch(welcome, /goTo\(page \+ 1\)/);
  assert.match(welcome, /hardwareBackPress/);
  assert.match(welcome, /onLayout=\{onLayout\}/);
  assert.match(welcome, /primary\(\(\) => leave\("\/\(auth\)\/sign-up"\)\)/);
});

// --- 3. Demo account picker ---------------------------------------------------

test("demo login: top-level data.password first, then OTP (123456 fallback)", () => {
  const acct = { label: "Customer", phone_e164: "+237600000001" };
  assert.deepEqual(demoCredential({ otp: "123456", password: "Demo#1", accounts: [acct] }, acct), {
    kind: "password",
    phone: "+237600000001",
    password: "Demo#1",
  });
  assert.deepEqual(demoCredential({ otp: "654321", password: null, accounts: [acct] }, acct), {
    kind: "otp",
    phone: "+237600000001",
    otp: "654321",
  });
  assert.equal(demoCredential({ otp: null, password: "", accounts: [acct] }, acct).otp, DEMO_FALLBACK_OTP);
  assert.equal(normalizeDemoDirectory(null), null);
  assert.equal(normalizeDemoDirectory({ otp: "1", accounts: [] }), null);
  assert.equal(normalizeDemoDirectory({ otp: 123456, password: "p", accounts: [acct, { bad: 1 }] }).accounts.length, 1);
  const signIn = read("app/(auth)/sign-in.tsx");
  assert.match(signIn, /<DemoAccountPicker/);
  assert.doesNotMatch(signIn, /demo\.accounts\.map\(/, "no list of buttons");
  assert.match(read("src/components/auth/DemoAccountPicker.tsx"), /t\("demoChoose"\)/);
  assert.match(read("src/i18n/en.ts"), /demoChoose: "Choose a demo account"/);
});

// --- 4. Offers from every insurer ---------------------------------------------

const offer = (id, carrier, total, covers, excess = 0, extra = {}) => ({
  id,
  carrier_id: carrier,
  total_minor: total,
  premium_minor: total,
  tax_minor: 0,
  fee_minor: 0,
  valid_until: "2099-01-01T00:00:00Z",
  status: "OFFERED",
  coverage_snapshot: {
    coverages: covers.map((c) => ({ code: c, name: c, mandatory: c === "RC", limit_minor: 1000, deductible_minor: c === "OD" ? excess : 0 })),
  },
  carrier: { party: { display_name: carrier.toUpperCase() } },
  ...extra,
});

test("offers: every insurer summarised, filters and sorts", () => {
  const list = [
    offer("1", "axa", 520, ["RC", "OD", "THEFT"], 2500),
    offer("2", "chanas", 485, ["RC", "OD", "THEFT", "GLASS"], 1000),
    offer("3", "nsia", 448, ["RC"]),
    offer("4", "chanas", 600, ["RC", "OD"], 500),
  ];
  const summary = insurerSummary(list);
  assert.deepEqual(summary.map((s) => s.carrierId), ["nsia", "chanas", "axa"]);
  assert.equal(summary.find((s) => s.carrierId === "chanas").offers, 2);
  assert.equal(coverLevel(list[1], list), "full");
  assert.equal(coverLevel(list[0], list), "standard");
  assert.equal(coverLevel(list[2], list), "essential");
  assert.deepEqual(filterOffers(list, { coverLevels: ["full"] }).map((o) => o.id), ["2"]);
  assert.deepEqual(filterOffers(list, { minPremiumMinor: 480, maxPremiumMinor: 530 }).map((o) => o.id), ["1", "2"]);
  assert.deepEqual(filterOffers(list, { maxExcessMinor: 1000 }).map((o) => o.id), ["2", "3", "4"]);
  assert.deepEqual(sortOffers(list, "insurer").map((o) => o.id), ["1", "2", "4", "3"]);
  assert.deepEqual(sortOffers(list, "excess").map((o) => o.id), ["3", "4", "2", "1"]);
  // carrier.id fallback when carrier_id is missing.
  assert.deepEqual(filterOffers([{ ...list[0], carrier_id: "", carrier: { id: "axa" } }], { providers: ["axa"] }).length, 1);
  // Optional API fields only appear when supplied.
  const rows = compareRows(list);
  assert.ok(rows.some((r) => r.key === "level"));
  assert.ok(!rows.some((r) => r.key === "rating"));
  const rated = compareRows([offer("5", "a", 1, ["RC"], 0, { payment_options: ["MTN_MOMO"], carrier: { rating: "A", claims_settlement_days: 12 } }), list[0]]);
  assert.ok(rated.some((r) => r.key === "rating") && rated.some((r) => r.key === "claims_days") && rated.some((r) => r.key === "payment"));
  assert.deepEqual(filterOffers(list, { paymentMethods: ["MTN_MOMO"] }), []);
});

test("compare screen accepts every visible offer, not only three", () => {
  const compare = read("app/quote/compare.tsx");
  assert.doesNotMatch(compare, /\.slice\(0, 3\)/);
  const offers = read("app/quote/offers.tsx");
  assert.match(offers, /t\("ofCompareAll"/);
  assert.match(offers, /ofInsurersAnswered/);
});

// --- 5. Visual identity ------------------------------------------------------

const lum = (h) => {
  const c = [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16) / 255).map((x) => (x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4));
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
};
const ratio = (a, b) => (Math.max(lum(a), lum(b)) + 0.05) / (Math.min(lum(a), lum(b)) + 0.05);

test("heritage palette keeps token names and AA contrast", () => {
  const tokens = read("src/theme/tokens.ts");
  const hex = (name) => tokens.match(new RegExp(`\\b${name}: '(#[0-9A-F]{6})'`))[1];
  for (const name of ["navy950", "navy900", "navy800", "blue700", "blue600", "blue500", "blue100", "blue50", "gold600", "gold500", "gold100", "gold50", "neutral950", "neutral50", "white", "success", "warning", "danger"])
    assert.ok(hex(name), `token ${name} kept`);
  const text = ["navy950", "blue600", "blue700", "blue500", "gold600", "neutral600", "neutral500", "successText", "warningText", "dangerText", "terracotta700"];
  for (const name of text) {
    assert.ok(ratio(hex(name), "#FFFFFF") >= 4.5, `${name} on white`);
    assert.ok(ratio(hex(name), hex("neutral50")) >= 4.5, `${name} on neutral50`);
  }
  assert.ok(ratio("#FFFFFF", hex("blue600")) >= 4.5, "button label");
  assert.ok(ratio(hex("gold500"), hex("navy950")) >= 4.5, "ochre wordmark on indigo");
  assert.ok(ratio(hex("gold100"), hex("navy950")) >= 4.5, "tagline on indigo");
  assert.ok(ratio(hex("warningText"), hex("warningSoft")) >= 4.5);
  assert.ok(ratio(hex("successText"), hex("successSoft")) >= 4.5);
  assert.ok(ratio(hex("dangerText"), hex("dangerSoft")) >= 4.5);
});

test("vector heritage pattern replaces stretched raster art on auth screens", () => {
  const pattern = read("src/components/HeritagePattern.tsx");
  assert.match(pattern, /from "react-native-svg"/);
  assert.match(pattern, /pointerEvents="none"/);
  for (const f of ["src/components/auth/AuthHero.tsx", "src/components/auth/AuthFooter.tsx", "src/components/onboarding/OnboardingParts.tsx"]) {
    const src = read(f);
    assert.doesNotMatch(src, /tribal|bottom_wave|splash_bottom_wave|africa_network/, `${f} no raster decoration`);
    assert.match(src, /HeritagePattern|KenteBand|NdopSurface/);
  }
  assert.match(read("src/components/ui.tsx"), /<HeritageAccent \/>/);
  assert.match(read("src/components/auth/AuthHero.tsx"), /setStatusBarStyle\("light"\)/);
});

test("OTA-safe: no new dependencies, no native config changes in this fix", () => {
  const pkg = JSON.parse(read("package.json"));
  assert.ok(pkg.dependencies["react-native-svg"], "svg was already a dependency");
  for (const f of ["src/components/HeritagePattern.tsx", "src/components/auth/DemoAccountPicker.tsx", "app/welcome.tsx"]) {
    for (const m of read(f).matchAll(/from "([^"@.][^"]*|@[^/"]+\/[^/"]+)"/g)) {
      const dep = m[1].startsWith("@") ? m[1] : m[1].split("/")[0];
      if (dep.startsWith("@/")) continue;
      assert.ok(pkg.dependencies[dep] || dep === "react" || dep === "react-native", `${f}: ${dep} must already be installed`);
    }
  }
});

// --- Vehicle picker: make → model → generation → year → engine variant -------

import { applyVariant, generationYears, normalizeGenerations, normalizeVariants, selectionToValues, variantSummary } from "../src/lib/vehicles.ts";

test("vehicle generations/variants normalize, years clamp, specs auto-fill", () => {
  assert.deepEqual(normalizeGenerations({ data: [] }), [], "empty until the master has data");
  const gens = normalizeGenerations({ data: [{ code: "J150", name: "J150", year_from: 2009, year_to: 2017, body_type: "SUV", variants_count: 2 }, { name: "no code" }] });
  assert.equal(gens.length, 1);
  assert.deepEqual(generationYears(gens[0], { min: 1950, max: 2027 }).slice(0, 2), ["2017", "2016"]);
  assert.equal(generationYears(gens[0], { min: 1950, max: 2027 }).at(-1), "2009");
  const open = generationYears({ year_from: 2024, year_to: null }, { min: 1950, max: 2027 });
  assert.deepEqual(open, ["2027", "2026", "2025", "2024"]);
  const [v] = normalizeVariants({ data: [{ code: "V1", name: "2.8 D-4D", engine_label: "2.8 D-4D 177hp", specs: { power_hp: 177, displacement_cc: 2755, fuel_type: "DIESEL", transmission: "AUTOMATIC", drivetrain: "AWD", body_type: null } }] });
  assert.equal(v.name, "2.8 D-4D 177hp");
  assert.match(variantSummary(v), /177 hp · 2755 cc · DIESEL/);
  const sel = applyVariant({ make: "Toyota", model: "Prado", model_code: "prado", generation_code: "J150", body_type: "SUV", year: "2015" }, v);
  assert.equal(sel.powertrain, "DIESEL");
  assert.equal(sel.body_type, "SUV", "unknown spec keeps the previous value");
  const values = selectionToValues(sel);
  assert.equal(values.vehicle_variant_code, "V1");
  assert.equal(values.vehicle_generation_code, "J150");
  assert.equal(values.engine_capacity_cc, "2755");
  const client = read("src/api/client.ts");
  assert.match(client, /\/public\/vehicles\/models\/\$\{encodeURIComponent\(modelCode\)\}\/generations`/);
  assert.match(client, /\/generations\/\$\{encodeURIComponent\(generationCode\)\}\/variants/);
  const picker = read("src/components/vehicles/VehiclePicker.tsx");
  assert.match(picker, /setMode\(list\.length \? "generation" : "done"\)/, "hidden when empty");
  assert.match(picker, /setMode\(list\.length \? "variant" : "done"\)/);
});

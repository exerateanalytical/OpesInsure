import { readFileSync, existsSync, readdirSync } from "node:fs";

const readJson = (path) => JSON.parse(readFileSync(path, "utf8"));
const profile = process.argv.includes("--production") ? "production" : "preview";
const errors = [];
const required = [
  "app.config.js",
  "eas.json",
  "plugins/withOpesInsureSecurity.js",
  "store/associations/assetlinks.json",
  "store/associations/apple-app-site-association",
  "maestro/smoke-customer.yaml",
];
for (const path of required) if (!existsSync(path)) errors.push(`MISSING:${path}`);
const eas = readJson("eas.json");
// Demo mode is gone: no profile may bake a demo environment or demo login.
for (const [name, p] of Object.entries(eas.build)) {
  if (/demo/i.test(name) || /demo/i.test(p.channel ?? "")) errors.push(`DEMO_PROFILE_FORBIDDEN:${name}`);
  const env = p.env ?? {};
  if ("EXPO_PUBLIC_SHOW_DEMO_LOGIN" in env) errors.push(`DEMO_LOGIN_FLAG_FORBIDDEN:${name}`);
  if (env.EXPO_PUBLIC_APP_ENV && !["staging", "production"].includes(env.EXPO_PUBLIC_APP_ENV))
    errors.push(`INVALID_APP_ENV:${name}`);
}
if (eas.build.production.channel !== "production")
  errors.push("PRODUCTION_CHANNEL_INVALID");
// production-apk is a separately distributed binary: it must never share an update channel with the store build.
const apk = eas.build["production-apk"];
if (apk && (apk.channel ?? eas.build[apk.extends]?.channel) === eas.build.production.channel)
  errors.push("PRODUCTION_APK_SHARES_UPDATE_CHANNEL");
// One version everywhere: package.json is the source, app.json mirrors it.
const pkg = readJson("package.json");
const app = readJson("app.json");
if (app.expo.version !== pkg.version) errors.push(`VERSION_MISMATCH:app.json=${app.expo.version},package.json=${pkg.version}`);
if (app.expo.runtimeVersion?.policy !== "appVersion") errors.push("RUNTIME_POLICY_NOT_APPVERSION");
// OPS-03: OTA updates must be code-signed; the certificate (public) is committed, the private key never is.
const updates = app.expo.updates ?? {};
if (!updates.codeSigningCertificate || !existsSync(updates.codeSigningCertificate)) errors.push("OTA_CODE_SIGNING_CERTIFICATE_MISSING");
if (updates.codeSigningMetadata?.alg !== "rsa-v1_5-sha256" || !updates.codeSigningMetadata?.keyid) errors.push("OTA_CODE_SIGNING_METADATA_INVALID");
if (existsSync("keys") || existsSync("certs/private-key.pem")) errors.push("OTA_PRIVATE_KEY_IN_PROJECT(move it to ~/.opesinsure-keys/codesigning)");
// PERF-001: keep Hermes + New Architecture in every release build.
if (app.expo.newArchEnabled !== true) errors.push("NEW_ARCHITECTURE_DISABLED");
if ((app.expo.jsEngine ?? "hermes") !== "hermes") errors.push("HERMES_DISABLED");
// PERF-002: direct APKs ship arm64-v8a + armeabi-v7a only, with R8 and
// resource shrinking (x86/x86_64 are emulator/Chromebook-only payloads).
const buildProps = app.expo.plugins.find((p) => Array.isArray(p) && p[0] === "expo-build-properties")?.[1]?.android;
if (!buildProps) errors.push("BUILD_PROPERTIES_MISSING");
else {
  if (!buildProps.enableProguardInReleaseBuilds) errors.push("R8_MINIFY_DISABLED");
  if (!buildProps.enableShrinkResourcesInReleaseBuilds) errors.push("RESOURCE_SHRINK_DISABLED");
  if (!buildProps.buildArchs?.includes("arm64-v8a")) errors.push("ARM64_ABI_MISSING");
}
// PERF-003: importing the @expo-google-fonts/inter index bundles all 18 TTFs.
const layout = readFileSync("app/_layout.tsx", "utf8");
if (/from\s+["']@expo-google-fonts\/inter["']/.test(layout)) errors.push("FONT_INDEX_IMPORT_BUNDLES_ALL_WEIGHTS");
// PROD-001: crash telemetry must be wired and use backend-accepted event codes.
if (!layout.includes("ProductionErrorBoundary") || !layout.includes("Telemetry.installGlobalHandlers"))
  errors.push("CRASH_TELEMETRY_NOT_WIRED");
const backendRuntime = "../config/mobile_runtime.php";
if (existsSync(backendRuntime)) {
  const allowed = new Set([...readFileSync(backendRuntime, "utf8").matchAll(/'([A-Z_]+)'/g)].map((m) => m[1]));
  const telemetry = readFileSync("src/security/telemetry.ts", "utf8");
  const union = telemetry.match(/export type TelemetryEvent =([\s\S]*?);/)?.[1] ?? "";
  for (const [, code] of union.matchAll(/"([A-Z_]+)"/g)) if (!allowed.has(code)) errors.push(`TELEMETRY_EVENT_NOT_ACCEPTED_BY_BACKEND:${code}`);
}
// Scratch files must never ship in scripts/.
for (const name of readdirSync("scripts")) if (/^_.*tmp/i.test(name)) errors.push(`TEMP_FILE_IN_SCRIPTS:${name}`);
// PROD-002: a store release needs explicit sign-off that every P0 audit item passed.
if (profile === "production" && process.argv.includes("--store") && process.env.OPES_P0_SIGNOFF !== "1")
  errors.push("P0_AUDIT_SIGNOFF_REQUIRED(set OPES_P0_SIGNOFF=1 after acceptance journeys pass)");
const associations = readFileSync("store/associations/assetlinks.json", "utf8");
// The Play App Signing fingerprint only exists once the app is on Play: it
// blocks store releases (--store), not the direct-download APK channel.
if (profile === "production" && process.argv.includes("--store") && associations.includes("REPLACE_WITH_"))
  errors.push("ANDROID_ASSOCIATION_PLACEHOLDER");
if (profile === "production" && !process.env.EXPO_PUBLIC_API_BASE_URL?.startsWith("https://"))
  errors.push("PRODUCTION_HTTPS_API_REQUIRED");
if (errors.length) {
  process.stderr.write(`${errors.join("\n")}\n`);
  process.exit(1);
}
process.stdout.write(`OpesInsure release doctor passed for ${profile}.\n`);

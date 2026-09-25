import { readFileSync, existsSync } from "node:fs";

const readJson = (path) => JSON.parse(readFileSync(path, "utf8"));
const profile = process.argv.includes("--production") ? "production" : "demo";
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
if (eas.build.production.env.EXPO_PUBLIC_SHOW_DEMO_LOGIN !== "false")
  errors.push("PRODUCTION_DEMO_MODE_NOT_FALSE");
if (eas.build.production.channel !== "production")
  errors.push("PRODUCTION_CHANNEL_INVALID");
// production-apk bakes a different JS env (SHOW_DEMO_LOGIN=true): it must
// never share an update channel with the store build.
const apk = eas.build["production-apk"];
if (apk && (apk.channel ?? eas.build[apk.extends]?.channel) === eas.build.production.channel)
  errors.push("PRODUCTION_APK_SHARES_UPDATE_CHANNEL");
// One version everywhere: package.json is the source, app.json mirrors it.
const pkg = readJson("package.json");
const app = readJson("app.json");
if (app.expo.version !== pkg.version) errors.push(`VERSION_MISMATCH:app.json=${app.expo.version},package.json=${pkg.version}`);
if (app.expo.runtimeVersion?.policy !== "appVersion") errors.push("RUNTIME_POLICY_NOT_APPVERSION");
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

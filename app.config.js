const base = require("./app.json");
const fs = require("fs");
const path = require("path");

// FCM (Android push). EAS "file" env var GOOGLE_SERVICES_JSON holds the path
// of the uploaded google-services.json; locally a ./google-services.json is
// used if present. Without either the build still succeeds (push disabled).
function googleServicesFile() {
  const fromEnv = process.env.GOOGLE_SERVICES_JSON;
  if (fromEnv) return fromEnv;
  const local = path.resolve("google-services.json");
  return fs.existsSync(local) ? "./google-services.json" : undefined;
}
// Single source of truth for the version: package.json. app.json mirrors it
// (tests/release-config.test.mjs keeps the two aligned).
const { version } = require("./package.json");

// OPS-09: EXPO_ANDROID_BUILD_ARCHS=arm64-v8a (eas.json production-apk-arm64)
// narrows expo-build-properties' ABI list; unset keeps app.json unchanged.
function withBuildArchs(plugins) {
  const archs = (process.env.EXPO_ANDROID_BUILD_ARCHS ?? "").split(",").map((a) => a.trim()).filter(Boolean);
  if (archs.length === 0) return plugins;
  return plugins.map((p) =>
    Array.isArray(p) && p[0] === "expo-build-properties"
      ? [p[0], { ...p[1], android: { ...p[1]?.android, buildArchs: archs } }]
      : p,
  );
}

// OPS-05: Sentry's config plugin (source-map upload for EAS builds) only when
// all three EAS secrets exist, so builds without them still succeed. The SDK's
// native module is autolinked either way; the JS side stays off without a DSN.
const sentryPlugin =
  process.env.SENTRY_AUTH_TOKEN && process.env.SENTRY_ORG && process.env.SENTRY_PROJECT
    ? [["@sentry/react-native/expo", {
        organization: process.env.SENTRY_ORG,
        project: process.env.SENTRY_PROJECT,
        url: process.env.SENTRY_URL ?? "https://de.sentry.io/",
      }]]
    : [];

module.exports = () => {
  const environment = process.env.EXPO_PUBLIC_APP_ENV ?? "staging";
  const production = environment === "production";
  return {
    ...base.expo,
    version,
    // Fingerprint: any native change (plugin, permission, native module)
    // produces a new runtime, so an OTA can never reach an incompatible
    // binary even if someone forgets to bump the version.
    runtimeVersion: { policy: "appVersion" },
    ios: {
      ...base.expo.ios,
      associatedDomains: ["applinks:insurance.opesdatacenter.tech"],
      infoPlist: {
        // HTTPS/TLS only + expo-crypto hashing/UUIDs: exempt from export docs.
        ITSAppUsesNonExemptEncryption: false,
        NSFaceIDUsageDescription: "Use Face ID to protect insurance and financial information. / Utilisez Face ID pour protéger vos informations d'assurance et financières.",
      },
    },
    android: {
      ...base.expo.android,
      ...(googleServicesFile() ? { googleServicesFile: googleServicesFile() } : {}),
      intentFilters: [
        {
          action: "VIEW",
          autoVerify: true,
          data: [
            { scheme: "https", host: "insurance.opesdatacenter.tech", pathPrefix: "/app" },
          ],
          category: ["BROWSABLE", "DEFAULT"],
        },
        {
          // Document QR codes (…/verify?code=… or ?ref=…&t=…) open the in-app verify page.
          action: "VIEW",
          autoVerify: false,
          data: [
            { scheme: "https", host: "insurance.opesdatacenter.tech", pathPrefix: "/verify" },
          ],
          category: ["BROWSABLE", "DEFAULT"],
        },
      ],
    },
    plugins: [
      ...withBuildArchs(base.expo.plugins),
      ["./plugins/withOpesInsureSecurity", { enableFlagSecure: production, nonExportedActivities: ["com.canhub.cropper.CropImageActivity"] }],
      ...sentryPlugin,
    ],
    extra: {
      ...base.expo.extra,
      appEnvironment: environment,
      releaseChannel: process.env.EXPO_PUBLIC_RELEASE_CHANNEL ?? (production ? "production" : "development"),
      eas: { projectId: process.env.EXPO_PUBLIC_EAS_PROJECT_ID ?? base.expo.extra?.eas?.projectId },
    },
  };
};

const base = require("./app.json");
// Single source of truth for the version: package.json. app.json mirrors it
// (tests/release-config.test.mjs keeps the two aligned).
const { version } = require("./package.json");

module.exports = () => {
  const environment = process.env.EXPO_PUBLIC_APP_ENV ?? "demo";
  const production = environment === "production";
  return {
    ...base.expo,
    version,
    // Fingerprint: any native change (plugin, permission, native module)
    // produces a new runtime, so an OTA can never reach an incompatible
    // binary even if someone forgets to bump the version.
    runtimeVersion: { policy: "fingerprint" },
    ios: {
      ...base.expo.ios,
      associatedDomains: ["applinks:insurance.opesdatacenter.tech"],
      infoPlist: {
        // HTTPS/TLS only + expo-crypto hashing/UUIDs: exempt from export docs.
        ITSAppUsesNonExemptEncryption: false,
        NSFaceIDUsageDescription: "Use Face ID to protect insurance and financial information.",
      },
    },
    android: {
      ...base.expo.android,
      intentFilters: [
        {
          action: "VIEW",
          autoVerify: true,
          data: [
            { scheme: "https", host: "insurance.opesdatacenter.tech", pathPrefix: "/app" },
          ],
          category: ["BROWSABLE", "DEFAULT"],
        },
      ],
    },
    plugins: [
      ...base.expo.plugins,
      ["./plugins/withOpesInsureSecurity", { enableFlagSecure: production }],
    ],
    extra: {
      ...base.expo.extra,
      appEnvironment: environment,
      releaseChannel: process.env.EXPO_PUBLIC_RELEASE_CHANNEL ?? "demo",
      eas: { projectId: process.env.EXPO_PUBLIC_EAS_PROJECT_ID ?? base.expo.extra?.eas?.projectId },
    },
  };
};

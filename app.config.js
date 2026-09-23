const base = require("./app.json");

module.exports = () => {
  const environment = process.env.EXPO_PUBLIC_APP_ENV ?? "demo";
  const production = environment === "production";
  return {
    ...base.expo,
    version: "1.2.0",
    runtimeVersion: { policy: "appVersion" },
    ios: {
      ...base.expo.ios,
      associatedDomains: ["applinks:insurance.opesdatacenter.tech"],
      infoPlist: {
        ITSAppUsesNonExemptEncryption: true,
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

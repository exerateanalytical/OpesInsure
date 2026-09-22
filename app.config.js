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
      buildNumber: process.env.IOS_BUILD_NUMBER ?? "1",
      associatedDomains: ["applinks:opesinsure.com", "applinks:www.opesinsure.com"],
      infoPlist: {
        ITSAppUsesNonExemptEncryption: true,
        NSFaceIDUsageDescription: "Use Face ID to protect insurance and financial information.",
      },
    },
    android: {
      ...base.expo.android,
      versionCode: Number(process.env.ANDROID_VERSION_CODE ?? 1),
      intentFilters: [
        {
          action: "VIEW",
          autoVerify: true,
          data: [
            { scheme: "https", host: "opesinsure.com", pathPrefix: "/app" },
            { scheme: "https", host: "www.opesinsure.com", pathPrefix: "/app" },
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
      eas: { projectId: process.env.EXPO_PUBLIC_EAS_PROJECT_ID ?? "SET_IN_EAS" },
    },
  };
};

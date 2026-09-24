import * as Application from "expo-application";

export type AppEnvironment = "demo" | "staging" | "production";

const environment = (process.env.EXPO_PUBLIC_APP_ENV ?? (__DEV__ ? "demo" : "production")) as AppEnvironment;
const apiBaseUrl = process.env.EXPO_PUBLIC_API_BASE_URL ?? "";
// Demo mode = a non-production build environment. It is not the same thing as
// showing the demo accounts on sign-in: that list is served by the backend only
// while server-side demo mode is on, so production builds may show it safely.
const demoMode = environment === "demo";
const showDemoLogin = process.env.EXPO_PUBLIC_SHOW_DEMO_LOGIN === "true";
const releaseChannel = process.env.EXPO_PUBLIC_RELEASE_CHANNEL ?? "development";

export const environmentConfig = {
  environment,
  apiBaseUrl,
  demoMode,
  showDemoLogin,
  releaseChannel,
  appVersion: Application.nativeApplicationVersion ?? "1.1.0",
  buildVersion: Application.nativeBuildVersion ?? "development",
};

export function productionConfigurationIssues() {
  const issues: string[] = [];
  if (!(["demo", "staging", "production"] as string[]).includes(environment))
    issues.push("INVALID_APP_ENV");
  if (environment === "production") {
    if (demoMode) issues.push("DEMO_MODE_FORBIDDEN");
    if (!apiBaseUrl.startsWith("https://")) issues.push("HTTPS_API_REQUIRED");
    if (/example\.com|localhost|10\.0\.2\.2/.test(apiBaseUrl))
      issues.push("NON_PRODUCTION_API_HOST");
    if (releaseChannel !== "production") issues.push("PRODUCTION_CHANNEL_REQUIRED");
  }
  return issues;
}

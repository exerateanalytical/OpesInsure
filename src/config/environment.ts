import * as Application from "expo-application";
import Constants from "expo-constants";

export type AppEnvironment = "demo" | "staging" | "production";

/** Production host. Used as the fallback for production bundles so an
 * `eas update` published without EXPO_PUBLIC_* env cannot trip the
 * HTTPS_API_REQUIRED gate and brick every installed app. */
export const PRODUCTION_API_BASE_URL = "https://insurance.opesdatacenter.tech/api/v1";
export const PUBLIC_SITE_URL = "https://insurance.opesdatacenter.tech";

const environment = (process.env.EXPO_PUBLIC_APP_ENV ?? (__DEV__ ? "demo" : "production")) as AppEnvironment;
const apiBaseUrl =
  process.env.EXPO_PUBLIC_API_BASE_URL || (environment === "production" ? PRODUCTION_API_BASE_URL : "");
// Demo mode = a non-production build environment. It is not the same thing as
// showing the demo accounts on sign-in: that list is served by the backend only
// while server-side demo mode is on, so production builds may show it safely.
const demoMode = environment === "demo";
const showDemoLogin = process.env.EXPO_PUBLIC_SHOW_DEMO_LOGIN === "true";
const releaseChannel =
  process.env.EXPO_PUBLIC_RELEASE_CHANNEL || (environment === "production" ? "production" : "development");

export const environmentConfig = {
  environment,
  apiBaseUrl,
  demoMode,
  showDemoLogin,
  releaseChannel,
  // Single source of truth: the native version, else the app config version
  // (app.config.js reads package.json). No stale hard-coded fallback.
  appVersion: Application.nativeApplicationVersion ?? Constants.expoConfig?.version ?? "0.0.0",
  buildVersion: Application.nativeBuildVersion ?? "development",
  /** Optional build-time overrides for the app lock (seconds). */
  relockGraceSeconds: process.env.EXPO_PUBLIC_RELOCK_GRACE_SECONDS,
  idleTimeoutSeconds: process.env.EXPO_PUBLIC_IDLE_TIMEOUT_SECONDS,
};

export const legalLinks = (server?: { privacy_policy_url?: string | null; account_deletion_url?: string | null; terms_url?: string | null } | null) => ({
  privacy: server?.privacy_policy_url || `${PUBLIC_SITE_URL}/privacy`,
  accountDeletion: server?.account_deletion_url || `${PUBLIC_SITE_URL}/account/delete`,
  terms: server?.terms_url || `${PUBLIC_SITE_URL}/terms`,
});

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

/**
 * Optional environment banner from GET /mobile/runtime/bootstrap
 * data.environment ({name, demo_mode, banner}). Shown only when the server
 * sets a banner; production without one shows nothing. Pure (Node tests).
 */
export type RuntimeEnvironment = { name?: string; demo_mode?: boolean; banner?: string | null } | null | undefined;

export function environmentBanner(env: RuntimeEnvironment): { kind: "demo" } | { kind: "label"; banner: string } | null {
  const banner = typeof env?.banner === "string" ? env.banner.trim() : "";
  if (!banner) return null;
  return banner.toUpperCase() === "DEMO" ? { kind: "demo" } : { kind: "label", banner: banner.slice(0, 40) };
}

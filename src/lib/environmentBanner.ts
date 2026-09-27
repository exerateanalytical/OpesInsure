/**
 * Optional environment banner from GET /mobile/runtime/bootstrap
 * data.environment ({name, banner}). Shown only when the server sets a
 * non-demo label (e.g. STAGING); a DEMO banner is never rendered and
 * production without one shows nothing. Pure (Node tests).
 */
export type RuntimeEnvironment = { name?: string; banner?: string | null } | null | undefined;

export function environmentBanner(env: RuntimeEnvironment): { kind: "label"; banner: string } | null {
  const banner = typeof env?.banner === "string" ? env.banner.trim() : "";
  if (!banner || /^demo\b/i.test(banner)) return null;
  return { kind: "label", banner: banner.slice(0, 40) };
}

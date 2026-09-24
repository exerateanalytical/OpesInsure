/**
 * Universal/App Links are registered for https://insurance.opesdatacenter.tech
 * with pathPrefix /app (app.json / app.config.js), but no route lives under
 * /app. Strip that prefix so e.g. /app/verify opens app/verify.tsx and
 * /app/invitation?token=... opens the invitation screen. Anything unmatched
 * falls through to +not-found.
 */
export function redirectSystemPath({ path }: { path: string; initial: boolean }): string {
  try {
    let value = path;
    const match = /^https?:\/\/[^/]+(\/.*)?$/i.exec(value);
    if (match) value = match[1] ?? "/";
    value = value.replace(/^\/app(?=\/|\?|#|$)/, "") || "/";
    if (!value.startsWith("/") && !value.includes("://")) value = `/${value}`;
    // Friendly aliases used in partner emails / printed certificates.
    value = value
      .replace(/^\/invitation(?=\/|\?|$)/, "/(auth)/invitation")
      .replace(/^\/invitations\/accept(?=\/|\?|$)/, "/(auth)/invitation")
      .replace(/^\/sign-in(?=\/|\?|$)/, "/(auth)/sign-in");
    return value;
  } catch {
    return "/";
  }
}

/**
 * Parses what a verification QR / typed value carries into the reference and
 * optional token POST /public/verify expects. Accepted shapes:
 *  - https://insurance.opesdatacenter.tech/verify?code=CODE   (document engine QR)
 *  - https://insurance.opesdatacenter.tech/verify?ref=SERIAL&t=TOKEN (certificate QR)
 *  - opesinsure://verify?code=… / opesinsure://verify/CODE / …/app/verify/CODE
 *  - a bare code, serial or policy number.
 */
export type VerifyTarget = { reference: string; token: string | null };

export function parseVerifyInput(raw: string | null | undefined): VerifyTarget | null {
  const value = String(raw ?? "").trim();
  if (!value) return null;
  const url = /^[a-z][a-z0-9+.-]*:\/\/[^/?#]*([^?#]*)(\?[^#]*)?/i.exec(value);
  if (url) {
    const query = new Map<string, string>();
    for (const part of (url[2] ?? "").replace(/^\?/, "").split("&")) {
      if (!part) continue;
      const [k = "", v = ""] = part.split("=");
      try {
        query.set(decodeURIComponent(k), decodeURIComponent(v.replace(/\+/g, " ")));
      } catch {
        query.set(k, v);
      }
    }
    const code = query.get("code") ?? query.get("ref") ?? query.get("reference");
    const token = query.get("t") ?? query.get("token") ?? null;
    if (code?.trim()) return { reference: code.trim(), token: token?.trim() || null };
    // opesinsure://verify/CODE (host = verify) or https://…/verify/CODE
    const path = /(?:^|\/)verify\/([^/]+)\/?$/i.exec(`${value.startsWith("opesinsure://") ? "/" : ""}${url[1] ?? ""}`)
      ?? /^opesinsure:\/\/verify\/([^/?#]+)/i.exec(value);
    if (path?.[1]) {
      try {
        return { reference: decodeURIComponent(path[1]).trim(), token: token?.trim() || null };
      } catch {
        return { reference: path[1].trim(), token: token?.trim() || null };
      }
    }
    return null;
  }
  return { reference: value, token: null };
}

/** expo-router target for the in-app result page. */
export function verifyRoute(target: VerifyTarget, source?: string | null, title?: string | null) {
  const params: { code: string; t?: string; source?: string; title?: string } = { code: target.reference };
  if (target.token) params.t = target.token;
  if (source) params.source = source;
  if (title) params.title = title;
  return { pathname: "/verify/[code]" as const, params };
}

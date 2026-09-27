/**
 * PII scrubbing for crash reports (OPS-05). Pure and dependency-free so it
 * is unit-tested under node. Applied in Sentry's beforeSend and
 * beforeBreadcrumb: anything that could identify a person or a contract is
 * masked or dropped before an event leaves the device.
 */
type Json = unknown;

/** Keys whose values are dropped wholesale (headers, KYC, bodies, identities). */
const SENSITIVE_KEY =
  /authorization|cookie|set-cookie|token|secret|password|passcode|otp|\bpin\b|^pin|name|email|phone|msisdn|payer|address|birth|dob|national|id_?number|id_?card|cni|passport|kyc|document_number|policy_number|claim_number|proposal_number|certificate|plate|registration|iban|account_number|body|payload|data$|^data|request_body|signature|latitude|longitude|user/i;

const PATTERNS: [RegExp, string][] = [
  [/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, "[email]"],
  // Cameroon numbers: +237 / 00237 / 237 prefix, then 9 digits (spaces/dashes allowed).
  [/(?:\+|00)?237[\s.-]?[26](?:[\s.-]?\d){8}/g, "[phone]"],
  [/\+\d(?:[\s.-]?\d){7,14}/g, "[phone]"],
  [/\b[26]\d{2}(?:[\s.-]?\d{2,3}){2,3}\b/g, "[phone]"],
  // Contract references: POL-2026-000002, CLM-DEMO-0001, PRP/2026/…, QTE-…, PAY-…, CERT-…
  [/\b(?:POL|CLM|PRP|PROP|QTE|QUO|PAY|CERT|END|REN|INV|RCP)[-/][A-Z0-9][A-Z0-9/-]{2,}\b/gi, "[ref]"],
  [/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/gi, "[uuid]"],
  // National ID / long numeric identifiers.
  [/\b\d{7,}\b/g, "[number]"],
  [/Bearer\s+[A-Za-z0-9._~+/=-]+/gi, "Bearer [token]"],
];

export function scrubString(value: string): string {
  let out = value;
  for (const [re, mask] of PATTERNS) out = out.replace(re, mask);
  return out.length > 1000 ? `${out.slice(0, 1000)}…` : out;
}

/** Strips the query string and masks ids in a URL path. */
export function scrubUrl(url: string): string {
  const [path] = url.split(/[?#]/);
  return scrubString(path ?? "").replace(/\/\d+(?=\/|$)/g, "/:id");
}

export function scrubValue(value: Json, depth = 0): Json {
  if (depth > 8) return "[depth]";
  if (typeof value === "string") return scrubString(value);
  if (Array.isArray(value)) return value.slice(0, 50).map((v) => scrubValue(v, depth + 1));
  if (value && typeof value === "object") {
    const out: Record<string, Json> = {};
    for (const [k, v] of Object.entries(value as Record<string, Json>)) {
      if (SENSITIVE_KEY.test(k)) out[k] = "[filtered]";
      else if (/url|^to$|^from$/i.test(k) && typeof v === "string") out[k] = scrubUrl(v);
      else out[k] = scrubValue(v, depth + 1);
    }
    return out;
  }
  return value;
}

type AnyEvent = Record<string, any>;

/** beforeSend: never send user identity, request bodies, cookies or headers. */
export function scrubEvent<T extends AnyEvent>(event: T): T {
  const e: AnyEvent = { ...event };
  delete e.user;
  delete e.server_name;
  if (e.request) {
    e.request = { method: e.request.method, url: e.request.url ? scrubUrl(String(e.request.url)) : undefined };
  }
  if (typeof e.message === "string") e.message = scrubString(e.message);
  if (e.message && typeof e.message === "object") e.message = scrubValue(e.message);
  if (e.exception?.values) {
    e.exception = {
      ...e.exception,
      values: e.exception.values.map((x: AnyEvent) => ({
        ...x,
        value: typeof x.value === "string" ? scrubString(x.value) : x.value,
        stacktrace: x.stacktrace
          ? {
              ...x.stacktrace,
              frames: (x.stacktrace.frames ?? []).map((f: AnyEvent) => {
                const frame = { ...f };
                delete frame.vars; // local variables can hold KYC/phone values
                return frame;
              }),
            }
          : x.stacktrace,
      })),
    };
  }
  if (e.extra) e.extra = scrubValue(e.extra);
  if (e.contexts) e.contexts = scrubValue(e.contexts);
  if (e.breadcrumbs) e.breadcrumbs = e.breadcrumbs.map((b: AnyEvent) => scrubBreadcrumb(b)).filter(Boolean);
  if (e.tags) e.tags = scrubValue(e.tags);
  return e as T;
}

/** beforeBreadcrumb: keep method/status/masked URL; drop bodies and headers. */
export function scrubBreadcrumb<T extends AnyEvent>(crumb: T | null): T | null {
  if (!crumb) return null;
  const b: AnyEvent = { ...crumb };
  if (typeof b.message === "string") b.message = scrubString(b.message);
  if (b.data) {
    const d = b.data as AnyEvent;
    if (b.category === "xhr" || b.category === "fetch" || b.type === "http") {
      b.data = {
        method: d.method,
        status_code: d.status_code,
        url: typeof d.url === "string" ? scrubUrl(d.url) : undefined,
      };
    } else {
      b.data = scrubValue(d);
    }
  }
  return b as T;
}

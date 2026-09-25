/**
 * Pure helpers for the customer purchase, payment and wallet flows.
 *
 * No imports on purpose: tests/purchase-flow.test.mjs loads this file
 * directly with Node's type stripping, so it must stay dependency-free and
 * use only erasable TypeScript syntax (no enums, namespaces or parameter
 * properties).
 */

// --- Pagination ------------------------------------------------------------

export type PageInfo = {
  page: number;
  lastPage: number;
  total: number | null;
  hasMore: boolean;
};
export type PageResult<T> = { items: T[]; info: PageInfo };

const num = (v: unknown): number | null =>
  typeof v === "number" && Number.isFinite(v)
    ? v
    : typeof v === "string" && v.trim() !== "" && Number.isFinite(Number(v))
      ? Number(v)
      : null;

function pageInfoFrom(
  source: Record<string, unknown> | null | undefined,
  count: number,
): PageInfo {
  const meta = (source ?? {}) as Record<string, unknown>;
  const page = num(meta.current_page) ?? 1;
  const lastPage = num(meta.last_page) ?? page;
  const total = num(meta.total);
  const nextUrl = meta.next_page_url;
  const hasMore =
    typeof nextUrl === "string" ? nextUrl.length > 0 : page < lastPage;
  return { page, lastPage, total: total ?? (hasMore ? null : count), hasMore };
}

/**
 * Accepts every list shape the mobile endpoints have used:
 *  - a bare array (api() already unwrapped `data`)
 *  - the new envelope `{ data: [...], meta: {...} }`
 *  - the old Laravel paginator under data `{ data: { data: [...], current_page, ... } }`
 *  - a bare paginator `{ data: [...], current_page, last_page, ... }`
 * and never throws on anything else (returns an empty page).
 */
export function unwrapPage<T>(payload: unknown): PageResult<T> {
  if (Array.isArray(payload))
    return { items: payload as T[], info: pageInfoFrom(null, payload.length) };
  if (!payload || typeof payload !== "object")
    return { items: [], info: pageInfoFrom(null, 0) };
  const body = payload as Record<string, unknown>;
  const data = body.data;
  if (Array.isArray(data)) {
    const meta =
      body.meta && typeof body.meta === "object"
        ? (body.meta as Record<string, unknown>)
        : body;
    return { items: data as T[], info: pageInfoFrom(meta, data.length) };
  }
  if (data && typeof data === "object") {
    const inner = data as Record<string, unknown>;
    if (Array.isArray(inner.data)) {
      const meta =
        inner.meta && typeof inner.meta === "object"
          ? (inner.meta as Record<string, unknown>)
          : inner;
      return {
        items: inner.data as T[],
        info: pageInfoFrom(meta, inner.data.length),
      };
    }
  }
  return { items: [], info: pageInfoFrom(null, 0) };
}

/** Appends a new page without duplicating ids already on screen. */
export function mergePages<T extends { id: string }>(current: T[], next: T[]) {
  const seen = new Set(current.map((x) => x.id));
  return [...current, ...next.filter((x) => !seen.has(x.id))];
}

// --- Localized values ------------------------------------------------------

/** Catalogue names arrive either as strings or as { en, fr } objects. */
export function localized(value: unknown, language: string = "en"): string {
  if (typeof value === "string") return value;
  if (typeof value === "number") return String(value);
  if (value && typeof value === "object") {
    const v = value as Record<string, unknown>;
    const hit = v[language] ?? v.en ?? v.fr ?? Object.values(v)[0];
    return typeof hit === "string" ? hit : "";
  }
  return "";
}

export const humanize = (code: string | null | undefined) =>
  (code ?? "")
    .toString()
    .replace(/_/g, " ")
    .toLowerCase()
    .replace(/^\w/, (c) => c.toUpperCase());

// --- Offer coverage --------------------------------------------------------

export type CoverageItem = {
  code: string;
  name: string;
  mandatory: boolean;
  optional: boolean;
  limitMinor: number | null;
  deductibleMinor: number | null;
  premiumMinor: number | null;
};
export type OfferDocument = { label: string; url: string };
export type NormalizedCoverage = {
  coverages: CoverageItem[];
  exclusions: { code: string; name: string }[];
  documents: OfferDocument[];
  /** Highest deductible/excess across the offer's coverages (policy excess). */
  excessMinor: number | null;
  /** Sum of limits of the mandatory+included coverages. */
  coverTotalMinor: number;
};

export function normalizeCoverage(
  snapshot: unknown,
  language: string = "en",
): NormalizedCoverage {
  const s =
    snapshot && typeof snapshot === "object"
      ? (snapshot as Record<string, unknown>)
      : {};
  const list = (v: unknown) => (Array.isArray(v) ? v : []);
  const coverages: CoverageItem[] = list(s.coverages).map((raw, i) => {
    const c = (raw ?? {}) as Record<string, unknown>;
    const mandatory = c.mandatory === true || c.mandatory === 1;
    const optional =
      !mandatory && (c.optional === true || c.optional === 1 || c.is_optional === true);
    return {
      code: String(c.code ?? `COVER_${i}`),
      name: localized(c.name, language) || humanize(String(c.code ?? "Cover")),
      mandatory,
      optional,
      limitMinor: num(c.limit_minor),
      deductibleMinor: num(c.deductible_minor ?? c.excess_minor),
      premiumMinor: num(c.premium_minor),
    };
  });
  const exclusions = list(s.exclusions).map((raw, i) => {
    const e = (raw ?? {}) as Record<string, unknown>;
    return {
      code: String(e.code ?? `EXCL_${i}`),
      name: localized(e.name, language) || humanize(String(e.code ?? "")),
    };
  });
  const documents: OfferDocument[] = [
    ...list(s.documents),
    ...list(s.wording_documents),
  ]
    .map((raw) => {
      const d = (raw ?? {}) as Record<string, unknown>;
      const url = d.url ?? d.download_url ?? d.href;
      return {
        label: localized(d.label ?? d.name ?? d.title, language) || "Policy wording",
        url: typeof url === "string" ? url : "",
      };
    })
    .filter((d) => d.url.startsWith("https://") || d.url.startsWith("http://"));
  const wording = s.policy_wording_url ?? s.wording_url;
  if (typeof wording === "string" && /^https?:\/\//.test(wording))
    documents.unshift({ label: "Policy wording", url: wording });
  const explicitExcess = num(s.excess_minor ?? s.deductible_minor);
  const deductibles = coverages
    .map((c) => c.deductibleMinor)
    .filter((v): v is number => v !== null);
  const excessMinor =
    explicitExcess ?? (deductibles.length ? Math.max(...deductibles) : null);
  const coverTotalMinor = coverages
    .filter((c) => !c.optional)
    .reduce((sum, c) => sum + (c.limitMinor ?? 0), 0);
  return { coverages, exclusions, documents, excessMinor, coverTotalMinor };
}

// --- Offer list: sort / filter / compare -----------------------------------

export type OfferLike = {
  id: string;
  carrier_id: string;
  total_minor: number;
  premium_minor: number;
  tax_minor: number;
  fee_minor: number;
  valid_until: string;
  coverage_snapshot: unknown;
  carrier?: { party?: { display_name?: string } } | null;
  product?: { name?: unknown } | null;
};

export const providerName = (o: {
  carrier?: { party?: { display_name?: string } } | null;
}) => o.carrier?.party?.display_name ?? "Licensed insurance carrier";

export type OfferSort = "price" | "cover";
export type OfferFilter = {
  providers?: string[];
  maxPremiumMinor?: number | null;
  maxExcessMinor?: number | null;
};

export function sortOffers<T extends OfferLike>(offers: T[], by: OfferSort): T[] {
  const copy = [...offers];
  if (by === "price")
    return copy.sort((a, b) => a.total_minor - b.total_minor);
  return copy.sort((a, b) => {
    const ca = normalizeCoverage(a.coverage_snapshot);
    const cb = normalizeCoverage(b.coverage_snapshot);
    return (
      cb.coverTotalMinor - ca.coverTotalMinor ||
      cb.coverages.length - ca.coverages.length ||
      a.total_minor - b.total_minor
    );
  });
}

export function filterOffers<T extends OfferLike>(offers: T[], f: OfferFilter): T[] {
  return offers.filter((o) => {
    if (f.providers && f.providers.length && !f.providers.includes(o.carrier_id))
      return false;
    if (f.maxPremiumMinor != null && o.total_minor > f.maxPremiumMinor)
      return false;
    if (f.maxExcessMinor != null) {
      const excess = normalizeCoverage(o.coverage_snapshot).excessMinor;
      if (excess != null && excess > f.maxExcessMinor) return false;
    }
    return true;
  });
}

export type CompareCell = { text: string; minor?: number | null; best?: boolean };
export type CompareRow = { key: string; label: string; cells: CompareCell[] };

/**
 * Builds normalized comparison rows for 2–3 offers: price rows, excess,
 * then one row per coverage code seen in ANY offer (so a cover missing
 * from one insurer shows as "Not included" rather than disappearing).
 */
export function compareRows(offers: OfferLike[], language: string = "en"): CompareRow[] {
  const norm = offers.map((o) => normalizeCoverage(o.coverage_snapshot, language));
  const money = (key: string, label: string, pick: (o: OfferLike, i: number) => number | null, lowerIsBetter = true): CompareRow => {
    const values = offers.map(pick);
    const present = values.filter((v): v is number => v !== null);
    const best = present.length ? (lowerIsBetter ? Math.min(...present) : Math.max(...present)) : null;
    return {
      key,
      label,
      cells: values.map((v) => ({ text: v === null ? "—" : "", minor: v, best: present.length > 1 && v !== null && v === best })),
    };
  };
  const rows: CompareRow[] = [
    { key: "provider", label: "Insurer", cells: offers.map((o) => ({ text: providerName(o) })) },
    { key: "product", label: "Product", cells: offers.map((o) => ({ text: localized(o.product?.name, language) || "Insurance offer" })) },
    money("premium", "Premium", (o) => o.premium_minor),
    money("tax", "Taxes", (o) => o.tax_minor),
    money("fees", "Fees", (o) => o.fee_minor),
    money("total", "Total payable", (o) => o.total_minor),
    money("excess", "Excess / deductible", (_o, i) => norm[i]?.excessMinor ?? null),
  ];
  const codes: { code: string; name: string }[] = [];
  norm.forEach((n) =>
    n.coverages.forEach((c) => {
      if (!codes.some((x) => x.code === c.code)) codes.push({ code: c.code, name: c.name });
    }),
  );
  for (const { code, name } of codes) {
    rows.push({
      key: `cover:${code}`,
      label: name,
      cells: norm.map((n) => {
        const c = n.coverages.find((x) => x.code === code);
        if (!c) return { text: "Not included" };
        const tag = c.mandatory ? "Included" : c.optional ? "Optional" : "Included";
        return { text: tag, minor: c.limitMinor };
      }),
    });
  }
  rows.push({
    key: "exclusions",
    label: "Exclusions",
    cells: norm.map((n) => ({ text: n.exclusions.length ? n.exclusions.map((e) => e.name).join(", ") : "None listed" })),
  });
  return rows;
}

// --- Validity --------------------------------------------------------------

export function validityLeft(validUntil: string | null | undefined, now: number = Date.now()) {
  const end = validUntil ? Date.parse(validUntil) : NaN;
  if (!Number.isFinite(end)) return { expired: false, label: "Validity not stated", ms: null as number | null };
  const ms = end - now;
  if (ms <= 0) return { expired: true, label: "Offer expired", ms: 0 };
  const minutes = Math.floor(ms / 60000);
  const days = Math.floor(minutes / 1440);
  const hours = Math.floor((minutes % 1440) / 60);
  const mins = minutes % 60;
  const label =
    days > 0 ? `Valid for ${days}d ${hours}h` : hours > 0 ? `Valid for ${hours}h ${mins}m` : `Valid for ${Math.max(mins, 1)} min`;
  return { expired: false, label, ms };
}

// --- Statuses --------------------------------------------------------------

export type Tone = "neutral" | "success" | "warning" | "info" | "danger";
export type PolicyBucket = "active" | "pending" | "expired" | "cancelled" | "suspended";

const POLICY: Record<string, { label: string; tone: Tone; bucket: PolicyBucket; claimable: boolean; note?: string }> = {
  ACTIVE: { label: "Active", tone: "success", bucket: "active", claimable: true },
  EXPIRING: { label: "Expiring soon", tone: "warning", bucket: "active", claimable: true, note: "Renew before the end date to stay covered." },
  ENDORSEMENT_PENDING: { label: "Change in review", tone: "info", bucket: "active", claimable: true, note: "A requested change is being reviewed. Your current cover continues meanwhile." },
  CANCELLATION_PENDING: { label: "Cancellation pending", tone: "warning", bucket: "active", claimable: true, note: "Cancellation is being processed. Cover continues until it is confirmed." },
  PENDING_PAYMENT: { label: "Awaiting payment", tone: "warning", bucket: "pending", claimable: false, note: "Cover starts only after payment is confirmed." },
  PAID_PENDING_ISSUANCE: { label: "Issuance in progress", tone: "info", bucket: "pending", claimable: false, note: "Payment received. The insurer is issuing your policy." },
  SUSPENDED: { label: "Suspended", tone: "danger", bucket: "suspended", claimable: false, note: "Cover is suspended. Contact the insurer to restore it." },
  CANCELLED: { label: "Cancelled", tone: "neutral", bucket: "cancelled", claimable: false },
  EXPIRED: { label: "Expired", tone: "neutral", bucket: "expired", claimable: false, note: "Renew to restore cover." },
  LAPSED: { label: "Lapsed", tone: "neutral", bucket: "expired", claimable: false },
};

export function policyStatusInfo(status: string | null | undefined) {
  const key = (status ?? "").toUpperCase();
  return POLICY[key] ?? { label: humanize(key) || "Unknown", tone: "neutral" as Tone, bucket: "pending" as PolicyBucket, claimable: false };
}

export type ProposalStage =
  | "disclosures"
  | "documents"
  | "review"
  | "counteroffer"
  | "declined"
  | "payable"
  | "paid"
  | "closed";

const PROPOSAL: Record<string, { label: string; tone: Tone; stage: ProposalStage; message: string }> = {
  DRAFT: { label: "Draft", tone: "neutral", stage: "disclosures", message: "Answer the insurer's questions to continue." },
  DISCLOSURES_PENDING: { label: "Questions pending", tone: "warning", stage: "disclosures", message: "Answer the insurer's questions to continue." },
  MORE_INFORMATION: { label: "More information needed", tone: "warning", stage: "documents", message: "The insurer needs more information or documents before deciding." },
  DOCUMENTS_PENDING: { label: "Documents needed", tone: "warning", stage: "documents", message: "Upload the requested documents so the insurer can review your application." },
  SUBMITTED: { label: "Submitted", tone: "info", stage: "review", message: "Your application was submitted and is queued for review." },
  UNDER_REVIEW: { label: "Under review", tone: "info", stage: "review", message: "An underwriter is reviewing your application. No payment is requested until it is approved." },
  COUNTEROFFERED: { label: "Revised offer", tone: "warning", stage: "counteroffer", message: "The insurer proposed revised terms. Review them before continuing." },
  DECLINED: { label: "Declined", tone: "danger", stage: "declined", message: "The insurer declined this application. You can compare other offers." },
  APPROVED: { label: "Approved", tone: "success", stage: "payable", message: "Approved. Review the terms and pay to start cover." },
  PAYMENT_PENDING: { label: "Ready to pay", tone: "success", stage: "payable", message: "Approved. Review the terms and pay to start cover." },
  PAID: { label: "Paid", tone: "success", stage: "paid", message: "Payment received. Your policy is being issued." },
  ISSUED: { label: "Policy issued", tone: "success", stage: "paid", message: "Your policy was issued." },
  WITHDRAWN: { label: "Withdrawn", tone: "neutral", stage: "closed", message: "This application was withdrawn." },
  EXPIRED: { label: "Expired", tone: "neutral", stage: "closed", message: "This application expired. Start a new quote." },
};

export function proposalStatusInfo(status: string | null | undefined) {
  const key = (status ?? "").toUpperCase();
  return PROPOSAL[key] ?? { label: humanize(key) || "Unknown", tone: "neutral" as Tone, stage: "review" as ProposalStage, message: "Status is being updated by the insurer." };
}

export function paymentStatusInfo(status: string | null | undefined): { label: string; tone: Tone } {
  switch ((status ?? "").toUpperCase()) {
    case "SUCCEEDED": return { label: "Paid", tone: "success" };
    case "FAILED": return { label: "Failed", tone: "danger" };
    case "EXPIRED": return { label: "Expired", tone: "danger" };
    case "CANCELLED": return { label: "Cancelled", tone: "neutral" };
    case "REFUND_PENDING": return { label: "Refund pending", tone: "warning" };
    case "REFUNDED": return { label: "Refunded", tone: "neutral" };
    case "CREATED": return { label: "Created", tone: "info" };
    case "PENDING_CUSTOMER": return { label: "Awaiting your approval", tone: "warning" };
    case "PROCESSING": return { label: "Processing", tone: "warning" };
    default:
      if ((status ?? "").toUpperCase().startsWith("CHARGEBACK")) return { label: "Disputed", tone: "danger" };
      return { label: humanize(status) || "Unknown", tone: "neutral" };
  }
}

/**
 * Payment stepper position. 0 = Initiated, 1 = Awaiting confirmation,
 * 2 = Confirmed. `failed` marks a terminal failure at any step.
 */
export function purchaseStep(paymentStatus: string | null | undefined, purchaseStatus?: string | null) {
  const p = (paymentStatus ?? "").toUpperCase();
  const agg = (purchaseStatus ?? "").toUpperCase();
  if (agg === "POLICY_ISSUED" || agg === "ISSUANCE_PENDING" || p === "SUCCEEDED")
    return { step: 2, failed: false, done: true };
  if (agg === "PAYMENT_FAILED" || p === "FAILED" || p === "EXPIRED" || p === "CANCELLED")
    return { step: p === "CREATED" || !p ? 0 : 1, failed: true, done: false };
  if (p === "PENDING_CUSTOMER" || p === "PROCESSING" || agg === "PAYMENT_PROCESSING")
    return { step: 1, failed: false, done: false };
  return { step: 0, failed: false, done: false };
}

// --- Errors ----------------------------------------------------------------

type ErrorLike = { status?: number; code?: string; message?: string; fields?: Record<string, unknown> } | null | undefined;

/**
 * Real (non-demo) accounts get 422 with errors.provider[0] (or 503)
 * "payment provider not configured" from POST /payments/{id}/initiate.
 */
export function isProviderNotConfigured(error: unknown): boolean {
  const e = error as ErrorLike;
  if (!e || typeof e !== "object") return false;
  if (e.status === 422 && e.fields && typeof e.fields === "object" && "provider" in e.fields) return true;
  const code = String(e.code ?? "").toUpperCase();
  const message = String(e.message ?? "");
  if (code.includes("PROVIDER_NOT_CONFIGURED") || code.includes("PAYMENT_PROVIDER_UNAVAILABLE")) return true;
  return (e.status === 422 || e.status === 503) && /provider.*not\s+(configured|available)|not\s+configured/i.test(message);
}

/** The server's own wording for the provider problem, when it sent one. */
export function providerErrorMessage(error: unknown): string | null {
  const e = error as ErrorLike;
  const list = e?.fields?.provider;
  const first = Array.isArray(list) ? list[0] : null;
  if (typeof first === "string" && first) return first;
  return typeof e?.message === "string" && e.message ? e.message : null;
}

export function isNotFound(error: unknown) {
  return (error as ErrorLike)?.status === 404;
}

export function errorMessage(error: unknown, fallback: string) {
  // ApiError messages are already localized (incl. 404 and the backend's
  // STALE_RECORD / DUPLICATE_SUBMISSION / ... codes) by src/i18n.
  const api = error as ErrorLike & { name?: string };
  if (api?.name === "ApiError" && api.message) return api.message;
  if (isNotFound(error)) return "This record was not found or is not linked to your account.";
  const m = (error as ErrorLike)?.message;
  return typeof m === "string" && m ? m : fallback;
}

// --- Idempotency -----------------------------------------------------------

/**
 * Identifies one payment attempt: proposal + attempt number + provider +
 * payer. It is only used ON THE DEVICE (as a lookup key in encrypted
 * storage) and is never sent: the payer's phone must not reach request
 * headers, the server idempotency store or its logs.
 */
export function paymentAttemptSlot(proposalId: string, attempt: number, provider: string, phone: string) {
  const digits = phone.replace(/\D/g, "");
  return `${proposalId}:${attempt}:${provider}:${digits}`;
}

/**
 * The Idempotency-Key actually sent: `pay:` + a random UUID that is minted
 * once per attempt slot and persisted, so a double tap, a timeout-then-retry
 * or an app restart re-sends the SAME key and the backend returns the same
 * payment instead of creating a second one. No PII.
 */
export function paymentIdempotencyKey(attemptUuid: string) {
  return `pay:${attemptUuid}`.slice(0, 128);
}

/** Keeps the newest `max` slot → uuid entries (insertion order). */
export function rememberAttemptKey(map: Record<string, string>, slot: string, uuid: string, max = 20) {
  const entries = Object.entries(map).filter(([k]) => k !== slot);
  entries.push([slot, uuid]);
  return Object.fromEntries(entries.slice(-max));
}

// --- Refunds ---------------------------------------------------------------

export const REFUND_REASONS = [
  { code: "COVER_NOT_NEEDED", label: "I no longer need this cover" },
  { code: "DUPLICATE_PAYMENT", label: "I paid twice" },
  { code: "WRONG_AMOUNT", label: "Wrong amount charged" },
  { code: "POLICY_NOT_ISSUED", label: "Policy was not issued" },
  { code: "OTHER", label: "Other reason" },
] as const;

export function refundPayload(input: { reason: string; reasonCode: string; amountMinor: number; idempotencyKey: string }) {
  const reason = input.reason.trim();
  return {
    reason,
    notes: reason,
    reason_code: input.reasonCode,
    amount_minor: Math.max(1, Math.round(input.amountMinor)),
    idempotency_key: input.idempotencyKey,
  };
}

// --- Receipts --------------------------------------------------------------

/** Receipt payload before and after the backend contract change. */
export function receiptView(r: Record<string, unknown> | null | undefined) {
  const x = r ?? {};
  const str = (v: unknown) => (typeof v === "string" && v ? v : null);
  const url = str(x.download_url);
  return {
    number: str(x.receipt_number) ?? str(x.reference) ?? str(x.id) ?? "—",
    issuedAt: str(x.issued_at) ?? str(x.confirmed_at) ?? str(x.requested_at),
    amountMinor: num(x.amount_minor) ?? 0,
    provider: str(x.provider),
    payer: str(x.payer_phone_e164),
    status: str(x.status),
    downloadUrl: url && /^https?:\/\//.test(url) ? url : null,
  };
}

/** Safe https/http URL or null — never hand undefined to Linking.openURL. */
export function openableUrl(v: unknown): string | null {
  return typeof v === "string" && /^https?:\/\//.test(v) ? v : null;
}

// --- Amount input ------------------------------------------------------------

/**
 * Parses a user-typed FCFA amount into minor units, accepting FR and EN
 * formats: "1 000,50", "1.000,50", "1,000.50", "1000.5", "250 000".
 * Returns null for anything that is not a finite, non-negative number, so a
 * caller never submits NaN.
 */
export function parseAmountMinor(input: string): number | null {
  let s = String(input ?? "").replace(/[\s  ]/g, "").replace(/fcfa|xaf/gi, "");
  if (!s || !/^[0-9.,]+$/.test(s)) return null;
  const lastComma = s.lastIndexOf(",");
  const lastDot = s.lastIndexOf(".");
  if (lastComma >= 0 && lastDot >= 0) {
    const decimal = lastComma > lastDot ? "," : ".";
    const thousands = decimal === "," ? "." : ",";
    s = s.split(thousands).join("").replace(decimal, ".");
  } else if (lastComma >= 0 || lastDot >= 0) {
    const sep = lastComma >= 0 ? "," : ".";
    const parts = s.split(sep);
    const tail = parts[parts.length - 1] ?? "";
    // One separator with 1-2 trailing digits is a decimal point; otherwise
    // it groups thousands ("1.000.000", "250,000").
    s = parts.length === 2 && tail.length > 0 && tail.length <= 2 ? `${parts[0]}.${tail}` : parts.join("");
  }
  const value = Number(s);
  if (!Number.isFinite(value) || value < 0) return null;
  return Math.round(value * 100);
}

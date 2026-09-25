/**
 * Pure helpers for the customer purchase, payment and wallet flows.
 *
 * Only the plain EN/FR catalogues are imported: tests/purchase-flow.test.mjs
 * loads this file directly with Node's type stripping, so it must stay
 * dependency-free and use only erasable TypeScript syntax (no enums,
 * namespaces or parameter properties). Labels take an optional language
 * (default "en").
 */
import { en } from "../i18n/en.ts";
import { fr } from "../i18n/fr.ts";

type CopyKey = keyof typeof en;
/** Catalogue lookup for pure helpers (same tables as src/i18n). */
export function copyText(language: string | undefined, key: string, vars?: Record<string, string | number>): string {
  const table = (language === "fr" ? fr : en) as Record<string, string>;
  const text = table[key] ?? (en as Record<string, string>)[key] ?? key;
  return vars ? text.replace(/\{(\w+)\}/g, (m, k: string) => (k in vars ? String(vars[k]) : m)) : text;
}
const tx = (language: string | undefined, key: CopyKey, vars?: Record<string, string | number>) => copyText(language, key, vars);

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
        label: localized(d.label ?? d.name ?? d.title, language) || tx(language, "policyWording"),
        url: typeof url === "string" ? url : "",
      };
    })
    .filter((d) => d.url.startsWith("https://") || d.url.startsWith("http://"));
  const wording = s.policy_wording_url ?? s.wording_url;
  if (typeof wording === "string" && /^https?:\/\//.test(wording))
    documents.unshift({ label: tx(language, "policyWording"), url: wording });
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
  carrier?: { id?: string; party?: { display_name?: string } } | null;
  product?: { name?: unknown } | null;
  status?: string;
};

export const providerName = (o: {
  carrier?: { party?: { display_name?: string } } | null;
}, language?: string) => o.carrier?.party?.display_name ?? tx(language, "licensedCarrier");

export type OfferSort = "price" | "cover" | "insurer" | "excess";
export type CoverLevel = "essential" | "standard" | "full";
export type OfferFilter = {
  providers?: string[];
  minPremiumMinor?: number | null;
  maxPremiumMinor?: number | null;
  maxExcessMinor?: number | null;
  coverLevels?: CoverLevel[];
  paymentMethods?: string[];
  /** Only offers still selectable (status OFFERED / not expired). */
  selectableOnly?: boolean;
};

/** Stable carrier key: carrier_id, else the eager-loaded carrier.id. */
export const carrierKey = (o: { carrier_id?: string | null; carrier?: { id?: string } | null }) =>
  o.carrier_id || o.carrier?.id || "unknown";

const includedCount = (o: OfferLike) =>
  normalizeCoverage(o.coverage_snapshot).coverages.filter((c) => !c.optional).length;

/**
 * Cover level relative to the other offers on the same quote (products of
 * one line are comparable): the widest included cover is "full", at least
 * 60 % of it "standard", otherwise "essential".
 */
export function coverLevel(offer: OfferLike, all: OfferLike[]): CoverLevel {
  const max = Math.max(1, ...all.map(includedCount));
  const ratio = includedCount(offer) / max;
  return ratio >= 0.999 ? "full" : ratio >= 0.6 ? "standard" : "essential";
}

/** Optional fields a future API version may add (see the backend gap note
 * in the 1.3.x report). The UI only offers a filter when data exists. */
export const offerPaymentMethods = (o: unknown): string[] => {
  const x = o as { payment_options?: unknown; payment_methods?: unknown };
  const v = Array.isArray(x?.payment_options) ? x.payment_options : Array.isArray(x?.payment_methods) ? x.payment_methods : [];
  return (v as unknown[]).map((m) => (typeof m === "string" ? m : String((m as { code?: unknown })?.code ?? ""))).filter(Boolean);
};
export const carrierClaimsDays = (o: unknown): number | null => {
  const c = (o as { carrier?: Record<string, unknown> | null })?.carrier;
  const v = c?.claims_settlement_days ?? c?.average_claim_settlement_days;
  return typeof v === "number" && Number.isFinite(v) ? v : null;
};
export const carrierRating = (o: unknown): string | null => {
  const c = (o as { carrier?: Record<string, unknown> | null })?.carrier;
  const v = c?.financial_rating ?? c?.rating;
  return typeof v === "string" && v.trim() ? v.trim() : typeof v === "number" ? String(v) : null;
};

export function sortOffers<T extends OfferLike>(offers: T[], by: OfferSort): T[] {
  const copy = [...offers];
  if (by === "price")
    return copy.sort((a, b) => a.total_minor - b.total_minor);
  if (by === "insurer")
    return copy.sort((a, b) => providerName(a).localeCompare(providerName(b)) || a.total_minor - b.total_minor);
  if (by === "excess")
    return copy.sort((a, b) => {
      const ea = normalizeCoverage(a.coverage_snapshot).excessMinor ?? Number.MAX_SAFE_INTEGER;
      const eb = normalizeCoverage(b.coverage_snapshot).excessMinor ?? Number.MAX_SAFE_INTEGER;
      return ea - eb || a.total_minor - b.total_minor;
    });
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
    if (f.providers && f.providers.length && !f.providers.includes(carrierKey(o)))
      return false;
    if (f.minPremiumMinor != null && o.total_minor < f.minPremiumMinor)
      return false;
    if (f.maxPremiumMinor != null && o.total_minor > f.maxPremiumMinor)
      return false;
    if (f.maxExcessMinor != null) {
      const excess = normalizeCoverage(o.coverage_snapshot).excessMinor;
      if (excess != null && excess > f.maxExcessMinor) return false;
    }
    if (f.coverLevels && f.coverLevels.length && !f.coverLevels.includes(coverLevel(o, offers)))
      return false;
    if (f.paymentMethods && f.paymentMethods.length) {
      const methods = offerPaymentMethods(o);
      if (!f.paymentMethods.some((m) => methods.includes(m))) return false;
    }
    if (f.selectableOnly) {
      const status = String((o as { status?: unknown }).status ?? "OFFERED").toUpperCase();
      if (status !== "OFFERED") return false;
    }
    return true;
  });
}

/** One row per insurer (cheapest offer each): proves every insurer that
 * answered is on screen, not only the first card of a carousel. */
export function insurerSummary<T extends OfferLike>(offers: T[]): { carrierId: string; name: string; offers: number; cheapest: T }[] {
  const map = new Map<string, { carrierId: string; name: string; offers: number; cheapest: T }>();
  for (const o of offers) {
    const key = carrierKey(o);
    const row = map.get(key);
    if (!row) map.set(key, { carrierId: key, name: providerName(o), offers: 1, cheapest: o });
    else {
      row.offers += 1;
      if (o.total_minor < row.cheapest.total_minor) row.cheapest = o;
    }
  }
  return [...map.values()].sort((a, b) => a.cheapest.total_minor - b.cheapest.total_minor);
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
    { key: "provider", label: tx(language, "ofInsurer"), cells: offers.map((o) => ({ text: providerName(o, language) })) },
    { key: "product", label: tx(language, "cfProduct"), cells: offers.map((o) => ({ text: localized(o.product?.name, language) || tx(language, "insuranceOffer") })) },
    money("premium", tx(language, "sumPremium"), (o) => o.premium_minor),
    money("tax", tx(language, "sumTaxes"), (o) => o.tax_minor),
    money("fees", tx(language, "sumFees"), (o) => o.fee_minor),
    money("total", tx(language, "sumTotalPayable"), (o) => o.total_minor),
    money("excess", tx(language, "sumExcess"), (_o, i) => norm[i]?.excessMinor ?? null),
    {
      key: "level",
      label: tx(language, "ofCoverLevel"),
      cells: offers.map((o) => ({ text: tx(language, ({ essential: "ofLevelEssential", standard: "ofLevelStandard", full: "ofLevelFull" } as const)[coverLevel(o, offers)]) })),
    },
  ];
  // Only when the API supplies them (see the backend gap note).
  if (offers.some((o) => carrierRating(o)))
    rows.push({ key: "rating", label: tx(language, "cmpRowRating"), cells: offers.map((o) => ({ text: carrierRating(o) ?? "—" })) });
  if (offers.some((o) => carrierClaimsDays(o) !== null))
    rows.push({ key: "claims_days", label: tx(language, "cmpRowClaimsDays"), cells: offers.map((o) => { const d = carrierClaimsDays(o); return { text: d === null ? "—" : tx(language, "cmpDays", { count: d }) }; }) });
  if (offers.some((o) => offerPaymentMethods(o).length))
    rows.push({ key: "payment", label: tx(language, "ofPaymentOptions"), cells: offers.map((o) => ({ text: offerPaymentMethods(o).join(", ") || "—" })) });
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
        if (!c) return { text: tx(language, "cmpNotIncluded") };
        const tag = c.optional && !c.mandatory ? tx(language, "cmpOptional") : tx(language, "sumIncluded");
        return { text: tag, minor: c.limitMinor };
      }),
    });
  }
  rows.push({
    key: "exclusions",
    label: tx(language, "cmpRowExclusions"),
    cells: norm.map((n) => ({ text: n.exclusions.length ? n.exclusions.map((e) => e.name).join(", ") : tx(language, "cmpNoneListed") })),
  });
  return rows;
}

// --- Validity --------------------------------------------------------------

export function validityLeft(validUntil: string | null | undefined, now: number = Date.now(), language?: string) {
  const end = validUntil ? Date.parse(validUntil) : NaN;
  if (!Number.isFinite(end)) return { expired: false, label: tx(language, "validityNotStated"), ms: null as number | null };
  const ms = end - now;
  if (ms <= 0) return { expired: true, label: tx(language, "offerExpired"), ms: 0 };
  const minutes = Math.floor(ms / 60000);
  const days = Math.floor(minutes / 1440);
  const hours = Math.floor((minutes % 1440) / 60);
  const mins = minutes % 60;
  const label =
    days > 0 ? tx(language, "validityDays", { days, hours }) : hours > 0 ? tx(language, "validityHours", { hours, mins }) : tx(language, "validityMinutes", { mins: Math.max(mins, 1) });
  return { expired: false, label, ms };
}

// --- Statuses --------------------------------------------------------------

export type Tone = "neutral" | "success" | "warning" | "info" | "danger";
export type PolicyBucket = "active" | "pending" | "expired" | "cancelled" | "suspended";

const POLICY: Record<string, { tone: Tone; bucket: PolicyBucket; claimable: boolean; note?: boolean }> = {
  ACTIVE: { tone: "success", bucket: "active", claimable: true },
  EXPIRING: { tone: "warning", bucket: "active", claimable: true, note: true },
  ENDORSEMENT_PENDING: { tone: "info", bucket: "active", claimable: true, note: true },
  CANCELLATION_PENDING: { tone: "warning", bucket: "active", claimable: true, note: true },
  PENDING_PAYMENT: { tone: "warning", bucket: "pending", claimable: false, note: true },
  PAID_PENDING_ISSUANCE: { tone: "info", bucket: "pending", claimable: false, note: true },
  SUSPENDED: { tone: "danger", bucket: "suspended", claimable: false, note: true },
  CANCELLED: { tone: "neutral", bucket: "cancelled", claimable: false },
  EXPIRED: { tone: "neutral", bucket: "expired", claimable: false, note: true },
  LAPSED: { tone: "neutral", bucket: "expired", claimable: false },
};

/** Label (catalogue policyStatus_*), tone, bucket and customer note (policyNote_*). */
export function policyStatusInfo(status: string | null | undefined, language?: string) {
  const key = (status ?? "").toUpperCase();
  const known = POLICY[key];
  if (!known) return { label: humanize(key) || tx(language, "statusUnknown"), tone: "neutral" as Tone, bucket: "pending" as PolicyBucket, claimable: false, note: undefined as string | undefined };
  return {
    label: copyText(language, `policyStatus_${key}`),
    tone: known.tone,
    bucket: known.bucket,
    claimable: known.claimable,
    note: known.note ? copyText(language, `policyNote_${key}`) : undefined,
  };
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

const PROPOSAL: Record<string, { tone: Tone; stage: ProposalStage }> = {
  DRAFT: { tone: "neutral", stage: "disclosures" },
  DISCLOSURES_PENDING: { tone: "warning", stage: "disclosures" },
  MORE_INFORMATION: { tone: "warning", stage: "documents" },
  DOCUMENTS_PENDING: { tone: "warning", stage: "documents" },
  SUBMITTED: { tone: "info", stage: "review" },
  UNDER_REVIEW: { tone: "info", stage: "review" },
  COUNTEROFFERED: { tone: "warning", stage: "counteroffer" },
  DECLINED: { tone: "danger", stage: "declined" },
  APPROVED: { tone: "success", stage: "payable" },
  PAYMENT_PENDING: { tone: "success", stage: "payable" },
  PAID: { tone: "success", stage: "paid" },
  ISSUED: { tone: "success", stage: "paid" },
  WITHDRAWN: { tone: "neutral", stage: "closed" },
  EXPIRED: { tone: "neutral", stage: "closed" },
};

/** Label (proposalStatus_*), tone, stage and message (proposalMsg_*). */
export function proposalStatusInfo(status: string | null | undefined, language?: string) {
  const key = (status ?? "").toUpperCase();
  const known = PROPOSAL[key];
  if (!known) return { label: humanize(key) || tx(language, "statusUnknown"), tone: "neutral" as Tone, stage: "review" as ProposalStage, message: tx(language, "proposalMsg_UNKNOWN") };
  return { label: copyText(language, `proposalStatus_${key}`), tone: known.tone, stage: known.stage, message: copyText(language, `proposalMsg_${key}`) };
}

const PAYMENT: Record<string, Tone> = {
  SUCCEEDED: "success",
  FAILED: "danger",
  EXPIRED: "danger",
  CANCELLED: "neutral",
  REFUND_PENDING: "warning",
  REFUNDED: "neutral",
  CREATED: "info",
  PENDING_CUSTOMER: "warning",
  PROCESSING: "warning",
};

/** Label (paymentStatus_*) and tone; chargebacks read as disputed. */
export function paymentStatusInfo(status: string | null | undefined, language?: string): { label: string; tone: Tone } {
  const key = (status ?? "").toUpperCase();
  if (PAYMENT[key]) return { label: copyText(language, `paymentStatus_${key}`), tone: PAYMENT[key] };
  if (key.startsWith("CHARGEBACK")) return { label: tx(language, "paymentStatus_DISPUTED"), tone: "danger" };
  return { label: humanize(status) || tx(language, "statusUnknown"), tone: "neutral" };
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

export function errorMessage(error: unknown, fallback: string, language?: string) {
  // ApiError messages are already localized (incl. 404 and the backend's
  // STALE_RECORD / DUPLICATE_SUBMISSION / ... codes) by src/i18n.
  const api = error as ErrorLike & { name?: string };
  if (api?.name === "ApiError" && api.message) return api.message;
  if (isNotFound(error)) return tx(language, "errNotFound");
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

/**
 * Choosing among a quote's offers (customer offers + comparison screens): what each offer's
 * choose button does, the compare selection (at most three), the accepted offer and the
 * application it opened, amount filters, the cover period suffix and the "Edit quote" route.
 * Pure and dependency-free apart from purchase.ts (node-tested in tests/offer-compare.test.mjs).
 */
import { offerBlock, parseAmountMinor, type OfferBlock, type OfferFilter } from "./purchase.ts";

export const MAX_COMPARE = 3;

type Choosable = { id: string; status?: string | null; valid_until?: string | null };

// --- Choose affordance ----------------------------------------------------------

export type OfferChoice =
  | { kind: "select" }
  /** The accepted offer: its button opens the application. */
  | { kind: "selected" }
  | { kind: "blocked"; reason: OfferBlock | "other_chosen" };

/**
 * One rule for every choose button (offer cards and comparison columns): a quote that ended
 * (declined, cancelled, expired) blocks every offer; once an offer is accepted it is the only one
 * that stays active (it opens its application) and the others are blocked.
 */
export function offerChoice(offer: Choosable, ctx: { acceptedId?: string | null; outcome?: string | null; now?: number }): OfferChoice {
  const now = ctx.now ?? Date.now();
  if (ctx.acceptedId) return offer.id === ctx.acceptedId ? { kind: "selected" } : { kind: "blocked", reason: "other_chosen" };
  const outcome = String(ctx.outcome ?? "").toUpperCase();
  if (outcome === "EXPIRED") return { kind: "blocked", reason: "expired" };
  if (outcome === "DECLINED" || outcome === "CANCELLED") return { kind: "blocked", reason: "unavailable" };
  const block = offerBlock(offer, now);
  return block ? { kind: "blocked", reason: block } : { kind: "select" };
}

/** Accepted offer of the quote from the server state (offer status ACCEPTED). */
export function acceptedOfferId(offers: Choosable[] | null | undefined): string | null {
  return (offers ?? []).find((o) => String(o.status ?? "").toUpperCase() === "ACCEPTED")?.id ?? null;
}

// --- Compare selection ------------------------------------------------------------

/** "a,b,,a" -> ["a", "b"]. */
export function parseIds(raw: string | string[] | null | undefined): string[] {
  const text = Array.isArray(raw) ? raw.join(",") : String(raw ?? "");
  return [...new Set(text.split(",").map((x) => x.trim()).filter(Boolean))];
}

/**
 * Keeps only ids of offers that are still on screen and choosable (after a re-rate, a filter or an
 * expiry), capped at MAX_COMPARE. Returns the SAME array when nothing changed (safe in effects).
 */
export function pruneCompareIds(ids: string[], visible: Choosable[], now: number = Date.now()): string[] {
  const ok = new Set(visible.filter((o) => !offerBlock(o, now)).map((o) => o.id));
  const next = ids.filter((id) => ok.has(id)).slice(0, MAX_COMPARE);
  return next.length === ids.length ? ids : next;
}

/** Tick / untick; a tick past the maximum is refused with `limited` so the screen can say why. */
export function toggleCompareId(ids: string[], id: string, max: number = MAX_COMPARE): { ids: string[]; limited: boolean } {
  if (ids.includes(id)) return { ids: ids.filter((x) => x !== id), limited: false };
  if (ids.length >= max) return { ids, limited: true };
  return { ids: [...ids, id], limited: false };
}

/** "Compare all": the cheapest choosable offers, at most MAX_COMPARE. */
export function compareAllIds<T extends Choosable & { total_minor: number }>(visible: T[], now: number = Date.now(), max: number = MAX_COMPARE): string[] {
  return visible
    .filter((o) => !offerBlock(o, now))
    .sort((a, b) => a.total_minor - b.total_minor)
    .slice(0, max)
    .map((o) => o.id);
}

// --- Applications --------------------------------------------------------------------

export type ProposalRef = {
  id: string;
  status?: string | null;
  quote_id?: string | null;
  quote_offer_id?: string | null;
  offer?: { id?: string | null; quote_id?: string | null; quote?: { id?: string | null } | null } | null;
  terms_snapshot?: { offer_id?: string | null; quote_id?: string | null } | null;
};

/** The (non-withdrawn) application opened for an offer, from GET /mobile/proposals rows. */
export function proposalForOffer(list: ProposalRef[] | null | undefined, offerId: string): string | null {
  const hit = (list ?? []).find((p) => {
    if (String(p.status ?? "").toUpperCase() === "WITHDRAWN") return false;
    return p.quote_offer_id === offerId || p.offer?.id === offerId || p.terms_snapshot?.offer_id === offerId;
  });
  return hit?.id ?? null;
}

/** Quote an application was made from (list row quote_id, terms snapshot, or the eager-loaded offer). */
export function proposalQuoteId(p: ProposalRef | null | undefined): string | null {
  if (!p) return null;
  return p.quote_id || p.terms_snapshot?.quote_id || p.offer?.quote_id || p.offer?.quote?.id || null;
}

// --- Amount filters (sheet draft) --------------------------------------------------------

/** FilterValues keys of the amount inputs in the offers filter sheet (raw typed text). */
export const AMOUNT_KEYS = ["minTotal", "maxTotal", "maxExcess"] as const;
type Values = Record<string, string[] | undefined>;
const raw = (v: Values, k: string) => (v[k]?.[0] ?? "").trim();

/** Typed text -> minor units with FR/EN separators ("12,5", "250 000", "1.000,50"); null when blank or invalid. */
export const amountMinor = (text: string): number | null => (text.trim() ? parseAmountMinor(text) : null);

export function amountFilter(v: Values): Pick<OfferFilter, "minPremiumMinor" | "maxPremiumMinor" | "maxExcessMinor"> {
  return { minPremiumMinor: amountMinor(raw(v, "minTotal")), maxPremiumMinor: amountMinor(raw(v, "maxTotal")), maxExcessMinor: amountMinor(raw(v, "maxExcess")) };
}

/** Amount inputs that actually filter (valid numbers). */
export const amountFilterCount = (v: Values) => AMOUNT_KEYS.filter((k) => amountMinor(raw(v, k)) !== null).length;

/** Text was typed but is not an amount (shown as a field error; the filter ignores it). */
export const amountInvalid = (text: string | undefined) => !!text?.trim() && parseAmountMinor(text) === null;

// --- Cover period ------------------------------------------------------------------------

export type CoverPeriod = { unit: "year" | "month" | "week" | "day"; count: number };

const UNITS: Record<string, CoverPeriod["unit"]> = { Y: "year", YEAR: "year", YEARS: "year", M: "month", MONTH: "month", MONTHS: "month", W: "week", WEEK: "week", WEEKS: "week", D: "day", DAY: "day", DAYS: "day" };
const NAMED: Record<string, CoverPeriod> = {
  ANNUAL: { unit: "year", count: 1 },
  YEARLY: { unit: "year", count: 1 },
  SEMI_ANNUAL: { unit: "month", count: 6 },
  QUARTERLY: { unit: "month", count: 3 },
  MONTHLY: { unit: "month", count: 1 },
  WEEKLY: { unit: "week", count: 1 },
  DAILY: { unit: "day", count: 1 },
};

function periodOf(v: unknown): CoverPeriod | null {
  if (v && typeof v === "object") {
    const o = v as Record<string, unknown>;
    const unit = UNITS[String(o.unit ?? "").toUpperCase()];
    const count = Number(o.value ?? o.count ?? 1);
    return unit && Number.isFinite(count) && count > 0 ? { unit, count } : null;
  }
  if (typeof v !== "string" || !v.trim()) return null;
  const s = v.trim().toUpperCase();
  if (NAMED[s]) return NAMED[s];
  const iso = /^P(\d+)([YMWD])$/.exec(s);
  if (iso) return { unit: UNITS[iso[2] as string] as CoverPeriod["unit"], count: Number(iso[1]) };
  return null;
}

const positive = (v: unknown) => (typeof v === "number" && Number.isFinite(v) && v > 0 ? v : null);

/**
 * Cover period of an offer when the payload states one (coverage snapshot or product: cover_period /
 * coverage_period / term / duration, or *_months / *_days). Null when unknown: the UI then shows the
 * price without a "per year" suffix instead of assuming one.
 */
export function offerPeriod(offer: { coverage_snapshot?: unknown; product?: unknown } | null | undefined): CoverPeriod | null {
  for (const src of [offer?.coverage_snapshot, offer?.product]) {
    if (!src || typeof src !== "object") continue;
    const o = src as Record<string, unknown>;
    for (const k of ["cover_period", "coverage_period", "term", "duration", "period"]) {
      const p = periodOf(o[k]);
      if (p) return p;
    }
    const months = positive(o.term_months) ?? positive(o.duration_months) ?? positive(o.default_term_months);
    if (months) return { unit: "month", count: months };
    const days = positive(o.duration_days) ?? positive(o.term_days);
    if (days) return { unit: "day", count: days };
  }
  return null;
}

/** i18n key + vars for the price suffix ("per year", "for 6 months"). */
export function periodCopy(p: CoverPeriod | null): { key: string; vars: Record<string, number> } | null {
  if (!p) return null;
  if ((p.unit === "year" && p.count === 1) || (p.unit === "month" && p.count === 12)) return { key: "ofPeriodPerYear", vars: {} };
  if (p.unit === "month" && p.count === 1) return { key: "ofPeriodPerMonth", vars: {} };
  const key = { year: "ofPeriodYears", month: "ofPeriodMonths", week: "ofPeriodWeeks", day: "ofPeriodDays" }[p.unit];
  return { key, vars: { count: p.count } };
}

// --- "Edit quote" -----------------------------------------------------------------------

const QUOTE_FLOW = ["quote/offers", "quote/compare"];

/**
 * How "Edit quote" leaves the offers / comparison screens (root stack route names, top = index):
 * back to the risk form when it sits right under them, otherwise dismiss down to the lowest
 * offers/compare screen and replace it with a prefilled risk form. No stale offers screen stays
 * underneath either way.
 */
export function editQuotePlan(routeNames: string[], index: number): { dismiss: number; action: "back" | "replace" } {
  let i = index;
  while (i >= 0 && QUOTE_FLOW.includes(routeNames[i] ?? "")) i--;
  const flow = Math.max(1, index - i);
  if (i >= 0 && routeNames[i] === "quote/risk") return { dismiss: flow, action: "back" };
  return { dismiss: flow - 1, action: "replace" };
}

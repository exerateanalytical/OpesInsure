/**
 * Pure helpers + an in-memory hand-off for the 4-step renewal journey
 * (Quote → Compare → Review → Payment). The renewal quote comes from
 * POST /policies/{id}/renewal-quote (PolicyApi.renewalQuote) and is the same
 * {quote, offers} shape as the Compare flow, so the insurance store keeps
 * quote/offers (setQuoteResult) and this module only remembers which policy
 * the quote belongs to and what the customer picked on the way.
 * Nothing here invents data: discounts, ratings and add-ons are only shown
 * when the payload carries them.
 */
import type { Policy, QuoteOffer, QuoteResult, WalletPolicy } from "@/api/client";
import { carrierKey, normalizeCoverage, type OfferLike } from "@/lib/purchase";

export type RenewalPolicy = Policy & Partial<Pick<WalletPolicy, "carrier_name" | "product_name" | "insured_object" | "risk_asset">>;

export type RenewalContext = {
  policyId: string;
  policy: RenewalPolicy;
  result: QuoteResult | null;
  selectedOfferId: string | null;
  /** Optional coverage codes the customer ticked on the quote step (priced by the insurer, never on the device). */
  addons: string[];
};

let current: RenewalContext | null = null;

/** Hand-off between the renewal screens (survives navigation, not an app restart: each screen can reload from the API). */
export const RenewalFlow = {
  get(policyId: string | undefined): RenewalContext | null {
    return current && policyId && current.policyId === policyId ? current : null;
  },
  start(policy: RenewalPolicy, result: QuoteResult | null): RenewalContext {
    const keep = current?.policyId === policy.id ? current : null;
    current = { policyId: policy.id, policy, result, selectedOfferId: keep?.selectedOfferId ?? null, addons: keep?.addons ?? [] };
    return current;
  },
  update(patch: Partial<Omit<RenewalContext, "policyId">>) {
    if (current) current = { ...current, ...patch };
    return current;
  },
  clear() {
    current = null;
  },
};

const num = (v: unknown): number | null =>
  typeof v === "number" && Number.isFinite(v) ? v : typeof v === "string" && v.trim() !== "" && Number.isFinite(Number(v)) ? Number(v) : null;

/** Whole days from `now` until the ISO date (negative when already past). */
export function daysUntil(iso: string | null | undefined, now: number = Date.now()): number | null {
  const end = iso ? Date.parse(iso) : NaN;
  if (!Number.isFinite(end)) return null;
  return Math.ceil((end - now) / 86_400_000);
}

/** New policy period: starts when the current cover ends, runs 12 months. */
export function renewalPeriod(coverageEndsAt: string | null | undefined): { start: string; end: string } | null {
  const t = coverageEndsAt ? Date.parse(coverageEndsAt) : NaN;
  if (!Number.isFinite(t)) return null;
  const end = new Date(t);
  end.setUTCFullYear(end.getUTCFullYear() + 1);
  return { start: new Date(t).toISOString(), end: end.toISOString() };
}

/**
 * Discount carried by the renewal offer, if the API sends one. Accepted
 * shapes: offer.discount_minor, offer.loyalty_discount_minor,
 * offer.discount{amount_minor|minor, percent|rate, label},
 * coverage_snapshot.discount_minor / .no_claim_discount_minor / .discount{…}.
 * Returns null when no such field exists (the UI then omits the row).
 */
export function offerDiscount(offer: unknown): { minor: number; percent: number | null; label: string | null } | null {
  const o = (offer ?? {}) as Record<string, unknown>;
  const snap = (o.coverage_snapshot && typeof o.coverage_snapshot === "object" ? o.coverage_snapshot : {}) as Record<string, unknown>;
  const candidates: unknown[] = [o.discount_minor, o.loyalty_discount_minor, o.no_claim_discount_minor, snap.discount_minor, snap.loyalty_discount_minor, snap.no_claim_discount_minor];
  const objects = [o.discount, snap.discount, o.no_claim_bonus, snap.no_claim_bonus].filter((d) => d && typeof d === "object") as Record<string, unknown>[];
  let minor: number | null = null;
  for (const c of candidates) {
    const v = num(c);
    if (v !== null && v > 0) {
      minor = v;
      break;
    }
  }
  let percent: number | null = null;
  let label: string | null = null;
  for (const d of objects) {
    if (minor === null) {
      const v = num(d.amount_minor ?? d.minor ?? d.value_minor);
      if (v !== null && v > 0) minor = v;
    }
    if (percent === null) {
      const p = num(d.percent ?? d.percentage ?? d.rate_percent);
      const r = num(d.rate);
      percent = p ?? (r !== null && r > 0 && r <= 1 ? Math.round(r * 100) : r);
    }
    if (!label && typeof d.label === "string" && d.label.trim()) label = d.label.trim();
  }
  if (percent === null) percent = num(o.discount_percent ?? snap.discount_percent);
  if (minor === null || minor <= 0) return null;
  return { minor, percent, label };
}

/** Insurer logo when the eager-loaded carrier row carries one (logo_url / party.logo_url). */
export function carrierLogo(offer: unknown): string | null {
  const c = (offer as { carrier?: Record<string, unknown> | null })?.carrier;
  const party = (c?.party ?? {}) as Record<string, unknown>;
  const v = c?.logo_url ?? c?.logo ?? party.logo_url ?? party.logo;
  return typeof v === "string" && /^https:\/\//i.test(v) ? v : null;
}

/** Vehicle / insured object line: "Toyota RAV4 · BXX 123 CD" from the wallet policy or the quote's risk facts. */
export function insuredObjectLabel(policy: RenewalPolicy | null | undefined, riskFacts?: Record<string, unknown> | null): string | null {
  const p = policy ?? ({} as RenewalPolicy);
  if (typeof p.insured_object === "string" && p.insured_object.trim()) return p.insured_object;
  if (p.insured_object && typeof p.insured_object === "object") {
    const o = p.insured_object as Record<string, unknown>;
    const s = [o.label, o.make, o.model, o.registration_number].filter((x): x is string => typeof x === "string" && !!x);
    if (s.length) return s.join(" · ");
  }
  if (p.risk_asset) {
    const s = [p.risk_asset.label, p.risk_asset.registration_number].filter((x): x is string => typeof x === "string" && !!x);
    if (s.length) return s.join(" · ");
  }
  return riskFactsLabel(riskFacts ?? p.terms_snapshot?.risk_facts);
}

/** Insured-object line from any product line's risk facts: vehicle first, then property / trip / person facts. */
export function riskFactsLabel(riskFacts: Record<string, unknown> | null | undefined): string | null {
  const vehicle = riskVehicleLabel(riskFacts);
  if (vehicle) return vehicle;
  const r = riskFacts ?? {};
  const str = (k: string) => (typeof r[k] === "string" && (r[k] as string).trim() ? (r[k] as string).trim() : null);
  const words = (v: string | null) => (v ? v.toLowerCase().replace(/_/g, " ").replace(/^\w/, (c) => c.toUpperCase()) : null);
  const place = (v: string | null) => (v ? v.replace(/\w\S*/g, (w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()) : null);
  const out = [
    words(str("property_type") ?? str("occupancy")),
    place(str("city") ?? str("address_line") ?? str("location")),
    place(str("destination_country") ?? str("destination")),
    str("insured_name") ?? str("business_name") ?? str("company_name"),
  ].filter(Boolean);
  return out.length ? out.join(" · ") : null;
}

/** Vehicle line from quote risk facts (make/model/registration), null when they are not vehicle facts. */
export function riskVehicleLabel(riskFacts: Record<string, unknown> | null | undefined): string | null {
  const r = riskFacts ?? {};
  const str = (k: string) => (typeof r[k] === "string" && (r[k] as string).trim() ? (r[k] as string).trim() : null);
  const name = [str("vehicle_make") ?? str("make"), str("vehicle_model") ?? str("model")].filter(Boolean).join(" ");
  const plate = str("registration_number") ?? str("plate_number");
  const out = [name || null, plate].filter(Boolean);
  return out.length ? out.join(" · ") : null;
}

/** The renewal offer from the policy's current insurer (falls back to the first/cheapest offer). */
export function sameCarrierOffer<T extends OfferLike>(offers: T[], carrierId: string | null | undefined): T | null {
  if (!offers.length) return null;
  const same = carrierId ? offers.find((o) => carrierKey(o) === carrierId) : null;
  return same ?? [...offers].sort((a, b) => a.total_minor - b.total_minor)[0] ?? null;
}

export type RenewalSort = "price" | "same_cover" | "best_value";

const includedCodes = (o: OfferLike) => new Set(normalizeCoverage(o.coverage_snapshot).coverages.filter((c) => !c.optional).map((c) => c.code));

/** Cover value per franc: sum of included limits divided by the yearly price (0 when limits are not stated). */
export function valueScore(o: OfferLike): number {
  const n = normalizeCoverage(o.coverage_snapshot);
  return o.total_minor > 0 ? n.coverTotalMinor / o.total_minor : 0;
}

/**
 * Renewal comparison ordering. "Same cover" ranks offers by how many of the
 * current insurer's included covers they also include (then price); "best
 * value" by cover per franc (then price); "price" by yearly total.
 */
export function sortRenewalOffers<T extends OfferLike>(offers: T[], by: RenewalSort, currentCarrierId: string | null | undefined): T[] {
  const copy = [...offers];
  if (by === "price") return copy.sort((a, b) => a.total_minor - b.total_minor);
  if (by === "best_value") return copy.sort((a, b) => valueScore(b) - valueScore(a) || a.total_minor - b.total_minor);
  const base = sameCarrierOffer(offers, currentCarrierId);
  const ref = base ? includedCodes(base) : new Set<string>();
  const overlap = (o: T) => {
    const codes = includedCodes(o);
    let n = 0;
    ref.forEach((c) => {
      if (codes.has(c)) n += 1;
    });
    return n;
  };
  return copy.sort((a, b) => overlap(b) - overlap(a) || a.total_minor - b.total_minor);
}

/** Id of the "best value" offer (highest cover per franc; only when limits are stated on at least one offer). */
export function bestValueOfferId(offers: OfferLike[]): string | null {
  const scored = offers.map((o) => ({ id: o.id, score: valueScore(o) })).filter((x) => x.score > 0);
  if (!scored.length) return null;
  return scored.sort((a, b) => b.score - a.score)[0]?.id ?? null;
}

/** Optional coverages of an offer (never invented: empty when the snapshot has none). */
export function optionalAddons(offer: QuoteOffer | null | undefined, language?: string) {
  if (!offer) return [];
  return normalizeCoverage(offer.coverage_snapshot, language).coverages.filter((c) => c.optional);
}

/**
 * The ONE offer comparison (REQ-DST-003). The server comparison (POST quote-comparisons) is the
 * source; comparisonFromOffers() builds the same shape from the rated offers when that call fails
 * or the device is offline. comparisonTable() turns either into the table: header columns, price
 * rows (lowest live value highlighted), validity, cover period, then limits, deductibles and
 * exclusions per cover. Pure (node-tested in tests/offer-compare.test.mjs).
 */
import {
  carrierClaimsDays,
  carrierKey,
  carrierRating,
  copyText,
  coverLevel,
  localized,
  normalizeCoverage,
  offerBlock,
  offerPaymentMethods,
  paymentMethodLabel,
  providerName,
  type OfferBlock,
  type OfferLike,
} from "./purchase.ts";
import { offerPeriod, periodCopy } from "./offerChoice.ts";
import type { QuoteComparison } from "./quoteWorkflow.ts";

type RatedOffer = OfferLike & { product_id?: string | null; carrier_logo_url?: string | null };

/** Requested cover start in a quote's risk facts, when the questionnaire asked for one. */
export function coverStartOf(riskFacts: Record<string, unknown> | null | undefined): string | null {
  const f = riskFacts ?? {};
  for (const k of ["start_date", "cover_start_date", "coverage_start_date", "departure_date"]) {
    const v = f[k];
    if (typeof v === "string" && Number.isFinite(Date.parse(v))) return v;
  }
  return null;
}

/** Client fallback: the POST quote-comparisons shape from each offer's coverage snapshot. */
export function comparisonFromOffers(quoteId: string, offers: RatedOffer[], language: string = "en"): QuoteComparison {
  const norm = offers.map((o) => normalizeCoverage(o.coverage_snapshot, language));
  const covers: { code: string; name: string }[] = [];
  const exclusions: { code: string; name: string }[] = [];
  norm.forEach((n) => {
    n.coverages.forEach((c) => covers.some((x) => x.code === c.code) || covers.push({ code: c.code, name: c.name }));
    n.exclusions.forEach((e) => exclusions.some((x) => x.code === e.code) || exclusions.push({ code: e.code, name: e.name }));
  });
  const lowest = offers.length ? offers.reduce((a, b) => (b.total_minor < a.total_minor ? b : a)) : null;
  return {
    id: "local",
    quote_id: quoteId,
    offers: offers.map((o) => ({
      offer_id: o.id,
      carrier: o.carrier?.party?.display_name ?? null,
      carrier_id: carrierKey(o),
      carrier_logo_url: o.carrier_logo_url ?? null,
      product: o.product?.name ?? null,
      product_id: o.product_id ?? null,
      premium_minor: o.premium_minor,
      tax_minor: o.tax_minor,
      fee_minor: o.fee_minor,
      total_minor: o.total_minor,
      valid_until: o.valid_until ?? null,
      current_status: o.status,
    })),
    coverages: covers.map(({ code, name }) => ({
      code,
      name,
      by_offer: offers.map((o, i) => {
        const c = norm[i]?.coverages.find((x) => x.code === code);
        return { offer_id: o.id, included: !!c, optional: !!c?.optional && !c.mandatory, limit_minor: c?.limitMinor ?? null, deductible_minor: c?.deductibleMinor ?? null };
      }),
    })),
    exclusions: exclusions.map(({ code, name }) => ({ code, name, by_offer: offers.map((o, i) => ({ offer_id: o.id, applies: !!norm[i]?.exclusions.some((e) => e.code === code) })) })),
    lowest_total_offer_id: lowest?.id ?? null,
  };
}

export type TableCell = { minor?: number | null; text?: string; best?: boolean; tone?: "danger" };
export type TableRow = { key: string; label: string; heading?: boolean; cells: TableCell[] };
export type TableColumn = {
  offerId: string;
  carrierId: string | null;
  name: string;
  product: string;
  totalMinor: number;
  validUntil: string | null;
  /** Why this offer can no longer be chosen (null = choosable). */
  block: OfferBlock | null;
  logoUrl: string | null;
};

const nameOf = (n: unknown, language: string, fallback: string) => localized(n, language) || fallback;

/**
 * Columns + rows for the comparison table. `order` sorts the columns (the screen's sort); `offers`
 * are the rated offers of the quote when known: they give the live status, the cover period and
 * the optional rows (cover level, rating, claims days, payment options).
 */
export function comparisonTable(
  c: QuoteComparison,
  opts: { language?: string; now?: number; order?: string[]; offers?: RatedOffer[]; formatDate?: (iso: string) => string; /** Requested cover start from the quote's risk facts (same for every offer). */ coverStart?: string | null } = {},
): { columns: TableColumn[]; rows: TableRow[] } {
  const language = opts.language ?? "en";
  const now = opts.now ?? Date.now();
  const tx = (key: string, vars?: Record<string, string | number>) => copyText(language, key, vars);
  const fmt = opts.formatDate ?? ((iso: string) => iso.slice(0, 10));
  const rank = (id: string) => {
    const i = (opts.order ?? []).indexOf(id);
    return i < 0 ? Number.MAX_SAFE_INTEGER : i;
  };
  const list = [...(c.offers ?? [])].sort((a, b) => rank(a.offer_id) - rank(b.offer_id));
  const rated = list.map((o) => (opts.offers ?? []).find((x) => x.id === o.offer_id) ?? null);
  const columns: TableColumn[] = list.map((o, i) => {
    const r = rated[i];
    const status = r?.status ?? o.current_status ?? "OFFERED";
    const validUntil = r?.valid_until ?? o.valid_until ?? null;
    return {
      offerId: o.offer_id,
      carrierId: o.carrier_id ?? (r ? carrierKey(r) : null),
      name: o.carrier || (r ? providerName(r, language) : tx("licensedCarrier")),
      product: nameOf(o.product, language, r ? localized(r.product?.name, language) : ""),
      totalMinor: o.total_minor,
      validUntil,
      block: offerBlock({ status: status === "UNKNOWN" ? "unavailable" : status, valid_until: validUntil }, now),
      logoUrl: o.carrier_logo_url ?? r?.carrier_logo_url ?? null,
    };
  });
  const live = columns.map((col) => !col.block);

  // Lowest value among the offers that can still be chosen; nothing is highlighted when all are equal.
  const money = (key: string, label: string, pick: (i: number) => number): TableRow => {
    const values = list.map((_, i) => pick(i));
    const candidates = values.filter((_, i) => live[i]);
    const min = candidates.length ? Math.min(...candidates) : null;
    const distinct = new Set(candidates).size > 1;
    return { key, label, cells: values.map((v, i) => ({ minor: v, best: distinct && live[i] === true && v === min })) };
  };

  const rows: TableRow[] = [
    money("premium", tx("sumPremium"), (i) => list[i]!.premium_minor),
    money("tax", tx("sumTaxes"), (i) => list[i]!.tax_minor),
    money("fees", tx("sumFees"), (i) => list[i]!.fee_minor),
    money("total", tx("sumTotalPayable"), (i) => list[i]!.total_minor),
    {
      key: "valid_until",
      label: tx("cmpRowValidUntil"),
      cells: columns.map((col) =>
        col.block === "expired"
          ? { text: tx("offerExpired"), tone: "danger" as const }
          : col.block && col.block !== "accepted"
            ? { text: tx("ofOfferUnavailable"), tone: "danger" as const }
            : { text: col.validUntil ? fmt(col.validUntil) : tx("validityNotStated") },
      ),
    },
  ];

  if (opts.coverStart) rows.push({ key: "cover_start", label: tx("cmpRowCoverStart"), cells: list.map(() => ({ text: fmt(opts.coverStart as string) })) });
  const periods = rated.map((r) => periodCopy(offerPeriod(r)));
  if (periods.some(Boolean))
    rows.push({ key: "period", label: tx("cmpRowCoverPeriod"), cells: periods.map((p) => ({ text: p ? tx(p.key, p.vars) : "—" })) });

  // Rows only the rated offers can fill (never invented: shown when at least one offer states it).
  if (rated.length && rated.every(Boolean)) {
    const all = rated as RatedOffer[];
    const levelKey = { essential: "ofLevelEssential", standard: "ofLevelStandard", full: "ofLevelFull" } as const;
    if (all.some((o) => normalizeCoverage(o.coverage_snapshot).coverages.length))
      rows.push({ key: "level", label: tx("ofCoverLevel"), cells: all.map((o) => ({ text: tx(levelKey[coverLevel(o, all)]) })) });
    if (all.some((o) => carrierRating(o)))
      rows.push({ key: "rating", label: tx("cmpRowRating"), cells: all.map((o) => ({ text: carrierRating(o) ?? "—" })) });
    if (all.some((o) => carrierClaimsDays(o) !== null))
      rows.push({ key: "claims_days", label: tx("cmpRowClaimsDays"), cells: all.map((o) => { const d = carrierClaimsDays(o); return { text: d === null ? "—" : tx("cmpDays", { count: d }) }; }) });
    if (all.some((o) => offerPaymentMethods(o).length))
      rows.push({ key: "payment", label: tx("ofPaymentOptions"), cells: all.map((o) => ({ text: offerPaymentMethods(o).map((m) => paymentMethodLabel(m, language)).join(", ") || "—" })) });
  }

  const covers = c.coverages ?? [];
  const byOffer = <T extends { offer_id: string }>(rows: T[], id: string) => rows.find((b) => b.offer_id === id);
  if (covers.length) {
    rows.push({ key: "section:limits", label: tx("cmpSection_limits"), heading: true, cells: [] });
    for (const cov of covers)
      rows.push({
        key: `limit:${cov.code}`,
        label: nameOf(cov.name, language, cov.code),
        cells: list.map((o) => {
          const b = byOffer(cov.by_offer, o.offer_id);
          if (!b?.included) return { text: tx("cmpNotIncluded") };
          const tag = b.optional ? tx("cmpOptional") : undefined;
          return b.limit_minor === null || b.limit_minor === undefined ? { text: tag ?? tx("cmpIncluded") } : { minor: b.limit_minor, text: tag };
        }),
      });
    rows.push({ key: "section:deductibles", label: tx("cmpSection_deductibles"), heading: true, cells: [] });
    for (const cov of covers)
      rows.push({
        key: `deductible:${cov.code}`,
        label: nameOf(cov.name, language, cov.code),
        cells: list.map((o) => {
          const b = byOffer(cov.by_offer, o.offer_id);
          if (!b?.included) return { text: tx("cmpNotIncluded") };
          return b.deductible_minor === null || b.deductible_minor === undefined ? { text: tx("cmpNone") } : { minor: b.deductible_minor };
        }),
      });
  }
  const excl = c.exclusions ?? [];
  if (excl.length) {
    rows.push({ key: "section:exclusions", label: tx("cmpSection_exclusions"), heading: true, cells: [] });
    for (const ex of excl)
      rows.push({
        key: `exclusion:${ex.code}`,
        label: nameOf(ex.name, language, ex.code),
        cells: list.map((o) => ({ text: byOffer(ex.by_offer, o.offer_id)?.applies ? tx("cmpApplies") : tx("cmpNone") })),
      });
  }
  return { columns, rows };
}

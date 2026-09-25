/**
 * Batch 6 quote / manual-quotation / proposal-lifecycle / distribution helpers.
 * Pure functions, no imports (node-tested in tests/quote-workflow.test.mjs).
 */

export type Tone = "neutral" | "success" | "warning" | "info" | "danger";

// --- Quote lifecycle (QuoteMachine) ------------------------------------------

export const QUOTE_PRICED_STATES = ["CALCULATED", "GENERATED", "SENT", "VIEWED"] as const;
export const QUOTE_TERMINAL_STATES = ["ACCEPTED", "DECLINED", "EXPIRED", "CANCELLED"] as const;
/** GET quotes/{q}/document answers 422 outside these states. */
export const QUOTE_DOCUMENT_STATES = ["GENERATED", "SENT", "VIEWED", "ACCEPTED"] as const;
/** QuoteService::DECLINE_REASONS. */
export const QUOTE_DECLINE_REASONS = ["CUSTOMER_DECLINED", "PRICE_TOO_HIGH", "COVER_NOT_SUITABLE", "LOST_TO_COMPETITOR", "NO_RESPONSE", "DUPLICATE", "OTHER"] as const;

type QuoteLike = { status?: string | null; lifecycle_state?: string | null; expires_at?: string | null } | null | undefined;

const up = (v: unknown) => String(v ?? "").toUpperCase();

/** The lifecycle state, falling back to the legacy status projection. */
export function quoteState(q: QuoteLike): string {
  return up(q?.lifecycle_state) || up(q?.status);
}

/** Terminal outcome wins; a priced quote past expires_at reads as EXPIRED. */
export function quoteOutcome(q: QuoteLike, now = Date.now()): "DECLINED" | "EXPIRED" | "CANCELLED" | "ACCEPTED" | null {
  const s = quoteState(q);
  const legacy = up(q?.status);
  for (const x of ["DECLINED", "CANCELLED", "ACCEPTED", "EXPIRED"] as const) if (s === x || legacy === x) return x;
  if (q?.expires_at && Date.parse(q.expires_at) < now) return "EXPIRED";
  return null;
}

export function quoteTone(q: QuoteLike, now = Date.now()): Tone {
  const o = quoteOutcome(q, now);
  if (o === "DECLINED" || o === "EXPIRED") return "danger";
  if (o === "CANCELLED") return "neutral";
  if (o === "ACCEPTED") return "success";
  const s = quoteState(q);
  if (s === "REFERRED") return "warning";
  return (QUOTE_PRICED_STATES as readonly string[]).includes(s) || s === "OFFERED" ? "success" : "info";
}

/** i18n key for the chip: quoteLifecycle_<STATE> (falls back to quoteStatus_<status>). */
export const quoteStateKey = (q: QuoteLike, now = Date.now()) => `quoteLifecycle_${quoteOutcome(q, now) ?? quoteState(q)}`;

export function canDeclineQuote(q: QuoteLike, now = Date.now()): boolean {
  if (quoteOutcome(q, now)) return false;
  const s = quoteState(q);
  return s === "REFERRED" || s === "OFFERED" || (QUOTE_PRICED_STATES as readonly string[]).includes(s);
}

export function hasQuoteDocument(q: QuoteLike): boolean {
  return (QUOTE_DOCUMENT_STATES as readonly string[]).includes(up(q?.lifecycle_state));
}

// --- Manual quotation: carrier quote requests (REQ-QUO-006) ------------------

export type CarrierRequestLike = {
  status?: string | null;
  response_due_at?: string | null;
  responded_at?: string | null;
  /** Case status behind the request (WAITING_FOR_CUSTOMER, WAITING_FOR_EXTERNAL_EVIDENCE, ...). */
  case_status?: string | null;
  case_family?: string | null;
  case_subtype?: string | null;
  sla?: { metric?: string; due_at?: string | null; stopped_at?: string | null; breached_at?: string | null; label?: string | null; deadline_label?: string | null }[] | null;
};

/** Waiting states pause the SLA; legacy names from older case types are mapped to the current ones. */
const WAITING_STATES: Record<string, "WAITING_FOR_CUSTOMER" | "WAITING_FOR_EXTERNAL_EVIDENCE"> = {
  WAITING_FOR_CUSTOMER: "WAITING_FOR_CUSTOMER",
  WAITING_CUSTOMER: "WAITING_FOR_CUSTOMER",
  WAITING_FOR_EXTERNAL_EVIDENCE: "WAITING_FOR_EXTERNAL_EVIDENCE",
  WAITING_THIRD_PARTY: "WAITING_FOR_EXTERNAL_EVIDENCE",
};
/** Canonical waiting state of the request's case, or null when it is not waiting. */
export const caseWaitingState = (r: Pick<CarrierRequestLike, "case_status">) => WAITING_STATES[up(r.case_status)] ?? null;

/** SLA label of the running clock: PLATFORM_SLA (internal target) or REGULATORY_DEADLINE (legal basis). */
export function slaLabel(r: Pick<CarrierRequestLike, "sla">): "PLATFORM_SLA" | "REGULATORY_DEADLINE" | null {
  const labels = (r.sla ?? []).map((c) => up(c.label ?? c.deadline_label ?? "")).filter(Boolean);
  if (!labels.length) return null;
  return labels.includes("REGULATORY_DEADLINE") ? "REGULATORY_DEADLINE" : "PLATFORM_SLA";
}

/** Case family / subtype of the request; null when the API sends neither. */
export function caseKindParts(r: Pick<CarrierRequestLike, "case_family" | "case_subtype">): { family: string | null; subtype: string | null } | null {
  const family = up(r.case_family) || null;
  const subtype = up(r.case_subtype) || null;
  return family || subtype ? { family, subtype } : null;
}
export const CARRIER_REQUEST_OPEN = ["REQUESTED", "IN_PROGRESS"] as const;
export const CARRIER_DECLINE_REASONS = ["OUT_OF_APPETITE", "INSUFFICIENT_INFORMATION", "RISK_TOO_HIGH", "NO_CAPACITY", "OTHER"] as const;

export const isOpenCarrierRequest = (r: CarrierRequestLike) => (CARRIER_REQUEST_OPEN as readonly string[]).includes(up(r.status));

/**
 * "Sent to insurer" summary for a quote from GET quotes/{id}/carrier-requests.
 * null when nothing was sent (auto-rated quote).
 */
export function sentToInsurer(requests: CarrierRequestLike[] | null | undefined): { waiting: number; offered: number; declined: number; total: number; nextDueAt: string | null } | null {
  const rows = (requests ?? []).filter((r) => up(r.status) !== "CANCELLED");
  if (!rows.length) return null;
  const open = rows.filter(isOpenCarrierRequest);
  const due = open.map((r) => r.response_due_at).filter((d): d is string => !!d).sort();
  return {
    waiting: open.length,
    offered: rows.filter((r) => up(r.status) === "OFFERED").length,
    declined: rows.filter((r) => up(r.status) === "DECLINED").length,
    total: rows.length,
    nextDueAt: due[0] ?? null,
  };
}

export type SlaState = { state: "none" | "on_track" | "due_soon" | "breached" | "met" | "paused"; hoursLeft: number | null };

/** SLA of an insurer response: breached past due, due_soon inside 4 h, paused while the case waits on someone else. */
export function slaState(r: CarrierRequestLike, now = Date.now()): SlaState {
  if (!isOpenCarrierRequest(r)) return { state: r.responded_at ? "met" : "none", hoursLeft: null };
  if ((r.sla ?? []).some((c) => !!c.breached_at)) return { state: "breached", hoursLeft: 0 };
  if (caseWaitingState(r)) return { state: "paused", hoursLeft: null };
  if (!r.response_due_at) return { state: "none", hoursLeft: null };
  const hours = (Date.parse(r.response_due_at) - now) / 3_600_000;
  if (hours <= 0) return { state: "breached", hoursLeft: 0 };
  return { state: hours <= 4 ? "due_soon" : "on_track", hoursLeft: Math.floor(hours) };
}

export const slaTone = (s: SlaState["state"]): Tone => (s === "breached" ? "danger" : s === "due_soon" ? "warning" : s === "met" ? "success" : s === "on_track" ? "info" : "neutral");

/** Chip text for an SLA state, prefixed with the clock's label when the API sends one. */
export function slaChipText(r: CarrierRequestLike, td: (key: string, fallback: string) => string, now = Date.now()): string {
  const s = slaState(r, now);
  const base = td(`cqrSla_${s.state}`, s.state).replace("{hours}", String(s.hoursLeft ?? 0));
  const label = slaLabel(r);
  return label ? `${td(`cqrSlaLabel_${label}`, label)} · ${base}` : base;
}

export type BreakdownLine = { code: string; label?: string; amount_minor: number };

/** Parses a user-entered FCFA amount ("125 000", "125000") to minor units; null if invalid. */
export function toMinor(text: string | null | undefined): number | null {
  const clean = String(text ?? "").replace(/[\s  ]/g, "").replace(",", ".");
  if (!clean) return null;
  if (!/^\d+(\.\d{1,2})?$/.test(clean)) return null;
  return Math.round(Number(clean) * 100);
}

export type OfferInput = { premium: string; tax: string; fee: string; validUntil: string; lines: { code: string; label: string; amount: string }[]; conditions: string[] };
export type OfferError = "premium" | "tax" | "fee" | "validity" | "line" | "breakdown";

/**
 * Validates and builds the POST carrier/quote-requests/{id}/offer body. Mirrors the server:
 * total = premium + tax + fee, and breakdown lines (when given) must add up to that total.
 */
export function buildCarrierOffer(input: OfferInput, now = Date.now()): { ok: true; body: Record<string, unknown>; total: number } | { ok: false; error: OfferError; total: number | null; linesSum?: number } {
  const premium = toMinor(input.premium);
  const tax = input.tax.trim() ? toMinor(input.tax) : 0;
  const fee = input.fee.trim() ? toMinor(input.fee) : 0;
  if (premium === null) return { ok: false, error: "premium", total: null };
  if (tax === null) return { ok: false, error: "tax", total: null };
  if (fee === null) return { ok: false, error: "fee", total: null };
  const total = premium + tax + fee;
  // A picked day (YYYY-MM-DD) is valid until the end of that day.
  const valid = Date.parse(/^\d{4}-\d{2}-\d{2}$/.test(input.validUntil) ? `${input.validUntil}T23:59:59` : input.validUntil);
  if (!input.validUntil || Number.isNaN(valid) || valid <= now) return { ok: false, error: "validity", total };
  const filled = input.lines.filter((l) => l.code.trim() || l.label.trim() || l.amount.trim());
  const lines: BreakdownLine[] = [];
  for (const l of filled) {
    const amount = toMinor(l.amount);
    if (amount === null || !(l.code.trim() || l.label.trim())) return { ok: false, error: "line", total };
    const code = (l.code.trim() || l.label.trim()).toUpperCase().replace(/[^A-Z0-9]+/g, "_").slice(0, 64);
    lines.push({ code, ...(l.label.trim() ? { label: l.label.trim() } : {}), amount_minor: amount });
  }
  const linesSum = lines.reduce((a, l) => a + l.amount_minor, 0);
  if (lines.length && linesSum !== total) return { ok: false, error: "breakdown", total, linesSum };
  const conditions = input.conditions.map((c) => c.trim()).filter(Boolean).map((text) => ({ text }));
  return {
    ok: true,
    total,
    body: {
      premium_minor: premium,
      tax_minor: tax,
      fee_minor: fee,
      total_minor: total,
      valid_until: new Date(valid).toISOString(),
      ...(lines.length ? { premium_breakdown: lines } : {}),
      ...(conditions.length ? { conditions } : {}),
    },
  };
}

// --- Quote comparison (REQ-DST-003) ---------------------------------------------

export type ComparisonOffer = {
  offer_id: string;
  carrier?: string | null;
  product?: unknown;
  premium_minor: number;
  tax_minor: number;
  fee_minor: number;
  total_minor: number;
  difference_to_lowest_minor?: number;
  valid_until?: string | null;
  current_status?: string;
};
export type QuoteComparison = {
  id: string;
  quote_id: string;
  is_expired?: boolean;
  expires_at?: string | null;
  offers?: ComparisonOffer[];
  coverages?: { code: string; name?: unknown; by_offer: { offer_id: string; included: boolean; optional?: boolean; limit_minor?: number | null; deductible_minor?: number | null }[] }[];
  exclusions?: { code: string; name?: unknown; by_offer: { offer_id: string; applies: boolean }[] }[];
  lowest_total_offer_id?: string | null;
};

export type CompareCell = { minor?: number | null; text?: string; best?: boolean };
export type CompareRow = { key: string; label: string; section?: "price" | "limits" | "deductibles" | "exclusions"; cells: CompareCell[] };

const nameOf = (n: unknown, language: string, fallback: string) => {
  if (typeof n === "string" && n) return n;
  if (n && typeof n === "object") {
    const o = n as Record<string, unknown>;
    const v = o[language] ?? o.en ?? o.fr;
    if (typeof v === "string" && v) return v;
  }
  return fallback;
};

/**
 * Rows for the comparison table: header, premium, tax, fees, total; then limits and deductibles per
 * coverage, then exclusions. `label` for dimension rows is an i18n key (resolved by the caller); coverage
 * and exclusion rows carry their own names.
 */
export function comparisonRows(c: QuoteComparison, language = "en", words: { included: string; notIncluded: string; applies: string; none: string } = { included: "✓", notIncluded: "—", applies: "✗", none: "—" }): CompareRow[] {
  const offers = c.offers ?? [];
  const lowest = c.lowest_total_offer_id ?? null;
  const money = (key: "premium_minor" | "tax_minor" | "fee_minor" | "total_minor", label: string): CompareRow => {
    const min = Math.min(...offers.map((o) => o[key]));
    return { key, label, section: "price", cells: offers.map((o) => ({ minor: o[key], best: key === "total_minor" ? o.offer_id === lowest || o[key] === min : false })) };
  };
  const rows: CompareRow[] = [
    { key: "insurer", label: "cmpInsurer", cells: offers.map((o) => ({ text: [o.carrier, nameOf(o.product, language, "")].filter(Boolean).join(" · ") || "—" })) },
    money("premium_minor", "cmpPremium"),
    money("tax_minor", "cmpTax"),
    money("fee_minor", "cmpFees"),
    money("total_minor", "cmpTotal"),
  ];
  const cell = (covOffer: { included: boolean; limit_minor?: number | null; deductible_minor?: number | null } | undefined, field: "limit_minor" | "deductible_minor"): CompareCell => {
    if (!covOffer || !covOffer.included) return { text: words.notIncluded };
    const v = covOffer[field];
    return v === null || v === undefined ? { text: field === "limit_minor" ? words.included : words.none } : { minor: v };
  };
  for (const cov of c.coverages ?? []) {
    const by = (id: string) => cov.by_offer.find((b) => b.offer_id === id);
    rows.push({ key: `limit:${cov.code}`, label: nameOf(cov.name, language, cov.code), section: "limits", cells: offers.map((o) => cell(by(o.offer_id), "limit_minor")) });
  }
  for (const cov of c.coverages ?? []) {
    const by = (id: string) => cov.by_offer.find((b) => b.offer_id === id);
    rows.push({ key: `deductible:${cov.code}`, label: nameOf(cov.name, language, cov.code), section: "deductibles", cells: offers.map((o) => cell(by(o.offer_id), "deductible_minor")) });
  }
  for (const ex of c.exclusions ?? []) {
    rows.push({
      key: `exclusion:${ex.code}`,
      label: nameOf(ex.name, language, ex.code),
      section: "exclusions",
      cells: offers.map((o) => ({ text: ex.by_offer.find((b) => b.offer_id === o.offer_id)?.applies ? words.applies : words.none })),
    });
  }
  return rows;
}

// --- Proposal lifecycle (REQ-PRP-001…005) --------------------------------------

export type ChecklistDocument = { code: string; label?: string; name?: unknown; mandatory?: boolean; status?: string | null };

/** required_documents[].status → tone + i18n key (propDoc_<STATUS>). */
export function requiredDocumentInfo(status: string | null | undefined): { key: string; tone: Tone; done: boolean } {
  const s = up(status) || "MISSING";
  const tone: Tone = s === "ACCEPTED" || s === "VERIFIED" ? "success" : s === "REJECTED" || s === "EXPIRED" ? "danger" : s === "UPLOADED" || s === "REVIEWING" ? "info" : "warning";
  return { key: `propDoc_${s}`, tone, done: ["UPLOADED", "REVIEWING", "ACCEPTED", "VERIFIED"].includes(s) };
}

export const PROPOSAL_WITHDRAWABLE = ["DRAFT", "DISCLOSURES_PENDING", "DOCUMENTS_PENDING", "INFORMATION_REQUIRED", "MORE_INFORMATION", "SUBMITTED", "UNDER_REVIEW", "RESUBMITTED", "COUNTEROFFERED", "APPROVED"] as const;

/** Prefer the server's available_transitions when present. */
export function canWithdrawProposal(status: string | null | undefined, transitions?: string[] | null): boolean {
  if (transitions && transitions.length) return transitions.map(up).includes("WITHDRAW");
  return (PROPOSAL_WITHDRAWABLE as readonly string[]).includes(up(status));
}

export function canResubmitProposal(status: string | null | undefined, transitions?: string[] | null, blocking?: string[] | null): boolean {
  if (up(status) !== "INFORMATION_REQUIRED") return false;
  if (transitions && transitions.length && !transitions.map(up).includes("RESUBMIT")) return false;
  return !(blocking ?? []).some((b) => /^DOCUMENT_/.test(b));
}

/**
 * 422 from saving answers after the proposal was submitted: Laravel validation on `status`
 * (wave3.disclosures_locked). The UI reloads the proposal instead of retrying.
 */
export function isAnswersLocked(error: unknown): boolean {
  const e = error as { status?: number; fields?: Record<string, unknown> } | null;
  return !!e && e.status === 422 && !!e.fields && typeof e.fields === "object" && "status" in e.fields;
}

// --- Distribution catalogue (REQ-DST-001/002) ----------------------------------

export type CatalogueItem = {
  product_id: string;
  name?: unknown;
  line_code?: string | null;
  carrier_name?: string | null;
  sellable: boolean;
  reasons?: string[];
  requires_carrier_approval?: boolean;
  commission_basis_points?: number | null;
};

/** Sellable first, then by line and name; grouped by line_code. */
export function groupCatalogue(items: CatalogueItem[], language = "en"): { line: string; items: CatalogueItem[] }[] {
  const sorted = [...items].sort(
    (a, b) => Number(b.sellable) - Number(a.sellable) || String(a.line_code ?? "").localeCompare(String(b.line_code ?? "")) || nameOf(a.name, language, "").localeCompare(nameOf(b.name, language, "")),
  );
  const groups = new Map<string, CatalogueItem[]>();
  for (const i of sorted) {
    const k = up(i.line_code) || "OTHER";
    groups.set(k, [...(groups.get(k) ?? []), i]);
  }
  return [...groups.entries()].map(([line, items]) => ({ line, items }));
}

export const catalogueName = (i: CatalogueItem, language = "en") => nameOf(i.name, language, i.product_id);

/** "12.5 %" from basis points; null when unknown. */
export const commissionPercent = (bp: number | null | undefined) => (bp === null || bp === undefined ? null : `${Number((bp / 100).toFixed(2))} %`);

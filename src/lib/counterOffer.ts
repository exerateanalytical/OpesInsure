/**
 * The insurer's counter-offer next to the original terms, for the application
 * hub before the customer accepts. Server facts only: `counter_offer` comes
 * from GET mobile/proposals/{id} (ProposalService::counterTerms: revised
 * premium / tax / fee / total, null when that part was not revised) and the
 * original amounts from the proposal's terms snapshot.
 *
 * Dependency-free: tests/counter-offer.test.mjs loads it with Node's type stripping.
 */
type Amounts = { premium_minor?: number | null; tax_minor?: number | null; fee_minor?: number | null; total_minor?: number | null };
export type CounterRow = { key: "premium" | "tax" | "fee" | "total"; label: "prRevisedPremium" | "prRevisedTax" | "prRevisedFee" | "prRevisedTotal"; from: number | null; to: number };
export type CounterView = { revisedTotal: number | null; originalTotal: number | null; rows: CounterRow[]; notes: string | null };

const amount = (v: unknown): number | null => (typeof v === "number" && Number.isFinite(v) ? v : null);

export function counterOfferView(counter: (Amounts & { notes?: string | null }) | null | undefined, original: Amounts | null | undefined): CounterView {
  const c = counter ?? {};
  const o = original ?? {};
  const parts = [
    ["premium", "prRevisedPremium"],
    ["tax", "prRevisedTax"],
    ["fee", "prRevisedFee"],
  ] as const;
  const rows: CounterRow[] = [];
  for (const [key, label] of parts) {
    const to = amount(c[`${key}_minor`]);
    const from = amount(o[`${key}_minor`]);
    if (to !== null && to !== from) rows.push({ key, label, from, to });
  }
  const originalTotal = amount(o.total_minor);
  // What accepting will charge: the server copies only the revised parts into the terms snapshot
  // (ProposalService::respondToCounterOffer), so without a revised total the original total stays.
  const revisedTotal = amount(c.total_minor) ?? (counter ? originalTotal : null);
  if (revisedTotal !== null && revisedTotal !== originalTotal) rows.push({ key: "total", label: "prRevisedTotal", from: originalTotal, to: revisedTotal });
  const notes = typeof c.notes === "string" && c.notes.trim() ? c.notes.trim() : null;
  return { revisedTotal, originalTotal, rows, notes };
}

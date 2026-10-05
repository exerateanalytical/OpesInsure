/**
 * "Get a quote" from an insurer or broker profile keeps the chosen institution through the quote flow
 * (quote/product → quote/risk → quote/offers) as two route params: `carriers` (comma-separated carrier ids) and
 * `from` (the display name). The offers screen then opens filtered to those insurers, with a chip to see all.
 * Pure helpers, no imports (node-tested in tests/quote-focus.test.mjs).
 */
export type QuoteFocus = { carriers: string[]; name: string };

type InstitutionLike = {
  id: string;
  type: "insurer" | "broker";
  name: string;
  short_name?: string | null;
  affiliated_insurers?: { id: string }[] | null;
};

/** Insurer profile → that insurer; broker profile → the insurers the broker is appointed by (none known → no focus). */
export function quoteFocusOf(row: InstitutionLike | null | undefined): QuoteFocus | null {
  if (!row) return null;
  if (row.type === "insurer") return { carriers: [row.id], name: row.short_name || row.name };
  const ids = [...new Set((row.affiliated_insurers ?? []).map((i) => i.id).filter(Boolean))];
  return ids.length ? { carriers: ids, name: row.name } : null;
}

/** Route params to carry the focus to the next quote step ({} when there is none). */
export function focusParams(focus: QuoteFocus | null | undefined): { carriers?: string; from?: string } {
  return focus && focus.carriers.length ? { carriers: focus.carriers.join(","), from: focus.name } : {};
}

/** Reads the focus back from route params (expo-router may hand a string or an array). */
export function focusFromParams(carriers: unknown, from: unknown): QuoteFocus | null {
  const raw = Array.isArray(carriers) ? carriers.join(",") : typeof carriers === "string" ? carriers : "";
  const ids = [...new Set(raw.split(",").map((s) => s.trim()).filter(Boolean))];
  if (!ids.length) return null;
  const name = (Array.isArray(from) ? from[0] : from) ?? "";
  return { carriers: ids, name: typeof name === "string" ? name : "" };
}

/** Insurers of the focus that actually answered the quote (empty → show every insurer and say so). */
export function focusedCarrierIds(focus: QuoteFocus | null | undefined, answered: string[]): string[] {
  if (!focus) return [];
  const set = new Set(answered);
  return focus.carriers.filter((id) => set.has(id));
}

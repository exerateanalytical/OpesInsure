import type { AssetScanState } from "@/api/client";

/** The registration-card facts the scan screen reads and confirms. */
export const SCAN_FACT_KEYS = ["registration_number", "make", "model", "year"] as const;
export type ScanFactKey = (typeof SCAN_FACT_KEYS)[number];

/** Attachment purpose for the vehicle registration card (pivot `purpose`). */
export const REGISTRATION_PURPOSE = "VEHICLE_REGISTRATION";

const text = (v: unknown) => (typeof v === "string" || typeof v === "number" ? String(v).trim() : "");

/**
 * Starting values for the form: what the asset already has on file, overlaid
 * with anything OCR actually extracted from this document (usually nothing
 * until an OCR provider is connected — the server never invents fields).
 */
export function scanPrefill(asset: AssetScanState, documentId: string): Record<ScanFactKey, string> {
  const facts = asset.facts ?? {};
  const ocr = asset.documents?.find((d) => d.id === documentId)?.ocr_data?.fields ?? {};
  const out = {} as Record<ScanFactKey, string>;
  for (const key of SCAN_FACT_KEYS) {
    const fallback = key === "registration_number" ? asset.external_reference : undefined;
    out[key] = text(ocr[key]) || text(facts[key]) || text(fallback);
  }
  return out;
}

/** The facts to confirm: trimmed, blanks dropped, year as a number. */
export function scanFacts(fields: Partial<Record<ScanFactKey, string>>): Record<string, string | number> {
  const facts: Record<string, string | number> = {};
  for (const key of SCAN_FACT_KEYS) {
    const value = (fields[key] ?? "").trim();
    if (!value) continue;
    if (key === "year") {
      const year = Number(value);
      if (Number.isInteger(year) && year > 1900) facts.year = year;
    } else facts[key] = value;
  }
  return facts;
}

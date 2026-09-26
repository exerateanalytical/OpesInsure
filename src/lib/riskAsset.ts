import type { RiskAsset } from "@/api/client";

type Raw = Partial<RiskAsset> & {
  id: string;
  display_name?: string | null;
  external_reference?: string | null;
  facts?: Record<string, unknown> | null;
};

const str = (v: unknown) => (typeof v === "string" && v.trim() ? v.trim() : undefined);

/**
 * GET /mobile/assets answers {data: paginator} and each row is the server
 * model (display_name, external_reference, facts). Map both shapes onto the
 * app's RiskAsset so the list and detail screens read one set of fields.
 */
export function normalizeAsset(x: Raw): RiskAsset {
  const f = (x.facts ?? {}) as Record<string, unknown>;
  const year = Number(x.year ?? f.year ?? f.manufacture_year);
  return {
    ...x,
    type: x.type ?? "VEHICLE",
    status: x.status ?? "",
    label: x.label ?? x.display_name ?? "",
    registration_number: x.registration_number ?? x.external_reference ?? str(f.registration_number) ?? undefined,
    make: x.make ?? str(f.make),
    model: x.model ?? str(f.model),
    year: Number.isFinite(year) && year > 0 ? year : undefined,
  } as RiskAsset;
}

export function normalizeAssetList(r: unknown): RiskAsset[] {
  const rows = Array.isArray(r) ? r : Array.isArray((r as { data?: unknown })?.data) ? (r as { data: unknown[] }).data : [];
  return (rows as Raw[]).map(normalizeAsset);
}

/**
 * Vehicle master data helpers for the vehicle picker (quote wizard, assets).
 *
 * The server (GET /public/vehicles/makes|models|reference) is the source of
 * truth for makes, models, aliases and ranking. These helpers only normalize
 * payloads, build the model-year list and the manual "not listed" entry, and
 * turn a selection into risk facts. Dependency-free (Node type stripping).
 */

export type VehicleMake = {
  code: string;
  name: string;
  country_of_origin?: string | null;
  segment?: string;
  cameroon_status?: string;
  market_priority?: string;
  aliases: string[];
  models_count?: number;
};
export type VehicleModel = { code: string; name: string; segment?: string; status?: string; aliases: string[] };
export type LabelledCode = { code: string; label: string };
export type VehicleReference = {
  body_types: LabelledCode[];
  usage_types: LabelledCode[];
  powertrains: LabelledCode[];
  hybrid_subtypes: LabelledCode[];
  transmissions: LabelledCode[];
  drive_types: LabelledCode[];
  vehicle_classes: LabelledCode[];
  ownership_types: LabelledCode[];
  conditions: LabelledCode[];
  model_years: { min: number; max: number };
};

/** What the picker hands back to its host screen. */
export type VehicleSelection = {
  make_code?: string;
  make: string;
  model_code?: string;
  model: string;
  year?: string;
  body_type?: string;
  powertrain?: string;
  transmission?: string;
  vehicle_usage?: string;
  /** True when entered through "Can't find your vehicle?" (pending master-data review). */
  manual?: boolean;
  review_id?: string;
  vin?: string;
  registration_number?: string;
  engine_number?: string;
};

export const MIN_MODEL_YEAR = 1950;

const rows = (payload: unknown): unknown[] => {
  if (Array.isArray(payload)) return payload;
  const body = payload && typeof payload === "object" ? (payload as Record<string, unknown>) : null;
  return body && Array.isArray(body.data) ? body.data : [];
};
const str = (v: unknown) => (typeof v === "string" ? v : v == null ? "" : String(v));
const strs = (v: unknown) => (Array.isArray(v) ? v.map(str).filter(Boolean) : []);

export function normalizeMakes(payload: unknown): VehicleMake[] {
  return rows(payload)
    .map((r) => {
      const x = (r ?? {}) as Record<string, unknown>;
      return {
        code: str(x.code),
        name: str(x.name),
        country_of_origin: x.country_of_origin == null ? null : str(x.country_of_origin),
        segment: x.segment == null ? undefined : str(x.segment),
        cameroon_status: x.cameroon_status == null ? undefined : str(x.cameroon_status),
        market_priority: x.market_priority == null ? undefined : str(x.market_priority),
        aliases: strs(x.aliases),
        models_count: typeof x.models_count === "number" ? x.models_count : undefined,
      };
    })
    .filter((m) => m.code && m.name);
}

export function normalizeModels(payload: unknown): VehicleModel[] {
  return rows(payload)
    .map((r) => {
      const x = (r ?? {}) as Record<string, unknown>;
      return { code: str(x.code), name: str(x.name), segment: x.segment == null ? undefined : str(x.segment), status: x.status == null ? undefined : str(x.status), aliases: strs(x.aliases) };
    })
    .filter((m) => m.code && m.name);
}

const labelled = (v: unknown, lang: string): LabelledCode[] =>
  (Array.isArray(v) ? v : [])
    .map((r) => {
      const x = (r ?? {}) as Record<string, unknown>;
      const label = x.label && typeof x.label === "object" ? (x.label as Record<string, unknown>) : null;
      const code = str(x.code);
      return { code, label: str(label?.[lang] ?? label?.en ?? x.label ?? code) || code };
    })
    .filter((o) => o.code);

export function normalizeReference(payload: unknown, lang: "en" | "fr" = "en", now = new Date()): VehicleReference {
  const body = payload && typeof payload === "object" ? (payload as Record<string, unknown>) : {};
  const root = (body.data && typeof body.data === "object" ? body.data : body) as Record<string, unknown>;
  const years = (root.model_years ?? {}) as Record<string, unknown>;
  return {
    body_types: labelled(root.body_types, lang),
    usage_types: labelled(root.usage_types, lang),
    powertrains: labelled(root.powertrains, lang),
    hybrid_subtypes: labelled(root.hybrid_subtypes, lang),
    transmissions: labelled(root.transmissions, lang),
    drive_types: labelled(root.drive_types, lang),
    vehicle_classes: labelled(root.vehicle_classes, lang),
    ownership_types: labelled(root.ownership_types, lang),
    conditions: labelled(root.conditions, lang),
    model_years: {
      min: typeof years.min === "number" ? years.min : MIN_MODEL_YEAR,
      max: typeof years.max === "number" ? years.max : now.getFullYear() + 1,
    },
  };
}

/** Newest first: current+1 … 1950 (or the server's range). */
export function modelYears(range?: { min: number; max: number }, now = new Date()): string[] {
  const max = range?.max ?? now.getFullYear() + 1;
  const min = range?.min ?? MIN_MODEL_YEAR;
  const out: string[] = [];
  for (let y = max; y >= min; y--) out.push(String(y));
  return out;
}

const norm = (s: string) =>
  s.normalize("NFD").replace(/[̀-ͯ]/g, "").toUpperCase().replace(/[^A-Z0-9]/g, "");

/**
 * Client-side filter over an already-loaded list (e.g. models of one make):
 * exact > prefix > contains, over name and aliases. Keeps server order within a tier.
 */
export function filterByQuery<T extends { name: string; aliases: string[] }>(items: T[], query: string): T[] {
  const q = norm(query);
  if (!q) return items;
  const score = (item: T) =>
    Math.max(
      ...[item.name, ...item.aliases].map((c) => {
        const n = norm(c);
        return n === q ? 3 : n.startsWith(q) ? 2 : q.length >= 2 && n.includes(q) ? 1 : 0;
      }),
    );
  return items
    .map((item, i) => ({ item, i, s: score(item) }))
    .filter((x) => x.s > 0)
    .sort((a, b) => b.s - a.s || a.i - b.i)
    .map((x) => x.item);
}

export type ManualVehicleEntry = {
  make: string;
  model: string;
  year: string;
  body_type: string;
  vin: string;
  registration_number: string;
  engine_number: string;
  powertrain: string;
  vehicle_usage: string;
};

export const emptyManualEntry = (): ManualVehicleEntry => ({ make: "", model: "", year: "", body_type: "", vin: "", registration_number: "", engine_number: "", powertrain: "", vehicle_usage: "" });

/** Returns field → message-key errors (keys live in the i18n catalogue). */
export function validateManualEntry(e: ManualVehicleEntry, range?: { min: number; max: number }): Partial<Record<keyof ManualVehicleEntry, "vehicleErrRequired" | "vehicleErrYear" | "vehicleErrVin" | "vehicleErrTooLong">> {
  const errors: Partial<Record<keyof ManualVehicleEntry, "vehicleErrRequired" | "vehicleErrYear" | "vehicleErrVin" | "vehicleErrTooLong">> = {};
  if (!e.make.trim()) errors.make = "vehicleErrRequired";
  else if (e.make.trim().length > 120) errors.make = "vehicleErrTooLong";
  if (!e.model.trim()) errors.model = "vehicleErrRequired";
  else if (e.model.trim().length > 120) errors.model = "vehicleErrTooLong";
  const y = Number(e.year);
  const min = range?.min ?? MIN_MODEL_YEAR;
  const max = range?.max ?? new Date().getFullYear() + 1;
  if (!e.year) errors.year = "vehicleErrRequired";
  else if (!Number.isInteger(y) || y < min || y > max) errors.year = "vehicleErrYear";
  if (e.vin && !/^[A-Za-z0-9-]{5,40}$/.test(e.vin.trim())) errors.vin = "vehicleErrVin";
  if (e.registration_number.length > 40) errors.registration_number = "vehicleErrTooLong";
  if (e.engine_number.length > 60) errors.engine_number = "vehicleErrTooLong";
  return errors;
}

/** Body for POST /mobile/vehicles/master-review (empty optional fields omitted). */
export function manualReviewPayload(e: ManualVehicleEntry, riskAssetId?: string | null) {
  const out: Record<string, string | number> = { make: e.make.trim(), model: e.model.trim() };
  if (e.year) out.model_year = Number(e.year);
  if (e.body_type) out.body_type = e.body_type;
  if (e.powertrain) out.powertrain = e.powertrain;
  if (e.vehicle_usage) out.usage = e.vehicle_usage;
  if (e.vin.trim()) out.vin = e.vin.trim().toUpperCase();
  if (e.registration_number.trim()) out.registration_number = e.registration_number.trim().toUpperCase();
  if (e.engine_number.trim()) out.engine_number = e.engine_number.trim();
  if (riskAssetId) out.risk_asset_id = riskAssetId;
  return out;
}

/** Manual entry → selection snapshot (no codes: the quote continues on text until reconciled). */
export function selectionFromManual(e: ManualVehicleEntry, reviewId?: string): VehicleSelection {
  return {
    make: e.make.trim(),
    model: e.model.trim(),
    year: e.year || undefined,
    body_type: e.body_type || undefined,
    powertrain: e.powertrain || undefined,
    vehicle_usage: e.vehicle_usage || undefined,
    vin: e.vin.trim() ? e.vin.trim().toUpperCase() : undefined,
    registration_number: e.registration_number.trim() ? e.registration_number.trim().toUpperCase() : undefined,
    engine_number: e.engine_number.trim() || undefined,
    manual: true,
    review_id: reviewId,
  };
}

/** Selection → string values keyed by risk-fact key (wizard/asset form state). */
export function selectionToValues(s: VehicleSelection): Record<string, string> {
  const out: Record<string, string> = { make: s.make, model: s.model, make_code: s.make_code ?? "", model_code: s.model_code ?? "" };
  for (const k of ["year", "body_type", "powertrain", "transmission", "vehicle_usage", "vin", "registration_number", "engine_number", "review_id"] as const) {
    const v = s[k];
    if (v) out[k === "review_id" ? "vehicle_review_id" : k] = String(v);
  }
  return out;
}

/** Readable one-liner: "Toyota Land Cruiser Prado · 2020". */
export function selectionLabel(s: Partial<VehicleSelection>): string {
  return [[s.make, s.model].filter(Boolean).join(" "), s.year].filter(Boolean).join(" · ");
}

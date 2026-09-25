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
  /** Make → model → generation → year → engine variant (CUST-007). */
  generation_code?: string;
  generation?: string;
  variant_code?: string;
  variant?: string;
  drive_type?: string;
  engine_capacity_cc?: string;
  power_hp?: string;
};

export type VehicleGeneration = { code: string; name: string; year_from: number | null; year_to: number | null; body_type?: string; variants_count: number };
export type VehicleVariantSpecs = {
  power_hp: number | null;
  power_kw: number | null;
  displacement_cc: number | null;
  fuel_type: string | null;
  transmission: string | null;
  drivetrain: string | null;
  body_type: string | null;
  torque_nm: number | null;
};
export type VehicleVariant = { code: string; name: string; year_from: number | null; year_to: number | null; specs: VehicleVariantSpecs };

export const MIN_MODEL_YEAR = 1950;

const num = (v: unknown): number | null => (typeof v === "number" && Number.isFinite(v) ? v : typeof v === "string" && v.trim() && Number.isFinite(Number(v)) ? Number(v) : null);
const optStr = (v: unknown): string | null => (typeof v === "string" && v.trim() ? v : null);

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

/**
 * Body for POST /master-data/suggestions (domain "vehicle", REQ-DUP-013):
 * a model under its make (text + parent), or a make alone; the typed
 * details travel as attributes (empty ones omitted).
 */
export function vehicleSuggestionPayload(e: ManualVehicleEntry, riskAssetId?: string | null) {
  const make = e.make.trim();
  const model = e.model.trim();
  const attributes: Record<string, string | number> = {};
  if (e.year) attributes.model_year = Number(e.year);
  if (e.body_type) attributes.body_type = e.body_type;
  if (e.powertrain) attributes.powertrain = e.powertrain;
  if (e.vehicle_usage) attributes.usage = e.vehicle_usage;
  if (e.vin.trim()) attributes.vin = e.vin.trim().toUpperCase();
  if (e.registration_number.trim()) attributes.registration_number = e.registration_number.trim().toUpperCase();
  if (e.engine_number.trim()) attributes.engine_number = e.engine_number.trim();
  if (riskAssetId) attributes.risk_asset_id = riskAssetId;
  return model
    ? { domain: "vehicle" as const, list: "models" as const, text: model, parent: make, attributes }
    : { domain: "vehicle" as const, list: "makes" as const, text: make, attributes };
}

/** The suggestion answer: data.review.id to carry as vehicle_review_id, or the matched master codes. */
export function suggestionOutcome(res: unknown): { reviewId?: string; makeCode?: string; modelCode?: string } {
  const r = res && typeof res === "object" ? (res as Record<string, unknown>) : {};
  const body = r.data && typeof r.data === "object" ? (r.data as Record<string, unknown>) : r;
  const review = body.review && typeof body.review === "object" ? (body.review as Record<string, unknown>) : null;
  const value = body.value && typeof body.value === "object" ? (body.value as Record<string, unknown>) : null;
  return {
    reviewId: typeof review?.id === "string" ? review.id : undefined,
    makeCode: typeof value?.make === "string" ? value.make : undefined,
    modelCode: typeof value?.model === "string" ? value.model : undefined,
  };
}

/** Manual entry → selection snapshot (no codes: the quote continues on text until reconciled). */
export function selectionFromManual(e: ManualVehicleEntry, reviewId?: string, matched?: { makeCode?: string; modelCode?: string }): VehicleSelection {
  // A MATCHED suggestion answers with the master codes: the quote then rates on codes.
  return {
    ...(matched?.makeCode ? { make_code: matched.makeCode } : {}),
    ...(matched?.modelCode ? { model_code: matched.modelCode } : {}),
    make: e.make.trim(),
    model: e.model.trim(),
    year: e.year || undefined,
    body_type: e.body_type || undefined,
    powertrain: e.powertrain || undefined,
    vehicle_usage: e.vehicle_usage || undefined,
    vin: e.vin.trim() ? e.vin.trim().toUpperCase() : undefined,
    registration_number: e.registration_number.trim() ? e.registration_number.trim().toUpperCase() : undefined,
    engine_number: e.engine_number.trim() || undefined,
    manual: !(matched?.makeCode && matched?.modelCode),
    review_id: reviewId,
  };
}

/** Selection → string values keyed by risk-fact key (wizard/asset form state). */
export function selectionToValues(s: VehicleSelection): Record<string, string> {
  const out: Record<string, string> = { make: s.make, model: s.model, make_code: s.make_code ?? "", model_code: s.model_code ?? "" };
  for (const k of ["year", "body_type", "powertrain", "transmission", "vehicle_usage", "vin", "registration_number", "engine_number", "review_id", "drive_type", "engine_capacity_cc", "power_hp"] as const) {
    const v = s[k];
    if (v) out[k === "review_id" ? "vehicle_review_id" : k] = String(v);
  }
  if (s.generation_code) out.vehicle_generation_code = s.generation_code;
  if (s.variant_code) out.vehicle_variant_code = s.variant_code;
  return out;
}

/** Readable one-liner: "Toyota Land Cruiser Prado · 2020". */
export function selectionLabel(s: Partial<VehicleSelection>): string {
  return [[s.make, s.model, s.generation].filter(Boolean).join(" "), s.year, s.variant].filter(Boolean).join(" · ");
}

// --- Generation → year → engine variant (CUST-007) --------------------------
// GET /public/vehicles/models/{model}/generations and
// /generations/{generation}/variants?year=. Both answer an empty list until
// admins/imports add the data; the picker then skips those steps.

export function normalizeGenerations(payload: unknown): VehicleGeneration[] {
  return rows(payload)
    .map((r) => {
      const x = (r ?? {}) as Record<string, unknown>;
      return {
        code: str(x.code),
        name: str(x.name),
        year_from: num(x.year_from),
        year_to: num(x.year_to),
        body_type: optStr(x.body_type) ?? undefined,
        variants_count: num(x.variants_count) ?? 0,
      };
    })
    .filter((g) => g.code && g.name);
}

export function normalizeVariants(payload: unknown): VehicleVariant[] {
  return rows(payload)
    .map((r) => {
      const x = (r ?? {}) as Record<string, unknown>;
      const sp = (x.specs && typeof x.specs === "object" ? x.specs : {}) as Record<string, unknown>;
      return {
        code: str(x.code),
        name: str(x.engine_label ?? x.name),
        year_from: num(x.year_from),
        year_to: num(x.year_to),
        specs: {
          power_hp: num(sp.power_hp),
          power_kw: num(sp.power_kw),
          displacement_cc: num(sp.displacement_cc ?? x.engine_capacity_cc),
          fuel_type: optStr(sp.fuel_type ?? x.powertrain),
          transmission: optStr(sp.transmission ?? x.transmission),
          drivetrain: optStr(sp.drivetrain ?? x.drive_type),
          body_type: optStr(sp.body_type ?? x.body_type),
          torque_nm: num(sp.torque_nm),
        },
      };
    })
    .filter((v) => v.code && v.name);
}

/** Years a generation was sold, newest first, clamped to the model-year range. */
export function generationYears(g: Pick<VehicleGeneration, "year_from" | "year_to">, range?: { min: number; max: number }, now = new Date()): string[] {
  const all = modelYears(range, now);
  if (g.year_from == null) return all;
  const from = g.year_from;
  const to = g.year_to ?? Number(all[0]);
  return all.filter((y) => Number(y) >= from && Number(y) <= to);
}

/** "Label · 150 hp · 1998 cc · Automatic" for the variant list. */
export function variantSummary(v: VehicleVariant): string {
  return [
    v.specs.power_hp != null ? `${v.specs.power_hp} hp` : null,
    v.specs.displacement_cc != null ? `${v.specs.displacement_cc} cc` : null,
    v.specs.fuel_type,
    v.specs.transmission,
    v.specs.drivetrain,
  ]
    .filter(Boolean)
    .join(" · ");
}

/** Engine variant → selection with specs auto-filled (unknown specs stay as
 * they were, so the user is only asked for what the master does not know). */
export function applyVariant(s: VehicleSelection, v: VehicleVariant): VehicleSelection {
  const keep = <T,>(next: T | null, prev: T | undefined) => (next != null ? next : prev);
  return {
    ...s,
    variant_code: v.code,
    variant: v.name,
    body_type: keep(v.specs.body_type, s.body_type) ?? undefined,
    powertrain: keep(v.specs.fuel_type, s.powertrain) ?? undefined,
    transmission: keep(v.specs.transmission, s.transmission) ?? undefined,
    drive_type: keep(v.specs.drivetrain, s.drive_type) ?? undefined,
    engine_capacity_cc: v.specs.displacement_cc != null ? String(v.specs.displacement_cc) : s.engine_capacity_cc,
    power_hp: v.specs.power_hp != null ? String(v.specs.power_hp) : s.power_hp,
  };
}

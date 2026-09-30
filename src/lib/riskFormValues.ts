import type { RiskField } from "@/lib/riskSchema";
import type { VehicleReference, VehicleSelection } from "@/lib/vehicles";

/**
 * Shared by every risk-schema form (customer quote wizard app/quote/risk.tsx, agent assisted sale
 * app/agent/sales/new.tsx) so both render and send the same answers.
 */

/** Localized options from the vehicle reference (EN/FR) for body type, fuel, usage, … */
export function withReferenceOptions(field: RiskField, reference: VehicleReference | null): RiskField {
  const rows = field.reference && reference ? (reference as unknown as Record<string, { code: string; label: string }[]>)[field.reference] : undefined;
  return rows?.length ? { ...field, options: rows.map((r) => ({ value: r.code, label: r.label })) } : field;
}

/** The vehicle currently picked in the form values (make/model codes + snapshot text). */
export function selectionFromValues(values: Record<string, string>): VehicleSelection | null {
  if (!values.make) return null;
  return {
    make_code: values.make_code || undefined,
    make: values.make,
    model_code: values.model_code || undefined,
    model: values.model ?? "",
    year: values.year || undefined,
    manual: !values.make_code || !values.model_code,
    review_id: values.vehicle_review_id || undefined,
  };
}

/** Keys a new vehicle selection replaces (the previous vehicle's codes and snapshot text are cleared first). */
export const CLEARED_VEHICLE_VALUES = { make_code: "", model_code: "", make: "", model: "", vehicle_review_id: "", vehicle_generation_code: "", vehicle_variant_code: "" } as const;

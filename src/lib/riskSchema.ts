/**
 * Risk questionnaire schemas (multi-step wizard).
 *
 * The server's GET /mobile/catalogue/lines/{code}/risk-schema is the source
 * of truth; LOCAL_SCHEMAS is the fallback when that endpoint is absent or
 * fails. Keys marked in the seeded line risk_schema.required
 * (registration_number, fiscal_power, usage_type, zone for MOTOR, …) are
 * always present and required here so rating never fails for a missing fact.
 *
 * Dependency-free (tests load it with Node type stripping).
 */

export type RiskFieldType = "text" | "number" | "money" | "select" | "date" | "boolean";
export type RiskOption = { value: string; label: string };
export type RiskField = {
  key: string;
  label: string;
  type: RiskFieldType;
  options?: RiskOption[];
  required?: boolean;
  placeholder?: string;
  min?: number;
  max?: number;
  help?: string;
};
export type RiskStep = { key: string; title: string; fields: RiskField[] };
export type RiskSchema = { line_code: string; steps: RiskStep[]; source: "server" | "local" };

const opts = (...pairs: [string, string][]): RiskOption[] => pairs.map(([value, label]) => ({ value, label }));
const year = new Date().getFullYear();

const ZONES = opts(["CAMEROON", "Cameroon (national)"], ["DOUALA", "Douala"], ["YAOUNDE", "Yaoundé"], ["CEMAC", "CEMAC zone"]);

export const LOCAL_SCHEMAS: Record<string, RiskStep[]> = {
  MOTOR: [
    { key: "vehicle", title: "Vehicle", fields: [
      { key: "registration_number", label: "Registration number", type: "text", required: true, placeholder: "LT 000 AA" },
      { key: "make", label: "Make", type: "text", placeholder: "Toyota" },
      { key: "model", label: "Model", type: "text", placeholder: "Corolla" },
      { key: "year", label: "Year of manufacture", type: "number", min: 1950, max: year + 1, placeholder: String(year - 5) },
      { key: "fiscal_power", label: "Fiscal power (CV)", type: "number", required: true, min: 1, max: 60, placeholder: "8" },
    ] },
    { key: "usage", title: "Usage", fields: [
      { key: "usage_type", label: "How is the vehicle used?", type: "select", required: true, options: opts(["PRIVATE", "Private use"], ["COMMERCIAL", "Commercial / goods"], ["TAXI", "Taxi / passenger transport"], ["MOTORCYCLE_TAXI", "Motorcycle taxi"]) },
      { key: "zone", label: "Main driving zone", type: "select", required: true, options: ZONES },
      { key: "seats", label: "Number of seats", type: "number", min: 1, max: 80, placeholder: "5" },
    ] },
    { key: "owner", title: "Owner & driver", fields: [
      { key: "owner_type", label: "Registered owner", type: "select", options: opts(["SELF", "Me"], ["COMPANY", "My company"], ["OTHER", "Someone else"]) },
      { key: "main_driver_birth_date", label: "Main driver's date of birth", type: "date" },
      { key: "licence_issued_on", label: "Driving licence issue date", type: "date" },
    ] },
    { key: "cover", title: "Cover", fields: [
      { key: "cover_type", label: "Cover level", type: "select", options: opts(["THIRD_PARTY", "Third-party liability (compulsory)"], ["THIRD_PARTY_FIRE_THEFT", "Third party, fire & theft"], ["COMPREHENSIVE", "Comprehensive"]) },
      { key: "vehicle_value_minor", label: "Vehicle value (FCFA)", type: "money", min: 0, placeholder: "5000000" },
      { key: "start_date", label: "Cover start date", type: "date" },
    ] },
    { key: "history", title: "History", fields: [
      { key: "claims_last_3_years", label: "Claims in the last 3 years", type: "number", min: 0, max: 50, placeholder: "0" },
      { key: "previously_insured", label: "Was the vehicle insured before?", type: "boolean" },
      { key: "previous_insurer", label: "Previous insurer (optional)", type: "text" },
    ] },
  ],
  HEALTH: [
    { key: "people", title: "Who needs cover", fields: [
      { key: "plan_type", label: "Plan type", type: "select", required: true, options: opts(["INDIVIDUAL", "Individual"], ["FAMILY", "Family"], ["GROUP", "Employee group"]) },
      { key: "beneficiary_count", label: "Number of people", type: "number", required: true, min: 1, max: 500, placeholder: "1" },
      { key: "oldest_age", label: "Age of the oldest person", type: "number", required: true, min: 0, max: 100, placeholder: "35" },
    ] },
    { key: "cover", title: "Cover", fields: [
      { key: "coverage_zone", label: "Where should cover apply?", type: "select", required: true, options: opts(["CAMEROON", "Cameroon"], ["CEMAC", "CEMAC zone"], ["AFRICA", "Africa"], ["WORLDWIDE", "Worldwide"]) },
      { key: "start_date", label: "Cover start date", type: "date" },
    ] },
  ],
  TRAVEL: [
    { key: "trip", title: "Trip", fields: [
      { key: "destination_country", label: "Destination country", type: "text", required: true, placeholder: "France" },
      { key: "departure_date", label: "Departure date", type: "date", required: true },
      { key: "return_date", label: "Return date", type: "date", required: true },
      { key: "traveller_count", label: "Number of travellers", type: "number", required: true, min: 1, max: 50, placeholder: "1" },
    ] },
  ],
  HOME: [
    { key: "property", title: "Property", fields: [
      { key: "property_type", label: "Property type", type: "select", required: true, options: opts(["HOUSE", "House"], ["APARTMENT", "Apartment"], ["VILLA", "Villa"]) },
      { key: "occupancy", label: "Occupancy", type: "select", required: true, options: opts(["OWNER_OCCUPIED", "I own and live in it"], ["RENTED", "I rent it"], ["LANDLORD", "I rent it out"]) },
      { key: "city", label: "City", type: "text", required: true, placeholder: "Douala" },
    ] },
    { key: "cover", title: "Cover", fields: [
      { key: "declared_value_minor", label: "Declared value (FCFA)", type: "money", required: true, min: 1, placeholder: "25000000" },
      { key: "start_date", label: "Cover start date", type: "date" },
    ] },
  ],
  LIFE: [
    { key: "insured", title: "Insured person", fields: [
      { key: "insured_age", label: "Age of insured person", type: "number", required: true, min: 18, max: 80, placeholder: "35" },
      { key: "purpose", label: "Protection purpose", type: "select", required: true, options: opts(["FAMILY_PROTECTION", "Family protection"], ["SAVINGS", "Savings"], ["EDUCATION", "Children's education"], ["LOAN_COVER", "Loan cover"]) },
    ] },
    { key: "cover", title: "Cover", fields: [
      { key: "cover_amount_minor", label: "Desired cover (FCFA)", type: "money", required: true, min: 1, placeholder: "10000000" },
      { key: "term_years", label: "Term in years", type: "number", required: true, min: 1, max: 40, placeholder: "10" },
    ] },
  ],
  BUSINESS: [
    { key: "business", title: "Business", fields: [
      { key: "business_name", label: "Business name", type: "text", required: true },
      { key: "activity_sector", label: "Activity sector", type: "select", required: true, options: opts(["RETAIL", "Retail / shop"], ["SERVICES", "Services / office"], ["HOSPITALITY", "Hotel / restaurant"], ["MANUFACTURING", "Manufacturing"], ["TRANSPORT", "Transport"], ["AGRICULTURE", "Agriculture"]) },
      { key: "city", label: "City", type: "text", required: true, placeholder: "Douala" },
      { key: "employee_count", label: "Number of employees", type: "number", required: true, min: 0, max: 100000, placeholder: "5" },
    ] },
    { key: "cover", title: "Cover", fields: [
      { key: "cover_type", label: "What should be covered?", type: "select", required: true, options: opts(["PROPERTY", "Premises & stock"], ["LIABILITY", "Liability"], ["MULTIRISK", "Multi-risk (property + liability)"]) },
      { key: "insured_value_minor", label: "Value to insure (FCFA)", type: "money", required: true, min: 1, placeholder: "20000000" },
      { key: "annual_turnover_minor", label: "Annual turnover (FCFA)", type: "money", min: 0 },
      { key: "start_date", label: "Cover start date", type: "date" },
    ] },
  ],
  ACCIDENT: [
    { key: "insured", title: "Insured person", fields: [
      { key: "insured_age", label: "Age of insured person", type: "number", required: true, min: 0, max: 80, placeholder: "35" },
      { key: "occupation_class", label: "Occupation", type: "select", required: true, options: opts(["OFFICE", "Office / low risk"], ["MANUAL", "Manual work"], ["HIGH_RISK", "High-risk work (construction, mining…)"]) },
    ] },
    { key: "cover", title: "Cover", fields: [
      { key: "cover_amount_minor", label: "Cover amount (FCFA)", type: "money", required: true, min: 1, placeholder: "5000000" },
      { key: "start_date", label: "Cover start date", type: "date" },
    ] },
  ],
};

const FIELD_TYPES: RiskFieldType[] = ["text", "number", "money", "select", "date", "boolean"];

/** Parses the server risk-schema payload; returns null when unusable. */
export function normalizeRiskSchema(payload: unknown, lineCode: string): RiskSchema | null {
  const body = payload && typeof payload === "object" ? (payload as Record<string, unknown>) : null;
  const root = body && body.data && typeof body.data === "object" && !Array.isArray(body.data) ? (body.data as Record<string, unknown>) : body;
  const rawSteps = root && Array.isArray(root.steps) ? root.steps : null;
  if (!rawSteps || !rawSteps.length) return null;
  const steps: RiskStep[] = rawSteps
    .map((s, i) => {
      const step = (s ?? {}) as Record<string, unknown>;
      const fields = (Array.isArray(step.fields) ? step.fields : [])
        .map((f) => {
          const x = (f ?? {}) as Record<string, unknown>;
          const key = typeof x.key === "string" ? x.key : "";
          if (!key) return null;
          const t = String(x.type ?? "text").toLowerCase();
          const type: RiskFieldType = FIELD_TYPES.includes(t as RiskFieldType)
            ? (t as RiskFieldType)
            : t === "integer" || t === "decimal" ? "number" : t === "enum" ? "select" : t === "bool" || t === "checkbox" ? "boolean" : "text";
          const options = Array.isArray(x.options)
            ? x.options.map((o) =>
                typeof o === "string"
                  ? { value: o, label: o.replace(/_/g, " ").toLowerCase().replace(/^\w/, (c) => c.toUpperCase()) }
                  : { value: String((o as Record<string, unknown>)?.value ?? ""), label: String((o as Record<string, unknown>)?.label ?? (o as Record<string, unknown>)?.value ?? "") },
              ).filter((o) => o.value)
            : undefined;
          const field: RiskField = {
            key,
            label: typeof x.label === "string" ? x.label : key,
            type: type === "select" && !options?.length ? "text" : type,
            options,
            required: x.required === true,
            placeholder: typeof x.placeholder === "string" ? x.placeholder : undefined,
            min: typeof x.min === "number" ? x.min : undefined,
            max: typeof x.max === "number" ? x.max : undefined,
            help: typeof x.help === "string" ? x.help : undefined,
          };
          return field;
        })
        .filter((f): f is RiskField => f !== null);
      return { key: typeof step.key === "string" ? step.key : `step_${i}`, title: typeof step.title === "string" ? step.title : `Step ${i + 1}`, fields };
    })
    .filter((s) => s.fields.length > 0);
  if (!steps.length) return null;
  return { line_code: String(root?.line_code ?? lineCode).toUpperCase(), steps, source: "server" };
}

export function localRiskSchema(lineCode: string): RiskSchema | null {
  const code = lineCode.toUpperCase();
  const steps = LOCAL_SCHEMAS[code];
  return steps ? { line_code: code, steps, source: "local" } : null;
}

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;
export function isValidIsoDate(v: string) {
  const m = ISO_DATE.exec(v);
  if (!m) return false;
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  return d.getUTCFullYear() === Number(m[1]) && d.getUTCMonth() === Number(m[2]) - 1 && d.getUTCDate() === Number(m[3]);
}

/** Returns an error message or null. */
export function validateField(field: RiskField, raw: string | undefined): string | null {
  const value = (raw ?? "").trim();
  if (!value) return field.required ? `${field.label} is required.` : null;
  switch (field.type) {
    case "number":
    case "money": {
      const n = Number(value.replace(/\s/g, ""));
      if (!Number.isFinite(n)) return "Enter a number.";
      if (field.min !== undefined && n < field.min) return `Must be at least ${field.min}.`;
      if (field.max !== undefined && n > field.max) return `Must be at most ${field.max}.`;
      return null;
    }
    case "date":
      return isValidIsoDate(value) ? null : "Choose a valid date.";
    case "select":
      return field.options?.some((o) => o.value === value) ? null : "Choose an option.";
    case "boolean":
      return value === "true" || value === "false" ? null : "Choose yes or no.";
    default:
      return value.length > 200 ? "Too long." : null;
  }
}

export function validateStep(step: RiskStep, values: Record<string, string>) {
  const errors: Record<string, string> = {};
  for (const f of step.fields) {
    const e = validateField(f, values[f.key]);
    if (e) errors[f.key] = e;
  }
  // Cross-field rule: a trip cannot end before it starts.
  const dep = values.departure_date;
  const ret = values.return_date;
  if (dep && ret && isValidIsoDate(dep) && isValidIsoDate(ret) && ret < dep && step.fields.some((f) => f.key === "return_date"))
    errors.return_date = "Return date must be after departure.";
  return errors;
}

/** Converts wizard strings to typed risk facts (money FCFA → minor units). */
export function buildFacts(schema: RiskSchema, values: Record<string, string>) {
  const facts: Record<string, unknown> = {};
  for (const step of schema.steps)
    for (const f of step.fields) {
      const raw = (values[f.key] ?? "").trim();
      if (!raw) continue;
      switch (f.type) {
        case "number": facts[f.key] = Number(raw.replace(/\s/g, "")); break;
        case "money": facts[f.key] = Math.round(Number(raw.replace(/\s/g, "")) * 100); break;
        case "boolean": facts[f.key] = raw === "true"; break;
        case "date":
        case "select": facts[f.key] = raw; break;
        default: facts[f.key] = raw.toUpperCase();
      }
    }
  return facts;
}

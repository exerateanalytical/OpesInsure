/**
 * Server-driven forms (GET /forms/{form}, InputFieldContract v1): customer
 * profile + beneficiaries, KYC identifier / document, claim FNOL, agent lead.
 * The same field contract as the risk wizard (parsed by riskSchema.ts), so
 * one renderer serves both. Pure helpers only (tests load this with Node
 * type stripping).
 */
import { allFields, fieldFacts, isFieldVisible, normalizeRiskSchema, type RiskField, type RiskStep } from "./riskSchema.ts";
import type { Lang, MasterValue } from "./masterFields.ts";

export type FormName = "customer_profile" | "kyc_identifier" | "kyc_document" | "claim_fnol" | "agent_lead";
export type FormSchema = {
  form: string;
  version: number;
  /** "PATCH /api/v1/mobile/kyc/profile" -> {method, path relative to /api/v1}. */
  submitTo: { method: string; path: string } | null;
  steps: RiskStep[];
};

/** "/api/v1/policies" -> "/policies" (the API client already targets /api/v1). */
export function apiPath(endpoint: string) {
  return endpoint.replace(/^https?:\/\/[^/]+/, "").replace(/^\/api\/v1(?=\/)/, "");
}

export function parseSubmitTo(raw: unknown): FormSchema["submitTo"] {
  if (typeof raw !== "string") return null;
  const m = /^(GET|POST|PUT|PATCH|DELETE)\s+(\S+)$/i.exec(raw.trim());
  return m ? { method: m[1]!.toUpperCase(), path: apiPath(m[2]!) } : null;
}

export function normalizeFormSchema(payload: unknown, form: string): FormSchema | null {
  const body = payload && typeof payload === "object" ? (payload as Record<string, unknown>) : null;
  const root = body && body.data && typeof body.data === "object" && !Array.isArray(body.data) ? (body.data as Record<string, unknown>) : body;
  const parsed = normalizeRiskSchema(root, form);
  if (!parsed) return null;
  return {
    form: typeof root?.form === "string" ? root.form : form,
    version: typeof root?.version === "number" ? root.version : 1,
    submitTo: parseSubmitTo(root?.submit_to),
    steps: parsed.steps,
  };
}

/** Defaults from the contract ("default": "CM"), under any values already known. */
export function initialFormValues(schema: Pick<FormSchema, "steps">, seed: Record<string, string> = {}) {
  const out: Record<string, string> = {};
  for (const f of allFields(schema)) if (f.defaultValue !== undefined) out[f.key] = f.defaultValue;
  for (const [k, v] of Object.entries(seed)) if (v !== undefined && v !== null && v !== "") out[k] = v;
  return out;
}

/** Helpers composed into a text target, in order (compose_from, else every field with compose_into = target, nearest first). */
export function composeSources(target: RiskField, fields: RiskField[]) {
  if (target.composeFrom?.length) return target.composeFrom;
  return fields.filter((f) => f.composeInto === target.key).map((f) => f.key).reverse();
}

/**
 * "Landmark, City, Department, Region": the typed text first, then the
 * display labels of the helper pickers (an "Other" pick contributes its text).
 */
export function composeValue(target: RiskField, fields: RiskField[], values: Record<string, string>, labels: Record<string, string>) {
  const parts = [(values[target.key] ?? "").trim()];
  for (const key of composeSources(target, fields)) {
    const other = (values[`${key}_other`] ?? "").trim();
    parts.push(values[key] === "OTHER" && other ? other : (labels[key] ?? "").trim() || (values[key] ?? "").trim());
  }
  return parts.filter(Boolean).join(", ");
}

/**
 * Request body for submit_to: visible fields except helpers (submit:false),
 * typed like risk facts; composed text targets carry the helper labels.
 */
export function buildFormPayload(schema: Pick<FormSchema, "steps">, values: Record<string, string>, labels: Record<string, string> = {}) {
  const fields = allFields(schema);
  const out: Record<string, unknown> = {};
  for (const f of fields) {
    if (f.submit === false || !isFieldVisible(f, values)) continue;
    const composed = f.composeFrom?.length || fields.some((x) => x.composeInto === f.key);
    if (composed) {
      const text = composeValue(f, fields, values, labels);
      if (text) out[f.key] = text;
      continue;
    }
    Object.assign(out, fieldFacts(f.type === "text" ? { ...f, freeText: f.freeText ?? "UNCLASSIFIED" } : f, values));
  }
  return out;
}

const localized = (v: unknown, lang: Lang): string | undefined => {
  if (typeof v === "string" && v) return v;
  if (v && typeof v === "object") {
    const o = v as Record<string, unknown>;
    const s = o[lang] ?? o.en ?? o.fr;
    return typeof s === "string" && s ? s : undefined;
  }
  return undefined;
};

/** Rows of an endpoint picker (policies, insurance lines, insurer register) as picker values. */
export function endpointValues(payload: unknown, valueKey: string): MasterValue[] {
  const body = payload && typeof payload === "object" ? (payload as Record<string, unknown>) : null;
  const inner = body && "data" in body ? body.data : payload;
  const rows: unknown[] = Array.isArray(inner)
    ? inner
    : inner && typeof inner === "object" && Array.isArray((inner as Record<string, unknown>).data)
      ? ((inner as Record<string, unknown>).data as unknown[])
      : inner && typeof inner === "object" && Array.isArray((inner as Record<string, unknown>).items)
        ? ((inner as Record<string, unknown>).items as unknown[])
        : [];
  const out: MasterValue[] = [];
  for (const r of rows) {
    if (!r || typeof r !== "object") continue;
    const row = r as Record<string, unknown>;
    const code = row[valueKey];
    if (typeof code !== "string" && typeof code !== "number") continue;
    const label = (lang: Lang) =>
      [localized(row.policy_number, lang) ?? localized(row.name, lang) ?? localized(row.label, lang) ?? localized(row.title, lang) ?? String(code),
        typeof row.policy_number === "string" ? localized(row.product_name ?? row.line_name ?? row.certificate_number, lang) : undefined]
        .filter(Boolean)
        .join(" · ");
    const aliases = [row.code, row.short_name, row.initials].filter((a): a is string => typeof a === "string");
    out.push({ code: String(code), label: { en: label("en"), fr: label("fr") }, aliases, attributes: { status: row.status } });
  }
  return out;
}

/** Profile form values from GET /mobile/account/customer-profile. */
export function profileToValues(p: Record<string, unknown> | null | undefined): Record<string, string> {
  if (!p) return {};
  const s = (v: unknown) => (typeof v === "string" ? v : "");
  const beneficiaries = Array.isArray(p.beneficiaries)
    ? p.beneficiaries
        .filter((b): b is Record<string, unknown> => !!b && typeof b === "object")
        .map((b) => ({ name: s(b.name ?? b.full_name), relationship: s(b.relationship), share_percent: b.share_percent === undefined || b.share_percent === null ? "" : String(b.share_percent) }))
    : [];
  return {
    date_of_birth: s(p.date_of_birth).slice(0, 10),
    occupation: s(p.occupation),
    region: s(p.region),
    city: s(p.city),
    address_line1: s(p.address_line1),
    beneficiaries: beneficiaries.length ? JSON.stringify(beneficiaries) : "",
  };
}

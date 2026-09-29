/**
 * Read-only summaries of server forms (GET /forms/{form}): the "filled form
 * reads as filled" view used by Personal information and Beneficiaries.
 * Codes become human labels (master lists, select options), dates read as
 * "12 March 1990", repeaters list one line per item. Pure: node-tested
 * (tests/form-summary.test.mjs).
 */
import { isFieldVisible, type RiskField } from "./riskSchema.ts";
import { OTHER, type Lang } from "./masterFields.ts";

export type SummaryRow = {
  key: string;
  label: string;
  /** null = nothing on file (the screen shows an "Add …" prompt). */
  value: string | null;
  /** Repeater rows (beneficiaries), one readable line each. */
  items?: string[];
  /** Yes/no answers (boolean fields): the review shows them as a chip so a "No" is as visible as a "Yes". */
  answer?: "yes" | "no";
};

/** Resolves a master-list code to its label (undefined when the list is not loaded or the code is unknown). */
export type LabelResolver = (field: RiskField, code: string) => string | undefined;
/** `money` formats whole FCFA (the app's formatXaf); without it amounts read "1 500 000 FCFA"-style digits. */
export type SummaryCopy = { other: string; yes: string; no: string; money?: (fcfa: number) => string };

export const fieldLabel = (f: Pick<RiskField, "label" | "labelFr">, lang: Lang) => (lang === "fr" && f.labelFr ? f.labelFr : f.label);

/** "1990-03-12" -> "12 March 1990" / "12 mars 1990"; the calendar day never shifts with the time zone. */
export function formatLongDate(value: string | null | undefined, lang: Lang): string | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value ?? "").trim());
  if (!m) return null;
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  if (Number.isNaN(d.getTime())) return null;
  return new Intl.DateTimeFormat(lang === "fr" ? "fr-FR" : "en-GB", { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" }).format(d);
}

/** "SMALL_TRADER" -> "Small trader"; free text (older rows typed by hand) is kept as typed. */
export function humanizeCode(raw: string): string {
  if (!/^[A-Z0-9_]+$/.test(raw) || !/[A-Z]/.test(raw)) return raw;
  const words = raw.toLowerCase().replace(/_+/g, " ").trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

const parseJsonArray = (raw: string): unknown[] => {
  try {
    const v = JSON.parse(raw);
    return Array.isArray(v) ? v : [];
  } catch {
    return [];
  }
};

/** One field's value in words, or null when empty. */
export function summarizeValue(f: RiskField, raw: string | undefined, values: Record<string, string>, lang: Lang, resolve: LabelResolver, copy: SummaryCopy): string | null {
  const v = String(raw ?? "").trim();
  if (!v) return null;
  switch (f.type) {
    case "date":
      return formatLongDate(v, lang) ?? v;
    case "boolean":
      return v === "true" ? copy.yes : copy.no;
    case "number":
      return /percent|_pct$/i.test(f.key) ? `${v}%` : v;
    case "money": {
      // Wizard values are whole FCFA as typed ("5 000 000"); the server gets minor units.
      const n = Number(v.replace(/\s/g, "")); // \s includes the no-break spaces of fr-CM grouping
      if (!Number.isFinite(n)) return v;
      return copy.money ? copy.money(n) : `${n} FCFA`;
    }
    case "vehicle_make":
    case "vehicle_model":
      // Picker codes carry the chosen name in their snapshot key (make, model).
      return (f.textKey ? String(values[f.textKey] ?? "").trim() : "") || humanizeCode(v);
    case "select":
    case "vehicle_generation":
    case "vehicle_variant":
      return f.options?.find((o) => o.value === v)?.label ?? humanizeCode(v);
    case "select_master":
      if (v === OTHER) return (values[`${f.key}_other`] ?? "").trim() || copy.other;
      return resolve(f, v) ?? humanizeCode(v);
    case "multi_select_master": {
      const codes = parseJsonArray(v).map(String).filter(Boolean);
      const labels = codes.map((c) => (c === OTHER ? (values[`${f.key}_other`] ?? "").trim() || copy.other : resolve(f, c) ?? humanizeCode(c)));
      return labels.length ? labels.join(", ") : null;
    }
    default:
      return v;
  }
}

/** Repeater items as lines: "Marie Ndongo · Spouse · 60%". */
export function summarizeItems(f: RiskField, raw: string | undefined, lang: Lang, resolve: LabelResolver, copy: SummaryCopy): string[] {
  const out: string[] = [];
  for (const item of parseJsonArray(String(raw ?? ""))) {
    if (!item || typeof item !== "object") continue;
    const map = Object.fromEntries(Object.entries(item as Record<string, unknown>).map(([k, x]) => [k, x === null || x === undefined ? "" : String(x)]));
    const parts = (f.itemFields ?? []).map((sub) => summarizeValue(sub, map[sub.key], map, lang, resolve, copy)).filter((x): x is string => !!x);
    if (parts.length) out.push(parts.join(" · "));
  }
  return out;
}

/**
 * Summary rows for the given fields. Helper pickers the server does not
 * store (submit:false, e.g. department) are left out.
 */
export function summarizeFields(fields: RiskField[], values: Record<string, string>, lang: Lang, resolve: LabelResolver, copy: SummaryCopy): SummaryRow[] {
  return fields
    .filter((f) => f.submit !== false && f.type !== "file")
    .map((f) => {
      const label = fieldLabel(f, lang);
      if (f.type === "repeater") {
        const items = summarizeItems(f, values[f.key], lang, resolve, copy);
        return { key: f.key, label, value: items.length ? String(items.length) : null, items };
      }
      const value = summarizeValue(f, values[f.key], values, lang, resolve, copy);
      return f.type === "boolean" && value ? { key: f.key, label, value, answer: values[f.key] === "true" ? ("yes" as const) : ("no" as const) } : { key: f.key, label, value };
    });
}

/**
 * Review rows for a wizard step or form: only the fields the customer saw
 * (visible_if / visibleWhen honoured), in the same order and with the same
 * labels and option labels, codes in words.
 */
export function reviewRows(fields: RiskField[], values: Record<string, string>, lang: Lang, resolve: LabelResolver, copy: SummaryCopy): SummaryRow[] {
  return summarizeFields(
    fields.filter((f) => isFieldVisible(f, values)),
    values,
    lang,
    resolve,
    copy,
  );
}

/** True when any of the keys holds a value (an empty JSON list counts as empty). */
export function anyFilled(values: Record<string, string> | null | undefined, keys: string[]): boolean {
  if (!values) return false;
  return keys.some((k) => {
    const v = String(values[k] ?? "").trim();
    return v !== "" && v !== "[]";
  });
}

/**
 * Restricts a form payload to the fields a section edits, and sends null for
 * the ones the customer emptied so a cleared value is really cleared (the
 * server leaves absent keys untouched).
 */
export function sectionPayload(payload: Record<string, unknown>, fields: Pick<RiskField, "key" | "submit" | "type">[], only: string[]): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(payload)) if (only.includes(k) || only.includes(k.replace(/_other$/, ""))) out[k] = v;
  for (const f of fields) {
    if (!only.includes(f.key) || f.submit === false || f.key in out) continue;
    out[f.key] = f.type === "repeater" || f.type === "multi_select_master" ? [] : null;
  }
  return out;
}

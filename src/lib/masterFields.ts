/**
 * Institutional master data in the quote wizard — pure helpers (no React / RN
 * imports; tests load this with Node type stripping).
 *
 * Wizard values stay strings: a multi-select is a JSON array string, a
 * repeater a JSON array of item-value maps. "Other / Not listed" stores the
 * code OTHER plus the typed text in `{key}_other` (filed for review, never
 * blocking the quote).
 */

export type MasterSource = { domain: string; list: string };
export type MasterValue = {
  code: string;
  label: { en: string; fr: string };
  parent?: string;
  aliases?: string[];
  attributes?: Record<string, unknown>;
  is_other?: boolean;
  common?: boolean;
};
export type VisibleIf = Record<string, unknown>;
export type Allocation = { field: string; total: number; group_by?: string };
export type Lang = "en" | "fr";

export const OTHER = "OTHER";

/** Accent/case/punctuation-insensitive key ("Médecin" → "medecin"). */
export function normalizeText(s: string | undefined | null): string {
  return String(s ?? "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/œ/gi, "oe")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, " ")
    .trim();
}

export function labelOf(v: MasterValue, lang: Lang) {
  return (lang === "fr" ? v.label.fr : v.label.en) || v.label.en || v.code;
}

/** Values allowed under the selected parent (hierarchies: sector → activity, region → department, …). */
export function narrowByParent(values: MasterValue[], parent?: string | null) {
  if (!parent || parent === OTHER) return values;
  return values.filter((v) => {
    if (v.is_other || !v.parent || v.parent === parent) return true;
    const also = (v.attributes?.also_parents ?? v.attributes?.categories) as unknown;
    return Array.isArray(also) && also.includes(parent);
  });
}

/**
 * Ranking mirrors the server: common (Cameroon) → exact → prefix → alias →
 * contains → typo-tolerant. Empty query keeps every value (rare values are
 * never hidden). "Other / Not listed" always last.
 */
export function searchMasterValues(values: MasterValue[], q: string, lang: Lang): MasterValue[] {
  const needle = normalizeText(q);
  const other = values.filter((v) => v.is_other || v.code === OTHER);
  const rest = values.filter((v) => !(v.is_other || v.code === OTHER));
  if (!needle) return [...rest, ...other];
  const scored = rest
    .map((v) => {
      const own = normalizeText(labelOf(v, lang));
      const alt = normalizeText(labelOf(v, lang === "fr" ? "en" : "fr"));
      const code = normalizeText(v.code.replace(/_/g, " "));
      const aliases = (v.aliases ?? []).map(normalizeText);
      let score = 0;
      if ([own, alt, code].includes(needle)) score = 1000;
      else if (own.startsWith(needle) || alt.startsWith(needle)) score = 800;
      else if (aliases.includes(needle)) score = 700;
      else if (aliases.some((a) => a.startsWith(needle))) score = 600;
      else if ([own, alt, code, ...aliases].some((s) => s.includes(needle))) score = 400;
      else score = fuzzy(needle, [own, alt, ...aliases].join(" "));
      return { v, score: score ? score + (v.common ? 40 : 0) : 0 };
    })
    .filter((x) => x.score > 0)
    .sort((a, b) => b.score - a.score || labelOf(a.v, lang).localeCompare(labelOf(b.v, lang)));
  return [...scored.map((x) => x.v), ...other];
}

function fuzzy(needle: string, haystack: string) {
  const words = haystack.split(" ").filter(Boolean);
  let total = 0;
  for (const q of needle.split(" ")) {
    if (q.length < 3) continue;
    let best = Infinity;
    for (const w of words) best = Math.min(best, levenshtein(q, w.length > q.length + 2 ? w.slice(0, q.length) : w));
    if (best > (q.length <= 4 ? 1 : 2)) return 0;
    total += 300 - best * 50;
  }
  return total;
}

export function levenshtein(a: string, b: string) {
  const dp = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    let prev = dp[0] ?? 0;
    dp[0] = i;
    for (let j = 1; j <= b.length; j++) {
      const tmp = dp[j] ?? 0;
      dp[j] = Math.min(tmp + 1, (dp[j - 1] ?? 0) + 1, prev + (a[i - 1] === b[j - 1] ? 0 : 1));
      prev = tmp;
    }
  }
  return dp[b.length] ?? 0;
}

/** Groups values under their parent label for grouped pickers (when no parent is selected). */
export function groupByParent(values: MasterValue[], parents: MasterValue[] | undefined, lang: Lang) {
  if (!parents?.length || !values.some((v) => v.parent)) return [{ title: "", data: values }];
  const byCode = new Map(parents.map((p) => [p.code, labelOf(p, lang)]));
  const groups = new Map<string, MasterValue[]>();
  for (const v of values) {
    const title = v.parent ? byCode.get(v.parent) ?? v.parent : "";
    groups.set(title, [...(groups.get(title) ?? []), v]);
  }
  return [...groups.entries()].map(([title, data]) => ({ title, data }));
}

export function parseList(raw: string | undefined): string[] {
  if (!raw) return [];
  try {
    const v = JSON.parse(raw);
    return Array.isArray(v) ? v.map(String) : [];
  } catch {
    return raw ? [raw] : [];
  }
}

export type RepeaterItem = Record<string, string>;
export function parseItems(raw: string | undefined): RepeaterItem[] {
  if (!raw) return [];
  try {
    const v = JSON.parse(raw);
    return Array.isArray(v) ? v.filter((x) => x && typeof x === "object") : [];
  } catch {
    return [];
  }
}

/**
 * visible_if: {field: value | [values] | {not_empty:true} | {contains:x}} —
 * every condition must hold. Values are wizard strings ("true"/"false" for booleans).
 */
export function visibleIf(cond: VisibleIf | undefined, values: Record<string, string>) {
  if (!cond) return true;
  return Object.entries(cond).every(([field, c]) => {
    const v = values[field] ?? "";
    if (c && typeof c === "object" && !Array.isArray(c)) {
      const o = c as Record<string, unknown>;
      if ("not_empty" in o) return (v !== "" && v !== "[]") === Boolean(o.not_empty);
      if ("contains" in o) return parseList(v).includes(String(o.contains));
      return true;
    }
    if (Array.isArray(c)) return c.map(String).includes(v);
    if (typeof c === "boolean") return v === String(c);
    return v === String(c ?? "");
  });
}

export type MasterFieldLike = {
  key: string;
  label: string;
  type: string;
  required?: boolean;
  otherAllowed?: boolean;
  itemFields?: MasterFieldLike[];
  allocation?: Allocation;
  minItems?: number;
  maxItems?: number;
  min?: number;
  max?: number;
  pattern?: string;
  visibleIf?: VisibleIf;
};

const t = (lang: Lang, en: string, fr: string) => (lang === "fr" ? fr : en);

/** Validation for select_master / multi_select_master / repeater / file fields. */
export function validateMasterField(field: MasterFieldLike, values: Record<string, string>, lang: Lang = "en"): string | null {
  const raw = (values[field.key] ?? "").trim();
  if (field.type === "select_master") {
    if (!raw) return field.required ? t(lang, `${field.label} is required.`, `${field.label} : champ obligatoire.`) : null;
    if (raw === OTHER && !(values[`${field.key}_other`] ?? "").trim()) return t(lang, "Describe the value that is not listed.", "Précisez la valeur non répertoriée.");
    return null;
  }
  if (field.type === "multi_select_master") {
    const list = parseList(raw);
    if (!list.length) return field.required ? t(lang, `Choose at least one ${field.label.toLowerCase()}.`, `Choisissez au moins une option.`) : null;
    if (list.includes(OTHER) && !(values[`${field.key}_other`] ?? "").trim()) return t(lang, "Describe the value that is not listed.", "Précisez la valeur non répertoriée.");
    return null;
  }
  if (field.type === "repeater") return validateRepeater(field, raw, lang).error;
  if (field.type === "file") return null;
  return null;
}

export function validateRepeater(field: MasterFieldLike, raw: string, lang: Lang = "en") {
  const items = parseItems(raw);
  const itemErrors: Record<string, string>[] = [];
  const min = field.minItems ?? (field.required ? 1 : 0);
  if (items.length < min) return { error: t(lang, `Add at least ${min}.`, `Ajoutez-en au moins ${min}.`), itemErrors };
  if (field.maxItems !== undefined && items.length > field.maxItems) return { error: t(lang, `At most ${field.maxItems}.`, `Au maximum ${field.maxItems}.`), itemErrors };
  let bad = false;
  items.forEach((item, i) => {
    const errs: Record<string, string> = {};
    for (const sub of field.itemFields ?? []) {
      const v = (item[sub.key] ?? "").trim();
      if (!v) {
        if (sub.required) errs[sub.key] = t(lang, "Required.", "Obligatoire.");
        continue;
      }
      if (sub.type === "select_master" && v === OTHER && !(item[`${sub.key}_other`] ?? "").trim()) errs[sub.key] = t(lang, "Describe it.", "Précisez.");
      if ((sub.type === "number" || sub.type === "money") && !Number.isFinite(Number(v))) errs[sub.key] = t(lang, "Enter a number.", "Saisissez un nombre.");
      if (sub.type === "number" && sub.min !== undefined && Number(v) < sub.min) errs[sub.key] = `≥ ${sub.min}`;
      if (sub.type === "number" && sub.max !== undefined && Number(v) > sub.max) errs[sub.key] = `≤ ${sub.max}`;
      if (sub.type === "date" && !/^\d{4}-\d{2}-\d{2}$/.test(v)) errs[sub.key] = t(lang, "Choose a valid date.", "Date invalide.");
    }
    if (Object.keys(errs).length) bad = true;
    itemErrors[i] = errs;
  });
  if (bad) return { error: t(lang, "Complete every entry.", "Complétez chaque ligne."), itemErrors };
  if (field.allocation) {
    const totals = allocationTotals(field.allocation, items);
    for (const [group, sum] of Object.entries(totals))
      if (Math.abs(sum - field.allocation.total) > 0.001)
        return {
          error: t(lang, `Shares${group === "_" ? "" : ` (${group.toLowerCase()})`} must total ${field.allocation.total}% — currently ${sum}%.`,
            `Les quotes-parts${group === "_" ? "" : ` (${group.toLowerCase()})`} doivent totaliser ${field.allocation.total} % — actuellement ${sum} %.`),
          itemErrors,
        };
  }
  return { error: null as string | null, itemErrors };
}

export function allocationTotals(rule: Allocation, items: RepeaterItem[]) {
  const totals: Record<string, number> = {};
  for (const item of items) {
    const g = rule.group_by ? item[rule.group_by] || "PRIMARY" : "_";
    totals[g] = Math.round(((totals[g] ?? 0) + Number(item[rule.field] || 0)) * 100) / 100;
  }
  return totals;
}

/** Typed facts for a master/repeater field (money → minor units, booleans, numbers). */
export function masterFacts(field: MasterFieldLike, values: Record<string, string>): Record<string, unknown> {
  const raw = (values[field.key] ?? "").trim();
  const out: Record<string, unknown> = {};
  if (!raw) return out;
  if (field.type === "select_master") out[field.key] = raw;
  else if (field.type === "multi_select_master") out[field.key] = parseList(raw);
  else if (field.type === "repeater")
    out[field.key] = parseItems(raw).map((item) => {
      const typed: Record<string, unknown> = {};
      for (const sub of field.itemFields ?? []) {
        const v = (item[sub.key] ?? "").trim();
        if (!v) continue;
        typed[sub.key] =
          sub.type === "number" ? Number(v) : sub.type === "money" ? Math.round(Number(v) * 100) : sub.type === "boolean" ? v === "true" : sub.type === "multi_select_master" ? parseList(v) : v;
        const otherText = (item[`${sub.key}_other`] ?? "").trim();
        if (v === OTHER && otherText) typed[`${sub.key}_other`] = otherText;
      }
      return typed;
    });
  const other = (values[`${field.key}_other`] ?? "").trim();
  if (other && (raw === OTHER || parseList(raw).includes(OTHER))) out[`${field.key}_other`] = other;
  return out;
}

export const MASTER_TYPES = ["select_master", "multi_select_master", "repeater", "file"] as const;
export const isMasterType = (type: string) => (MASTER_TYPES as readonly string[]).includes(type);

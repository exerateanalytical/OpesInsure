/** Pure helpers of the public insurer profile (app/institutions/insurer/[id].tsx).
 * Node-tested in tests/insurer-profile.test.mjs. */
import { branchOffices, type InsurerDirectory, type VerificationKey } from "./institutions.ts";

/** A key fact as i18n key + vars, translated by the page. */
export type InsurerFact = {
  label: string;
  value: { key: string; vars?: Record<string, string | number> } | { text: string } | { date: string };
  tone?: "warning";
};

type InsurerRow = {
  name: string;
  legal_name?: string | null;
  canonical_id?: string | null;
  regulator_sequence?: number | null;
  branch?: string | null;
  regulatory_status?: string | null;
  licensed?: boolean | null;
  verified_at?: string | null;
  legal_footer?: unknown;
};

const squash = (v: string) =>
  v.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/[^a-z0-9]/g, "");

/** Registered legal name when it says more than the display name (case/accents/punctuation ignored). */
export function legalNameLine(name: string, legalName: string | null | undefined): string | null {
  const l = legalName?.trim();
  return l && squash(l) !== squash(name) ? l : null;
}

/** "Non-life insurance company" / "Life insurance company" / "Insurance company". */
export function insurerKindKey(branch: string | null | undefined): "instKindInsurerIARD" | "instKindInsurerLIFE" | "instKindInsurer" {
  return branch === "LIFE" ? "instKindInsurerLIFE" : branch === "IARD" ? "instKindInsurerIARD" : "instKindInsurer";
}

/** A published date (YYYY-MM-DD or ISO) that the date formatter can show; null otherwise. */
export function validDate(value: string | null | undefined): string | null {
  const v = value?.trim();
  return v && /^\d{4}-\d{2}-\d{2}/.test(v) && !Number.isNaN(Date.parse(v)) ? v : null;
}

/** Branch-office summary: branches (head office excluded) and the distinct cities they are in. */
export function officeSummary(d: Pick<InsurerDirectory, "branches">): { count: number; cities: number } {
  const offices = branchOffices(d.branches);
  const cities = new Set(offices.map((b) => (b.city ?? "").trim().toLowerCase()).filter(Boolean)).size;
  return { count: offices.length, cities };
}

/** Letterhead legal footer lines (strings only, trimmed, empty lines dropped). */
export function legalFooterLines(raw: unknown): string[] {
  return Array.isArray(raw) ? raw.filter((l): l is string => typeof l === "string" && !!l.trim()).map((l) => l.trim()) : [];
}

/**
 * The directory lists no offices: say whether branches are still being verified (HQ-only
 * badge or partial record) so the page can explain the gap instead of hiding the section.
 */
export function officesPending(d: Pick<InsurerDirectory, "branches" | "verification">): boolean {
  if (d.branches.length) return false;
  const key: VerificationKey | undefined = d.verification?.key;
  return key === "verificationHqBranchesPending" || key === "verificationPartial";
}

/**
 * Key facts of the insurer profile, in display order; empty values are left out. The internal
 * insurer_code / ASAC code (e.g. "ACTIVA_VIE") is not shown: the public identifier is the
 * register entry (canonical_id).
 */
export function insurerFacts(row: InsurerRow, d: Pick<InsurerDirectory, "branches">): InsurerFact[] {
  const facts: InsurerFact[] = [];
  if (row.canonical_id) {
    facts.push({
      label: "instRegisterEntry",
      value: row.regulator_sequence
        ? { key: "instRegisterEntryValue", vars: { number: row.regulator_sequence, id: row.canonical_id } }
        : { text: row.canonical_id },
    });
  }
  if (row.branch === "IARD" || row.branch === "LIFE") facts.push({ label: "instLicenceBranch", value: { key: row.branch === "LIFE" ? "branchLIFE" : "branchIARD" } });
  const status = row.regulatory_status?.trim().toUpperCase();
  if (row.licensed === false || (status && status !== "AUTHORIZED")) {
    facts.push({ label: "instRegulatoryStatus", value: { key: "instLicenceUnconfirmed" }, tone: "warning" });
  }
  const offices = officeSummary(d);
  if (offices.count) {
    facts.push({
      label: "insurerStatBranches",
      value: offices.cities
        ? { key: offices.cities > 1 ? "instOfficesValue" : "instOfficesValueOne", vars: { count: offices.count, cities: offices.cities } }
        : { text: String(offices.count) },
    });
  }
  const checked = validDate(row.verified_at);
  if (checked) facts.push({ label: "instDirectoryChecked", value: { date: checked } });
  return facts;
}

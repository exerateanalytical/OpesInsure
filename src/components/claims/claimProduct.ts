/**
 * Small pure helpers shared by the claims list, FNOL wizard and claim detail:
 * product icon/tint from the policy line, insured-asset label, provider
 * name, loose extra claim fields and evidence file helpers.
 */
import { FileText, Film, Image as ImageIcon, LucideIcon, ShieldCheck } from "lucide-react-native";
import type { Claim, WalletPolicy } from "@/api/client";
import type { ClaimEvidenceItem } from "@/api/extra";
import { CATEGORIES } from "@/components/customer/categories";
import type { Tint } from "@/components/design";

const NAME_HINTS: [RegExp, (typeof CATEGORIES)[number]["id"]][] = [
  [/motor|auto|vehic|voiture|car\b|moto/i, "motor"],
  [/health|sant|medic|hospital/i, "health"],
  [/travel|voyage|trip/i, "travel"],
  [/home|habitation|house|property|maison|mrh/i, "home"],
  [/business|entreprise|sme|commercial|liabil|responsab/i, "business"],
  [/life|vie|deces|décès/i, "life"],
  [/accident/i, "accident"],
];

const TINTS: Record<string, Tint> = { motor: "blue", home: "green", health: "red", travel: "blue", business: "gold", life: "gold", accident: "gold" };

export function productCategory(name?: string | null, lineCode?: string | null) {
  const line = (lineCode ?? "").toUpperCase();
  if (line) {
    const byLine = CATEGORIES.find((c) => c.lines.some((l) => line.includes(l)));
    if (byLine && byLine.id !== "more") return byLine;
  }
  const text = name ?? "";
  for (const [re, id] of NAME_HINTS) if (re.test(text)) return CATEGORIES.find((c) => c.id === id) ?? null;
  return null;
}

/** Lucide icon for a product (car/home/heart/briefcase…), shield when unknown. */
export function productIcon(name?: string | null, lineCode?: string | null): LucideIcon {
  return productCategory(name, lineCode)?.icon ?? ShieldCheck;
}

export function productTint(name?: string | null, lineCode?: string | null): Tint {
  const cat = productCategory(name, lineCode);
  return (cat && TINTS[cat.id]) ?? "blue";
}

type LoosePolicy = Partial<WalletPolicy> & { product?: { name?: string; line_code?: string } | null; line_code?: string | null; carrier_short_name?: string | null };

export function policyTitle(p: LoosePolicy | null | undefined, fallback: string) {
  return p?.product_name ?? p?.product?.name ?? fallback;
}

export function policyLine(p: LoosePolicy | null | undefined) {
  return p?.product?.line_code ?? p?.line_code ?? null;
}

export function providerName(p: LoosePolicy | null | undefined) {
  return p?.carrier_short_name ?? p?.carrier_name ?? p?.carrier?.party?.display_name ?? null;
}

/** "Toyota Corolla 2022 · LT 123 AB" from insured_object / risk_asset. */
export function insuredLabel(p: LoosePolicy | null | undefined): string | null {
  if (!p) return null;
  if (typeof p.insured_object === "string") return p.insured_object;
  if (p.insured_object && typeof p.insured_object === "object") {
    const o = p.insured_object as Record<string, unknown>;
    const s = [o.label, o.make, o.model, o.registration_number].filter((x): x is string => typeof x === "string" && !!x);
    if (s.length) return s.join(" · ");
  }
  if (p.risk_asset) return [p.risk_asset.label, p.risk_asset.registration_number].filter(Boolean).join(" · ") || null;
  return null;
}

/** Extra string fields the API may return on a claim (incident_type, …) that the typed model does not list. */
export function claimExtra(claim: Claim | null | undefined, key: string): string | null {
  const v = (claim as unknown as Record<string, unknown> | null | undefined)?.[key];
  return typeof v === "string" && v ? v : null;
}

/** The claim's own policy payload when the API embeds it. */
export function claimPolicy(claim: Claim | null | undefined): LoosePolicy | null {
  const p = claim?.policy;
  return p && typeof p === "object" ? (p as LoosePolicy) : null;
}

export function evidenceIcon(item: Pick<ClaimEvidenceItem, "mime_type" | "evidence_type">): LucideIcon {
  const mime = (item.mime_type ?? "").toLowerCase();
  if (mime.startsWith("video/") || item.evidence_type === "VIDEO") return Film;
  if (mime.startsWith("image/") || item.evidence_type === "PHOTO") return ImageIcon;
  return FileText;
}

export function evidenceIsPdf(item: Pick<ClaimEvidenceItem, "mime_type">) {
  return (item.mime_type ?? "").toLowerCase() === "application/pdf";
}

export function formatBytes(n: number | null | undefined) {
  if (typeof n !== "number" || !Number.isFinite(n) || n <= 0) return null;
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

/** Requirement is satisfied when the server says so or an uploaded item carries its key. */
export function requirementMet(status: string, key: string, items: Pick<ClaimEvidenceItem, "evidence_type" | "status">[]) {
  if (status === "UPLOADED" || status === "VERIFIED") return true;
  return items.some((i) => i.evidence_type === key && i.status !== "REJECTED");
}

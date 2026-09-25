/**
 * KYC case-engine states (App\Application\Kyc\KycService) as the customer
 * sees them. Pure: node-tested (tests/crm-kyc.test.mjs).
 */
export const KYC_DOCUMENT_PURPOSES = ["ID_FRONT", "ID_BACK", "PASSPORT", "PROOF_OF_ADDRESS", "RCCM", "NIU"] as const;
export type KycPurpose = (typeof KYC_DOCUMENT_PURPOSES)[number];

/** Catalogue requirement code -> purposes that satisfy it (config/kyc.php purpose_aliases). */
export const REQUIREMENT_PURPOSES: Record<string, KycPurpose[]> = {
  NATIONAL_ID: ["ID_FRONT", "ID_BACK"],
  PASSPORT: ["PASSPORT"],
  PROOF_OF_ADDRESS: ["PROOF_OF_ADDRESS"],
  BUSINESS_REGISTRATION: ["RCCM"],
  TAX_ID_CERTIFICATE: ["NIU"],
};

export type KycRequirement = {
  requirement_code: string;
  applies_to?: string | null;
  mandatory: boolean;
  accepted_canonical_codes?: string[];
  satisfied: boolean;
};

export type KycPhase = "start" | "draft" | "more_info" | "in_review" | "pending_approval" | "approved" | "rejected" | "expired";
export type KycTone = "success" | "danger" | "info" | "warning";

/** Where the customer stands; `editable` = may attach documents and (re)submit. */
export function kycPhase(
  status: string | null | undefined,
  expiresAt?: string | null,
  now = Date.now(),
): { phase: KycPhase; editable: boolean; tone: KycTone } {
  const s = (status ?? "NOT_STARTED").toUpperCase();
  if (s === "APPROVED" || s === "VERIFIED") {
    if (expiresAt && Date.parse(expiresAt) <= now) return { phase: "expired", editable: true, tone: "danger" };
    return { phase: "approved", editable: false, tone: "success" };
  }
  if (s === "EXPIRED") return { phase: "expired", editable: true, tone: "danger" };
  if (s === "REJECTED") return { phase: "rejected", editable: true, tone: "danger" };
  if (s === "MORE_INFO_REQUIRED") return { phase: "more_info", editable: true, tone: "warning" };
  if (s === "PENDING_APPROVAL") return { phase: "pending_approval", editable: false, tone: "info" };
  if (s === "SUBMITTED" || s === "REVIEWING" || s === "UNDER_REVIEW") return { phase: "in_review", editable: false, tone: "info" };
  if (s === "DRAFT") return { phase: "draft", editable: true, tone: "warning" };
  return { phase: "start", editable: true, tone: "warning" };
}

/** Purposes that would satisfy the still-missing requirements. */
export function suggestedPurposes(missing: string[] | null | undefined): KycPurpose[] {
  const out: KycPurpose[] = [];
  for (const code of missing ?? []) for (const p of REQUIREMENT_PURPOSES[code] ?? []) if (!out.includes(p)) out.push(p);
  return out;
}

/** Submit allowed when editable, something attached and no mandatory requirement missing. */
export function canSubmitKyc(editable: boolean, documents: number, requirements: KycRequirement[] | null | undefined): boolean {
  if (!editable || documents === 0) return false;
  return !(requirements ?? []).some((r) => r.mandatory && !r.satisfied);
}

/** Whole days until expiry (negative = past); null when there is no expiry. */
export function daysUntil(iso: string | null | undefined, now = Date.now()): number | null {
  if (!iso) return null;
  const t = Date.parse(iso);
  return Number.isNaN(t) ? null : Math.floor((t - now) / 86_400_000);
}

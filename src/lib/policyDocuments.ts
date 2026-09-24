/**
 * Policy document engine contract (GET /api/v1/mobile/policies/{id}/documents).
 * Pure helpers only (unit-tested in tests/policy-documents.test.mjs).
 */
export type DocumentGroup = "POLICY_PACK" | "CERTIFICATES" | "SERVICING" | "CLAIMS" | "FINANCIAL";

export const DOCUMENT_GROUPS: DocumentGroup[] = ["POLICY_PACK", "CERTIFICATES", "SERVICING", "CLAIMS", "FINANCIAL"];

export type IssuedDocumentStatus =
  | "DRAFT" | "GENERATED" | "PENDING_SIGNATURE" | "ISSUED" | "VALID"
  | "SUPERSEDED" | "REPLACED" | "REVOKED" | "EXPIRED" | "CANCELLED";

export interface IssuedDocument {
  id: string;
  document_type_code: string;
  document_type_id: string | null;
  title: string;
  title_fr: string;
  group: DocumentGroup;
  stage: string | null;
  status: IssuedDocumentStatus;
  is_current: boolean;
  status_reason: string | null;
  language: "FR" | "EN" | "BILINGUAL" | null;
  issued_at: string | null;
  document_number: string | null;
  verification_code: string | null;
  verification_url: string | null;
  origin: string | null;
  issuer_type: "INSURER" | "BROKER" | "PLATFORM" | null;
  is_carrier_original: boolean;
  subject_label: string | null;
  policy_id: string;
  policy_version: number | null;
  replaced_by: string | null;
  download_url: string;
}

export interface PolicyDocumentsPayload {
  policy: { id: string; policy_number: string | null; version: number; status: string };
  contract_history: {
    manifest_id: string; policy_id: string; policy_number: string | null;
    kind: "ORIGINAL" | "ENDORSEMENT" | "RENEWAL" | "CANCELLATION" | "REINSTATEMENT" | "PAYMENT" | "CLAIM";
    label: string; sequence: number; policy_version: number; generated_at: string | null;
  }[];
  groups: { group: DocumentGroup; documents: IssuedDocument[]; history: IssuedDocument[] }[];
  packs: { manifest_id: string; pack_code: string; trigger: string; label: string; generated_at: string | null; items: { document_type_code: string; title_en: string; title_fr: string; state: string; document_id: string | null; subject_label?: string | null }[] }[];
  evidence: { id: string; category: string; origin: string; issued_by_insurer: false; uploaded_at: string | null; mime_type: string }[];
  pack_download_url: string | null;
}

export type Tone = "neutral" | "success" | "warning" | "info" | "danger";

export function statusTone(status: IssuedDocumentStatus): Tone {
  switch (status) {
    case "VALID":
    case "ISSUED":
      return "success";
    case "GENERATED":
    case "PENDING_SIGNATURE":
    case "DRAFT":
      return "info";
    case "SUPERSEDED":
    case "REPLACED":
    case "EXPIRED":
      return "warning";
    default:
      return "danger";
  }
}

/** i18n key for a group tab (keys exist in en.ts/fr.ts). */
export const groupKey = (g: DocumentGroup) =>
  ({ POLICY_PACK: "docGroupPolicyPack", CERTIFICATES: "docGroupCertificates", SERVICING: "docGroupServicing", CLAIMS: "docGroupClaims", FINANCIAL: "docGroupFinancial" } as const)[g];

/** i18n key for a document status. */
export const statusKey = (s: IssuedDocumentStatus) => `docStatus_${s}`;

/** Title in the user's language (French register names for fr). */
export const documentTitle = (d: Pick<IssuedDocument, "title" | "title_fr" | "subject_label">, language: "en" | "fr") => {
  const base = language === "fr" ? d.title_fr || d.title : d.title;
  return d.subject_label ? `${base} · ${d.subject_label}` : base;
};

/** Current documents never include replaced/revoked ones, even if the server sent them there. */
export const currentOnly = (docs: IssuedDocument[]) => docs.filter((d) => d.is_current);

/** Count of items the insurer still has to provide across packs. */
export const awaitingCount = (p: Pick<PolicyDocumentsPayload, "packs">) =>
  p.packs.reduce((n, pack) => n + pack.items.filter((i) => i.state === "AWAITING_CARRIER_DOCUMENT").length, 0);

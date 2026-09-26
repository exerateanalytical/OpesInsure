import type { CopyKey } from "@/i18n/strings";
import { normalizeClaimStatus } from "@/lib/claimStatus";

/** "Next Steps" bullets on the claim detail (design 39), per backend status. */
export function claimNextStepKeys(status: string | null | undefined): CopyKey[] {
  switch (normalizeClaimStatus(status)) {
    case "DRAFT":
      return ["claimNext_evidence", "claimNext_review"];
    case "SUBMITTED":
    case "ACKNOWLEDGED":
      return ["claimNext_review", "claimNext_contact", "claimNext_decision"];
    case "EVIDENCE_PENDING":
      return ["claimNext_evidence", "claimNext_review", "claimNext_decision"];
    case "ASSESSMENT":
      return ["claimNext_assessment", "claimNext_contact", "claimNext_decision"];
    case "CARRIER_REVIEW":
      return ["claimNext_review", "claimNext_decision"];
    case "APPROVED":
    case "PARTIALLY_APPROVED":
      return ["claimNext_settlement", "claimNext_payment"];
    case "DECLINED":
      return ["claimNext_appeal"];
    case "DISPUTED":
      return ["claimNext_review", "claimNext_decision"];
    case "PAYMENT_PENDING":
      return ["claimNext_payment"];
    case "PAID":
    case "CLOSED":
      return ["claimNext_done"];
    default:
      return ["claimNext_review", "claimNext_contact", "claimNext_decision"];
  }
}

/** i18n key of the status explanation shown in the "Current Status" card. */
export const claimStatusMessageKey = (status: string | null | undefined) =>
  `claimStatusMsg_${normalizeClaimStatus(status) ?? "UNKNOWN"}` as const;

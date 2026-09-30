/**
 * Insurer decision buttons (referrals, issuance maker-checker, bordereaux).
 * The server is the authority: when it sends the caller's allowed actions
 * only those show; the fallbacks mirror the backend's open statuses for
 * older payloads. No imports (node-tested).
 */
export type ReferralDecision = "APPROVE" | "MORE_INFORMATION" | "DECLINE";

/** Stored underwriting_cases statuses a carrier may still decide (MobileCarrierOpsController::OPEN_REFERRAL_STATUSES). */
export const OPEN_REFERRAL_STATUSES = ["QUEUED", "IN_REVIEW", "DECISION_PENDING", "AWAITING_INFORMATION"];

const ORDER: ReferralDecision[] = ["APPROVE", "MORE_INFORMATION", "DECLINE"];

export const referralDecisions = (referral: { status: string; allowed_actions?: string[] | null }): ReferralDecision[] => {
  if (Array.isArray(referral.allowed_actions)) {
    return ORDER.filter((d) => referral.allowed_actions!.includes(d));
  }
  if (!OPEN_REFERRAL_STATUSES.includes(referral.status)) return [];
  return referral.status === "AWAITING_INFORMATION" ? ["APPROVE", "DECLINE"] : ORDER;
};

/** Issuance maker-checker actions (PolicyIssuanceService::capabilities). */
export type IssuanceAction = "approve" | "reject" | "verify" | "request_correction" | "second_approve";

export const ISSUANCE_PENDING = ["REQUESTED", "CARRIER_REVIEW", "PENDING"];

export const issuanceActions = (
  item: { status: string; capabilities?: string[] | null },
  mayDecide: boolean,
): IssuanceAction[] => {
  if (Array.isArray(item.capabilities)) {
    return (["verify", "request_correction", "approve", "second_approve", "reject"] as IssuanceAction[]).filter((a) => item.capabilities!.includes(a));
  }
  // Older payloads: the route permission alone, approve/reject only.
  return mayDecide && ISSUANCE_PENDING.includes(item.status) ? ["approve", "reject"] : [];
};

/** A carrier decides a bordereau only while it is SUBMITTED (CarrierOperationsController::acknowledgeBordereau). */
export const bordereauDecidable = (status: string | null | undefined, mayDecide: boolean) => mayDecide && status === "SUBMITTED";

/** POST carrier/bordereaux/{id}/decision validation, mirrored so the button enables only when the server would accept. */
export const bordereauDecisionReady = (carrierReference: string, notes: string) =>
  carrierReference.trim().length > 0 && carrierReference.trim().length <= 160 && notes.trim().length >= 20 && notes.trim().length <= 2000;

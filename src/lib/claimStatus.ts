/**
 * Claim status vocabulary, mirroring the backend state machine
 * (app/Domain/Claims/ClaimStateMachine.php + ClaimLifecycle.php):
 *
 *   DRAFT → SUBMITTED → ACKNOWLEDGED → (EVIDENCE_PENDING) → ASSESSMENT
 *   → CARRIER_REVIEW → APPROVED | PARTIALLY_APPROVED | DECLINED
 *   → (PAYMENT_PENDING) → PAID → CLOSED, with DISPUTED after a decision.
 *
 * Pure module (no imports) so node:test can load it directly.
 */
export const CLAIM_STATUSES = [
  "DRAFT",
  "SUBMITTED",
  "ACKNOWLEDGED",
  "EVIDENCE_PENDING",
  "ASSESSMENT",
  "CARRIER_REVIEW",
  "APPROVED",
  "PARTIALLY_APPROVED",
  "DECLINED",
  "PAYMENT_PENDING",
  "PAID",
  "DISPUTED",
  "CLOSED",
] as const;
export type ClaimStatus = (typeof CLAIM_STATUSES)[number];

/** Older app builds / payloads used REJECTED and SETTLED. */
const LEGACY: Record<string, ClaimStatus> = {
  REJECTED: "DECLINED",
  SETTLED: "PAID",
  UNDER_REVIEW: "CARRIER_REVIEW",
  MORE_INFORMATION: "EVIDENCE_PENDING",
};

export function normalizeClaimStatus(value: string | null | undefined): ClaimStatus | null {
  const upper = (value ?? "").toUpperCase();
  if ((CLAIM_STATUSES as readonly string[]).includes(upper)) return upper as ClaimStatus;
  return LEGACY[upper] ?? null;
}

export type Tone = "neutral" | "success" | "warning" | "info" | "danger";
export function claimTone(value: string | null | undefined): Tone {
  switch (normalizeClaimStatus(value)) {
    case "DECLINED":
      return "danger";
    case "PAID":
    case "APPROVED":
      return "success";
    case "EVIDENCE_PENDING":
    case "PARTIALLY_APPROVED":
    case "DISPUTED":
    case "PAYMENT_PENDING":
      return "warning";
    case "CLOSED":
    case "DRAFT":
      return "neutral";
    default:
      return "info";
  }
}

/** i18n key suffix for a status label: `claimStatus_<STATUS>`. */
export const claimStatusKey = (value: string | null | undefined) =>
  `claimStatus_${normalizeClaimStatus(value) ?? "UNKNOWN"}` as const;

export const TRACKER_STEPS = [
  "submitted",
  "documents",
  "review",
  "assessment",
  "info",
  "decision",
  "settlement",
] as const;
export type TrackerStep = (typeof TRACKER_STEPS)[number];
export type StepState = "done" | "current" | "attention" | "upcoming";

/**
 * Fixed customer tracker: Submitted → Documents received → Under review →
 * Assessment → Additional info → Decision → Settlement. "Additional info" is
 * only highlighted (attention) while the insurer is waiting on evidence.
 */
export function claimTracker(value: string | null | undefined): StepState[] {
  const status = normalizeClaimStatus(value);
  let current = -1;
  let attention = -1;
  let complete = false;
  switch (status) {
    case "SUBMITTED":
      current = 0;
      break;
    case "ACKNOWLEDGED":
      current = 2;
      break;
    case "EVIDENCE_PENDING":
      current = 2;
      attention = 4;
      break;
    case "ASSESSMENT":
    case "CARRIER_REVIEW":
      current = 3;
      break;
    case "APPROVED":
    case "PARTIALLY_APPROVED":
    case "DECLINED":
    case "DISPUTED":
      current = 5;
      break;
    case "PAYMENT_PENDING":
      current = 6;
      break;
    case "PAID":
    case "CLOSED":
      complete = true;
      break;
    default:
      current = -1;
  }
  return TRACKER_STEPS.map((_, index) => {
    if (complete) return "done";
    if (index === attention) return "attention";
    if (index === current) return "current";
    // Additional info is a branch: it is not "done" merely because a later
    // step was reached, but showing it as upcoming after a decision would be
    // misleading, so it follows the same rule as the rest.
    return index < current ? "done" : "upcoming";
  });
}

export type ClaimAction =
  | "incident"
  | "evidence"
  | "checklist"
  | "inspection"
  | "repair"
  | "settlement"
  | "appeal"
  | "message";

const ACTIONS: Record<ClaimAction, ClaimStatus[]> = {
  incident: ["DRAFT", "SUBMITTED", "ACKNOWLEDGED", "EVIDENCE_PENDING"],
  evidence: ["DRAFT", "SUBMITTED", "ACKNOWLEDGED", "EVIDENCE_PENDING", "ASSESSMENT"],
  checklist: ["DRAFT", "SUBMITTED", "ACKNOWLEDGED", "EVIDENCE_PENDING", "ASSESSMENT", "CARRIER_REVIEW"],
  inspection: ["ACKNOWLEDGED", "EVIDENCE_PENDING", "ASSESSMENT", "CARRIER_REVIEW"],
  repair: ["APPROVED", "PARTIALLY_APPROVED", "PAYMENT_PENDING", "PAID"],
  settlement: ["APPROVED", "PARTIALLY_APPROVED", "PAYMENT_PENDING", "PAID"],
  appeal: ["DECLINED", "PARTIALLY_APPROVED"],
  message: CLAIM_STATUSES.filter((s) => s !== "CLOSED"),
};

export function claimActionAllowed(action: ClaimAction, value: string | null | undefined) {
  const status = normalizeClaimStatus(value);
  return !!status && ACTIONS[action].includes(status);
}

/** Open claims shown on Home: anything not paid or closed. */
export function isActiveClaim(value: string | null | undefined) {
  const status = normalizeClaimStatus(value);
  return !!status && status !== "PAID" && status !== "CLOSED";
}

// ---------------------------------------------------------------------------
// Compact rails for the redesigned claims list / detail (design 29 & 39)
// ---------------------------------------------------------------------------

/** Four-node horizontal rail on each claim card: Submitted → Assessment → Decision → Settlement. */
export const RAIL_STEPS = ["submitted", "assessment", "decision", "settlement"] as const;
export type RailStep = (typeof RAIL_STEPS)[number];
export type RailState = "done" | "current" | "rejected" | "upcoming";

/** Which of the seven tracker steps feed each rail node. */
const RAIL_SOURCES: Record<RailStep, number[]> = {
  submitted: [0],
  assessment: [1, 2, 3, 4],
  decision: [5],
  settlement: [6],
};

/**
 * Collapses claimTracker() onto the four rail nodes. A node is "current" when
 * any of its tracker steps is current or needs attention, "done" when all of
 * them are done. A declined claim shows a red "rejected" decision node; an
 * approved claim has its decision done and settlement in progress.
 */
export function claimRail(value: string | null | undefined): RailState[] {
  const status = normalizeClaimStatus(value);
  const steps = claimTracker(status);
  return RAIL_STEPS.map((node) => {
    if (node === "decision" && status === "DECLINED") return "rejected";
    if ((status === "APPROVED" || status === "PARTIALLY_APPROVED") && node === "decision") return "done";
    if ((status === "APPROVED" || status === "PARTIALLY_APPROVED") && node === "settlement") return "current";
    const own = RAIL_SOURCES[node].map((i) => steps[i] ?? "upcoming");
    if (own.some((s) => s === "current" || s === "attention")) return "current";
    if (own.every((s) => s === "done")) return "done";
    return "upcoming";
  });
}

/** Five-node detail header: Submitted → Under Review → Assessment → Decision → Settlement. */
export const DETAIL_STAGES = ["submitted", "review", "assessment", "decision", "settlement"] as const;

/**
 * Index of the active detail stage (0-4); DETAIL_STAGES.length once the claim
 * is paid or closed so every node reads as done. Unknown statuses return 0.
 */
export function claimStage(value: string | null | undefined): number {
  switch (normalizeClaimStatus(value)) {
    case "DRAFT":
    case "SUBMITTED":
      return 0;
    case "ACKNOWLEDGED":
    case "EVIDENCE_PENDING":
      return 1;
    case "ASSESSMENT":
    case "CARRIER_REVIEW":
      return 2;
    case "APPROVED":
    case "PARTIALLY_APPROVED":
    case "DECLINED":
    case "DISPUTED":
      return 3;
    case "PAYMENT_PENDING":
      return 4;
    case "PAID":
    case "CLOSED":
      return DETAIL_STAGES.length;
    default:
      return 0;
  }
}

/** List filter segments: everything, still moving, or finished (paid / closed / declined). */
export type ClaimSegment = "all" | "progress" | "completed";
export function claimSegment(value: string | null | undefined): Exclude<ClaimSegment, "all"> {
  const status = normalizeClaimStatus(value);
  return status === "PAID" || status === "CLOSED" || status === "DECLINED" ? "completed" : "progress";
}

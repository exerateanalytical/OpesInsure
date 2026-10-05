/**
 * Phase-1 fix S (2026-09-30): the staff workspace claims module (app/workspace/[role]/claims*). Pure (no imports) so it
 * is node-tested. The server decides every action (GET mobile/workspace/claims/{id} `actions`, `transitions`,
 * `expert_assignments[].available_events`); this file only maps them to screens and requests.
 */

/** Workspace modules with a dedicated screen instead of the generic read-only table. */
export const DEDICATED_WORKSPACE_MODULES: Record<string, string> = {
  claims: "/workspace/[role]/claims",
};

export const workspaceModuleRoute = (role: string, key: string) =>
  DEDICATED_WORKSPACE_MODULES[key]
    ? { pathname: DEDICATED_WORKSPACE_MODULES[key]!, params: { role } }
    : { pathname: "/workspace/[role]/module/[module]", params: { role, module: key } };

/** Status filter of the claims list (one filter sheet; server-side ?status=). */
export const WORKSPACE_CLAIM_STATUSES = [
  "SUBMITTED",
  "ACKNOWLEDGED",
  "EVIDENCE_PENDING",
  "ASSESSMENT",
  "INVESTIGATING",
  "CARRIER_REVIEW",
  "APPROVED",
  "PARTIALLY_APPROVED",
  "DECLINED",
  "DISPUTED",
  "PAYMENT_PENDING",
  "PAID",
  "CLOSED",
] as const;

export type WorkspaceClaimAction = "assign_to_me" | "transition" | "propose_decision" | "approve_decision";

export const canDo = (actions: readonly string[] | null | undefined, action: WorkspaceClaimAction) => !!actions?.includes(action);

/** Adjuster moves on an expert assignment -> POST claims/adjuster/assignments/{id}/<suffix>. */
export const ADJUSTER_EVENT_PATH: Record<string, string> = {
  accept: "accept",
  decline: "decline",
  schedule_inspection: "inspection",
  record_inspection: "inspected",
  submit_report: "report",
};

export const adjusterEventPath = (assignmentId: string, event: string): string | null =>
  ADJUSTER_EVENT_PATH[event] ? `/claims/adjuster/assignments/${encodeURIComponent(assignmentId)}/${ADJUSTER_EVENT_PATH[event]}` : null;

/** Which fields an adjuster move needs before it can be sent. */
export const adjusterEventReady = (
  event: string,
  input: { text?: string; when?: string; amountMinor?: number | null },
): boolean => {
  const text = (input.text ?? "").trim();
  switch (event) {
    case "accept":
    case "record_inspection":
      return true;
    case "decline":
      return text.length >= 5;
    case "schedule_inspection":
      return !!input.when && !Number.isNaN(Date.parse(input.when)) && Date.parse(input.when) > Date.now();
    case "submit_report":
      return text.length >= 20 && typeof input.amountMinor === "number" && input.amountMinor >= 0;
    default:
      return false;
  }
};

/** Decision form readiness (server: rationale >= 20 chars, amount required unless DECLINE). */
export const decisionReady = (decision: "APPROVE" | "PARTIAL" | "DECLINE" | null, reason: string, rationale: string, amountMinor: number | null) =>
  decision !== null && reason.trim().length > 1 && rationale.trim().length >= 20 && (decision === "DECLINE" || (amountMinor ?? 0) > 0);

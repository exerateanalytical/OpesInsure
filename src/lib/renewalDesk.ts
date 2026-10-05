/**
 * Renewal desk (app/broker/renewals/[id], app/agent/renewals/[id]): pure helpers, no imports (node-tested in
 * tests/renewal-desk.test.mjs). The server decides what the caller may do (allowed_actions); these only order it.
 */
type DeskLike = { status?: string | null; allowed_actions?: string[] | null; sale_id?: string | null; successor_policy_id?: string | null };

export type DeskActions = {
  /** The renewal quote is this seller's assisted sale: send it to the client / request the payment there. */
  openOffer: boolean;
  requote: boolean;
  decline: boolean;
};

export function deskActions(r: DeskLike | null | undefined): DeskActions {
  const allowed = r?.allowed_actions ?? [];
  const closed = !!r?.successor_policy_id || ["RENEWED", "LAPSED", "DECLINED"].includes(String(r?.status ?? ""));
  return {
    openOffer: !!r?.sale_id && !closed,
    requote: !closed && allowed.includes("REQUOTE"),
    decline: !closed && allowed.includes("DECLINE"),
  };
}

export type DeskTone = "success" | "warning" | "danger" | "info" | "neutral";

export function deskTone(status: string | null | undefined): DeskTone {
  switch (status) {
    case "RENEWED":
      return "success";
    case "QUOTED":
      return "info";
    case "DUE":
    case "CONTACTED":
    case "NOT_OPENED":
      return "warning";
    case "ISSUANCE_FAILED":
    case "LAPSED":
      return "danger";
    default:
      return "neutral";
  }
}

/** A decline needs the client's reason (the server requires 3–500 characters). */
export const declineReasonValid = (reason: string) => reason.trim().length >= 3 && reason.trim().length <= 500;

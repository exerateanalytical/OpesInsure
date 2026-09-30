import type { ClaimSettlement } from "@/api/client";

/** Settlement statuses at or after the customer's acceptance (ClaimSettlementService lifecycle). */
const ACCEPTED_OR_LATER = ["ACCEPTED", "DISCHARGE_SIGNED", "PAYMENT_PENDING", "PAID"];
const PAYMENT_STARTED = ["PENDING", "INITIATED", "PROCESSING", "SUBMITTED", "APPROVED"];
const PAYMENT_DONE = ["PAID", "SETTLED", "COMPLETED"];

/** The settlement endpoint answers {id: null, status: "PENDING"} until an offer is released: that is "no offer". */
export function releasedSettlement(x: ClaimSettlement | { id: null; status: string } | null | undefined): ClaimSettlement | null {
  return x && x.id ? (x as ClaimSettlement) : null;
}

/** Accept / Reject are offered only while the settlement is OFFERED. */
export function canDecideSettlement(x: Pick<ClaimSettlement, "status" | "can_decide"> | null | undefined): boolean {
  if (!x) return false;
  return x.status === "OFFERED" && x.can_decide !== false;
}

/** Offered → accepted → payment initiated → paid, from the settlement and payment status. */
export function settlementSteps(x: Pick<ClaimSettlement, "status" | "payment_status">) {
  const pay = (x.payment_status ?? "").toUpperCase();
  const paid = x.status === "PAID" || PAYMENT_DONE.includes(pay);
  const initiated = paid || x.status === "PAYMENT_PENDING" || PAYMENT_STARTED.includes(pay);
  const accepted = initiated || ACCEPTED_OR_LATER.includes(x.status);
  return [
    { key: "approved", label: "settleStepApproved", body: "settleStepApprovedBody", done: true },
    { key: "accepted", label: "settleStepAccepted", body: "settleStepAcceptedBody", done: accepted },
    { key: "initiated", label: "settleStepInitiated", body: "settleStepInitiatedBody", done: initiated },
    { key: "paid", label: "settleStepPaid", body: "settleStepPaidBody", done: paid },
  ] as const;
}

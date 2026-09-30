/**
 * Backend error codes (error body `code`) that deserve a specific, localized
 * message instead of the generic "request could not be completed". Values
 * are i18n keys present in en.ts and fr.ts. No imports (node-tested).
 */
export const API_ERROR_COPY = {
  STALE_RECORD: "errStaleRecord",
  DUPLICATE_SUBMISSION: "errDuplicateSubmission",
  AUTHORITY_EXCEEDED: "errAuthorityExceeded",
  PAYMENT_OK_ISSUANCE_FAILED: "errPaymentOkIssuanceFailed",
  // POST /payments guards: never charge an application twice; app payments need the accepted terms.
  PAYMENT_ALREADY_MADE: "errPaymentAlreadyMade",
  PAYMENT_IN_PROGRESS: "errPaymentInProgress",
  TERMS_NOT_ACCEPTED: "errTermsNotAccepted",
  INTEGRATION_UNAVAILABLE: "errIntegrationUnavailable",
  STEP_UP_REQUIRED: "errStepUpRequired",
  NETWORK_UNAVAILABLE: "errNetworkUnavailable",
  REQUEST_TIMEOUT: "errNetworkUnavailable",
  OFFLINE_QUEUE_FULL: "errOfflineQueueFull",
  KYC_REVIEW_IN_PROGRESS: "errKycReviewInProgress",
  // Manual quotation offer (carrier/quote-requests/{id}/offer).
  PREMIUM_INCONSISTENT: "errPremiumInconsistent",
  BREAKDOWN_INCONSISTENT: "errBreakdownInconsistent",
  VALIDITY_IN_PAST: "errValidityInPast",
} as const;

export type ApiErrorCopyKey = (typeof API_ERROR_COPY)[keyof typeof API_ERROR_COPY];

export function apiErrorCopyKey(code: string | null | undefined): ApiErrorCopyKey | null {
  if (!code) return null;
  return (API_ERROR_COPY as Record<string, ApiErrorCopyKey>)[code.toUpperCase()] ?? null;
}

/** 409: another KYC submission of this party is already under review — reload, do not resubmit. */
export const isKycReviewInProgress = (code: string | null | undefined) => (code ?? "").toUpperCase() === "KYC_REVIEW_IN_PROGRESS";

/** Codes where the user should reload the record rather than resubmit. */
export const isStaleRecord = (code: string | null | undefined) => (code ?? "").toUpperCase() === "STALE_RECORD";

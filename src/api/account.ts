import { api } from "./client";

export type Purpose = "MARKETING" | "PARTNER_SHARING" | "ANALYTICS" | "WHATSAPP_UPDATES";
export type ConsentRow = { purpose: Purpose; granted: boolean; notice_version: string | null; updated_at: string | null };
export type PrivacyRequest = { id: string; reference: string | null; type: "EXPORT" | "DELETE"; status: string; due_on: string | null; created_at: string | null };

/** Customer consent + data-subject endpoints (MobileCustomerAccountController, routes/wave16_lifecycle.php). */
export const PrivacyApi = {
  consents: () => api<ConsentRow[]>("/mobile/account/consents"),
  saveConsents: (consents: { purpose: Purpose; granted: boolean }[]) =>
    api<ConsentRow[]>("/mobile/account/consents", { method: "PUT", body: JSON.stringify({ consents }), idempotent: true }),
  requests: () => api<PrivacyRequest[]>("/mobile/account/privacy-requests"),
  createRequest: (kind: "EXPORT" | "DELETE") =>
    api<PrivacyRequest>("/mobile/account/privacy-requests", { method: "POST", body: JSON.stringify({ type: kind }), idempotent: true }),
};

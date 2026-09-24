import { api } from "./client";
import type { DocumentGroup, PolicyDocumentsPayload } from "@/lib/policyDocuments";

/** Document engine endpoints for the policyholder's own policy. */
export const PolicyDocumentsApi = {
  list: (policyId: string, filter: { group?: DocumentGroup; stage?: string } = {}) => {
    const q = new URLSearchParams(Object.entries(filter).filter(([, v]) => !!v) as [string, string][]).toString();
    return api<PolicyDocumentsPayload>(`/mobile/policies/${policyId}/documents${q ? `?${q}` : ""}`);
  },
  /** Short-lived signed ZIP link: each document kept as its own file + manifest.json. */
  packUrl: (policyId: string, manifestId?: string) =>
    api<{ url: string; format: "zip"; expires_in_minutes: number }>(
      `/mobile/policies/${policyId}/documents/pack${manifestId ? `?manifest=${encodeURIComponent(manifestId)}` : ""}`,
    ),
};

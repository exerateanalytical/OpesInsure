import { Platform } from "react-native";
import { api, type Claim, type CustomerNotification, type EvidenceRequirement } from "./client";
import { rows, type Institution } from "./extra";

/**
 * Customer-shell API calls (home, explore, profile/KYC, claims evidence,
 * support context, push, sign-out everywhere). Kept out of client.ts, which
 * the purchase flow owns. Paginated or bare-array responses are flattened.
 */
type Page<T> = T[] | { data?: T[] };

export type CustomerQuote = {
  id: string;
  status: string;
  product_name?: string;
  product_code?: string;
  vehicle_label?: string;
  offer_count?: number;
  lowest_total_minor?: number;
  can_resume?: boolean;
  created_at?: string;
  expires_at?: string | null;
};

export type KycIdentifier = {
  type: string;
  country_code: string | null;
  masked_value: string;
  verified_at: string | null;
};
export type KycSubmission = {
  id: string;
  status: string;
  notes: string | null;
  submitted_at: string | null;
  reviewed_at: string | null;
  documents: { id: string; purpose: string; category: string; scan_status: string }[];
};
/** GET /mobile/kyc/profile (App\Application\Kyc\MobileKycService::profile). */
export type KycState = {
  party_id: string | null;
  display_name: string | null;
  identifiers: KycIdentifier[];
  submission: KycSubmission | null;
};

export type SupportCaseInput = {
  category: string;
  subject: string;
  description: string;
  /** Sent for forward compatibility; the server derives priority today. */
  priority?: "NORMAL" | "HIGH";
  claim_id?: string;
  payment_id?: string;
  policy_id?: string;
  parent_case_id?: string;
};

export const CustomerApi = {
  /** POST /auth/mobile/logout-all — revokes every refresh token of the user. */
  logoutAll: () =>
    api<{ revoked?: number }>("/auth/mobile/logout-all", {
      method: "POST",
      idempotent: true,
    }),
  registerPushToken: (token: string) =>
    api<{ registered: boolean }>("/mobile/account/push-tokens", {
      method: "POST",
      body: JSON.stringify({ token, provider: "expo", platform: Platform.OS }),
      idempotent: true,
    }),
  institutions: async (type?: "insurer" | "broker") =>
    rows(
      await api<Page<Institution>>(
        `/public/institutions${type ? `?type=${type}` : ""}`,
        { anonymous: true },
      ),
    ),
  claims: async () => rows(await api<Page<Claim>>("/mobile/claims")),
  quotes: async () => rows(await api<Page<CustomerQuote>>("/mobile/quotes")),
  notifications: async () =>
    rows(await api<Page<CustomerNotification>>("/mobile/notifications")),
  evidenceRequirements: async (claimId: string) =>
    rows(
      await api<Page<EvidenceRequirement>>(
        `/mobile/claims/${claimId}/evidence-requirements`,
      ),
    ),
  createSupportCase: (payload: SupportCaseInput) =>
    api<{ id: string; reference: string; status: string; priority: string }>(
      "/mobile/support/cases",
      { method: "POST", body: JSON.stringify(payload), idempotent: true },
    ),

  // --- KYC -------------------------------------------------------------
  kyc: () => api<KycState>("/mobile/kyc/profile"),
  addIdentifier: (payload: {
    identifier_type: string;
    identifier_value: string;
    identifier_country?: string;
  }) =>
    api<KycState>("/mobile/kyc/profile", {
      method: "PATCH",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  attachKycDocument: (document_id: string, purpose: string) =>
    api<KycSubmission>("/mobile/kyc/documents", {
      method: "POST",
      body: JSON.stringify({ document_id, purpose }),
      idempotent: true,
    }),
  submitKyc: (notes?: string) =>
    api<KycSubmission>("/mobile/kyc/submission", {
      method: "POST",
      body: JSON.stringify(notes ? { notes } : {}),
      idempotent: true,
    }),

  // --- Files -----------------------------------------------------------
  /** POST /mobile/documents — JPEG, PNG or PDF as base64. */
  uploadDocument: (payload: {
    category: string;
    mime_type: "application/pdf" | "image/jpeg" | "image/png";
    file_base64: string;
  }) =>
    api<{ id: string }>("/mobile/documents", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
      timeoutMs: 60000,
    }),
  /** Resumable upload (used for video evidence, video/mp4 only). */
  startUpload: (payload: {
    resource_type: string;
    mime_type: string;
    total_chunks: number;
    total_size_bytes: number;
  }) =>
    api<{ id: string; status: string; total_chunks: number }>("/mobile/uploads", {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  putChunk: (id: string, index: number, data: string) =>
    api<unknown>(`/mobile/uploads/${id}/chunks/${index}`, {
      method: "PUT",
      body: JSON.stringify({ data }),
      timeoutMs: 60000,
    }),
  finalizeUpload: (id: string) =>
    api<{ id: string; status: string }>(`/mobile/uploads/${id}/finalize`, {
      method: "POST",
      timeoutMs: 60000,
    }),
  attachClaimEvidence: (
    claimId: string,
    payload: {
      document_id?: string;
      upload_session_id?: string;
      evidence_type: string;
      purpose: string;
    },
  ) =>
    api<unknown>(`/mobile/claims/${claimId}/evidence`, {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
};

/** Reads a local file URI as base64 (no data: prefix). */
export async function readAsBase64(uri: string): Promise<{ base64: string; size: number }> {
  const blob = await (await fetch(uri)).blob();
  const dataUrl = await new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error("FILE_READ_FAILED"));
    reader.onload = () => resolve(String(reader.result));
    reader.readAsDataURL(blob);
  });
  const comma = dataUrl.indexOf(",");
  return { base64: comma >= 0 ? dataUrl.slice(comma + 1) : dataUrl, size: blob.size };
}

/** Base64 chunk length: a multiple of 4 so each chunk decodes on its own
 * (2 000 000 chars ≈ 1.5 MB, well under the server's 10 MB chunk cap). */
const CHUNK_CHARS = 2_000_000;

/** Uploads a file as claim evidence: documents API for images/PDF,
 * resumable upload for video. */
export async function uploadClaimEvidence(
  claimId: string,
  asset: { uri: string; mimeType?: string | null },
  evidenceType: string,
  onProgress?: (fraction: number) => void,
) {
  const mime = (asset.mimeType ?? "").toLowerCase();
  const { base64, size } = await readAsBase64(asset.uri);
  if (mime.startsWith("video/")) {
    const total = Math.max(1, Math.ceil(base64.length / CHUNK_CHARS));
    const session = await CustomerApi.startUpload({
      resource_type: "CLAIM_EVIDENCE",
      mime_type: "video/mp4",
      total_chunks: total,
      total_size_bytes: size,
    });
    for (let i = 0; i < total; i++) {
      await CustomerApi.putChunk(session.id, i, base64.slice(i * CHUNK_CHARS, (i + 1) * CHUNK_CHARS));
      onProgress?.((i + 1) / (total + 1));
    }
    await CustomerApi.finalizeUpload(session.id);
    await CustomerApi.attachClaimEvidence(claimId, {
      upload_session_id: session.id,
      evidence_type: evidenceType,
      purpose: "CLAIM_EVIDENCE",
    });
  } else {
    const mime_type =
      mime === "application/pdf" ? "application/pdf" : mime === "image/png" ? "image/png" : "image/jpeg";
    const document = await CustomerApi.uploadDocument({
      category: "CLAIM_EVIDENCE",
      mime_type,
      file_base64: base64,
    });
    onProgress?.(0.8);
    await CustomerApi.attachClaimEvidence(claimId, {
      document_id: document.id,
      evidence_type: evidenceType,
      purpose: "CLAIM_EVIDENCE",
    });
  }
  onProgress?.(1);
}

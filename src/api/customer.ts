import { Platform } from "react-native";
import * as FileSystem from "expo-file-system/legacy";
import { api, apiPage, type Claim, type CustomerNotification, type EvidenceRequirement, type Payment, type Policy } from "./client";
import { chunkRanges, isVideo, videoMime, type DraftEvidence } from "@/lib/evidenceUpload";
import { rows, type Institution } from "./extra";
import { loadDirectory } from "./directory";
import { storeDocument } from "./documentUpload";
import type { KycRequirement } from "@/lib/kyc";

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
  /** KYC case engine (Batch 4): level, requirements and expiry. */
  kyc_level?: string | null;
  approved_at?: string | null;
  expires_at?: string | null;
  expired_at?: string | null;
  remediation_reason?: string | null;
  requirements?: KycRequirement[];
  missing_requirements?: string[];
  documents: { id: string; purpose: string; category: string; scan_status: string; verification_status?: string | null }[];
};
/** GET /mobile/kyc/profile (App\Application\Kyc\MobileKycService::profile). */
export type KycState = {
  party_id: string | null;
  display_name: string | null;
  identifiers: KycIdentifier[];
  submission: KycSubmission | null;
};

/** Partial FNOL kept by the server (claim_drafts) — never a claim until submitted. */
export type ClaimDraftPayload = {
  incident_at?: string | null;
  incident_location?: string | null;
  description?: string | null;
  incident_type?: string | null;
  injuries_reported?: boolean;
  police_report_filed?: boolean;
  police_reference?: string | null;
  estimated_loss_minor?: number | null;
  latitude?: number | null;
  longitude?: number | null;
  /** Wizard answers as entered (form values), so a resumed draft reopens the form as it was left. */
  client_state?: Record<string, unknown> | null;
  evidence?: DraftEvidence[] | null;
};
export type ClaimDraftInput = ClaimDraftPayload & { policy_id?: string | null };
export type ClaimDraft = {
  id: string;
  policy_id: string | null;
  payload: ClaimDraftPayload | null;
  policy?: Policy | null;
  claim_id?: string | null;
  created_at?: string;
  updated_at?: string;
};
export type ClaimDraftEvidenceReport = {
  attached: number;
  pending: number;
  failed: { index: number; name: string | null; evidence_type: string }[];
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
      // SIGN_OUT_EVERYWHERE grant when the user just stepped up (mobile audit B5).
      stepUpIfGranted: "SIGN_OUT_EVERYWHERE",
      idempotent: true,
    }),
  registerPushToken: (token: string) =>
    api<{ registered: boolean }>("/mobile/account/push-tokens", {
      method: "POST",
      body: JSON.stringify({ token, provider: "expo", platform: Platform.OS }),
      idempotent: true,
    }),
  /** Thin wrapper over the shared, cached directory store (src/api/directory.ts). */
  institutions: (type?: "insurer" | "broker"): Promise<Institution[]> => loadDirectory(type ?? "all"),
  claims: async () => rows(await api<Page<Claim>>("/mobile/claims")),
  /** One page of GET /mobile/payments for one policy (server filter policy_id). */
  policyPayments: (policyId: string, page = 1) => apiPage<Payment>(`/mobile/payments?policy_id=${encodeURIComponent(policyId)}`, page),
  /** One page of GET /mobile/claims (newest first); policyId narrows it server-side to that policy. */
  claimsPage: (page = 1, policyId?: string) =>
    apiPage<Claim>(policyId ? `/mobile/claims?policy_id=${encodeURIComponent(policyId)}` : "/mobile/claims", page),

  // --- Claim drafts (new-claim wizard: nothing is filed until drafts/{id}/submit) ------
  claimDrafts: async () => rows(await api<Page<ClaimDraft>>("/mobile/claims/drafts")),
  claimDraft: (id: string) => api<ClaimDraft>(`/mobile/claims/drafts/${id}`),
  createClaimDraft: (payload: ClaimDraftInput) =>
    api<ClaimDraft>("/mobile/claims/drafts", { method: "POST", body: JSON.stringify(payload), idempotent: true }),
  updateClaimDraft: (id: string, payload: ClaimDraftInput) =>
    api<ClaimDraft>(`/mobile/claims/drafts/${id}`, { method: "PATCH", body: JSON.stringify(payload), idempotent: true }),
  deleteClaimDraft: (id: string) => api<null>(`/mobile/claims/drafts/${id}`, { method: "DELETE", idempotent: true }),
  /** Files the draft as a claim (FNOL) with the declaration; evidence saved on the draft is attached by the server. */
  submitClaimDraft: async (id: string) => {
    const body = await api<{ data: Claim; evidence?: ClaimDraftEvidenceReport }>(`/mobile/claims/drafts/${id}/submit`, {
      method: "POST",
      body: JSON.stringify({ declaration_confirmed: true }),
      idempotent: true,
      envelope: true,
      timeoutMs: 60000,
    });
    return { claim: body.data, evidence: body.evidence ?? { attached: 0, pending: 0, failed: [] } };
  },

  // --- Account: phone verification + password -------------------------
  /** POST /me/phone/verification: sends a code to the account's own phone (demo OTP rules apply server-side). */
  requestPhoneVerification: (channel?: "whatsapp" | "sms") =>
    api<{ challenge_id?: string; delivery_status?: string; expires_in?: number; sent?: boolean; reason?: string }>("/me/phone/verification", {
      method: "POST",
      body: JSON.stringify(channel ? { channel } : {}),
      idempotent: true,
    }),
  confirmPhoneVerification: (challenge_id: string, code: string) =>
    api<{ user?: unknown }>("/me/phone/verification/confirm", {
      method: "POST",
      body: JSON.stringify({ challenge_id, code }),
      idempotent: true,
    }),
  /** PUT /me/password: ends every session on success (the app then signs in again). */
  changePassword: (current_password: string, password: string, password_confirmation: string) =>
    api<{ password_changed: boolean; sessions_revoked?: boolean }>("/me/password", {
      method: "PUT",
      body: JSON.stringify({ current_password, password, password_confirmation }),
      idempotent: true,
    }),
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
  /** Body from form kyc_identifier (identifier_type may be OTHER + identifier_type_other). */
  addIdentifier: (payload: {
    identifier_type: string;
    identifier_value: string;
    identifier_country?: string;
    [key: string]: unknown;
  }) =>
    api<KycState>("/mobile/kyc/profile", {
      method: "PATCH",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
  /** extra: the rest of form kyc_document (purpose_other when purpose is OTHER). */
  attachKycDocument: (document_id: string, purpose: string, extra: Record<string, unknown> = {}) =>
    api<KycSubmission>("/mobile/kyc/documents", {
      method: "POST",
      body: JSON.stringify({ ...extra, document_id, purpose }),
      idempotent: true,
    }),
  submitKyc: (notes?: string) =>
    api<KycSubmission>("/mobile/kyc/submission", {
      method: "POST",
      body: JSON.stringify(notes ? { notes } : {}),
      idempotent: true,
    }),

  // --- Files -----------------------------------------------------------
  // POST /mobile/documents: storeDocument() in ./documentUpload (reuses an identical stored file).
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
    // 202 + security_check_pending: the file is in its malware check and is attached automatically afterwards.
    api<{ id?: string; status?: string; security_check_pending?: boolean } | null>(`/mobile/claims/${claimId}/evidence`, {
      method: "POST",
      body: JSON.stringify(payload),
      idempotent: true,
    }),
};

const dataUrlBase64 = (blob: Blob) =>
  new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error("FILE_READ_FAILED"));
    reader.onload = () => {
      const dataUrl = String(reader.result);
      const comma = dataUrl.indexOf(",");
      resolve(comma >= 0 ? dataUrl.slice(comma + 1) : dataUrl);
    };
    reader.readAsDataURL(blob);
  });

/** Reads a local file URI as base64 (no data: prefix). Images and PDFs only: videos are streamed in chunks. */
export async function readAsBase64(uri: string): Promise<{ base64: string; size: number }> {
  const blob = await (await fetch(uri)).blob();
  return { base64: await dataUrlBase64(blob), size: blob.size };
}

/**
 * A local file read one byte range at a time as base64, so a video never sits in memory whole:
 * on device through expo-file-system (position/length reads), on web by slicing the file blob.
 */
async function openChunkedFile(uri: string, knownSize?: number | null): Promise<{ size: number; read: (start: number, end: number) => Promise<string> }> {
  if (Platform.OS !== "web") {
    const info = await FileSystem.getInfoAsync(uri);
    const size = info.exists && typeof info.size === "number" ? info.size : (knownSize ?? 0);
    if (size > 0)
      return {
        size,
        read: (start, end) => FileSystem.readAsStringAsync(uri, { encoding: FileSystem.EncodingType.Base64, position: start, length: end - start }),
      };
  }
  const blob = await (await fetch(uri)).blob();
  return { size: blob.size, read: (start, end) => dataUrlBase64(blob.slice(start, end)) };
}

/** Where an uploaded evidence file lives before it is attached to a claim. */
export type EvidenceFileRef = { document_id: string } | { upload_session_id: string };

/**
 * Uploads one evidence file without attaching it: documents API for images/PDF, a resumable upload for
 * video (read and sent chunk by chunk with its real type: .mov → video/quicktime). Used directly by the
 * new-claim wizard (the refs are kept on the claim draft and attached by the server on submit).
 */
export async function uploadEvidenceFile(
  asset: { uri: string; mimeType?: string | null; name?: string | null; size?: number | null },
  onProgress?: (fraction: number) => void,
): Promise<EvidenceFileRef & { mime_type: string; size_bytes: number }> {
  const mime = (asset.mimeType ?? "").toLowerCase();
  if (isVideo(mime)) {
    const mime_type = videoMime(mime, asset.name, asset.uri);
    const file = await openChunkedFile(asset.uri, asset.size);
    const ranges = chunkRanges(file.size);
    const session = await CustomerApi.startUpload({
      resource_type: "CLAIM_EVIDENCE",
      mime_type,
      total_chunks: ranges.length,
      total_size_bytes: file.size,
    });
    for (let i = 0; i < ranges.length; i++) {
      const [start, end] = ranges[i]!;
      await CustomerApi.putChunk(session.id, i, await file.read(start, end));
      onProgress?.((i + 1) / (ranges.length + 1));
    }
    await CustomerApi.finalizeUpload(session.id);
    return { upload_session_id: session.id, mime_type, size_bytes: file.size };
  }
  const mime_type = mime === "application/pdf" ? "application/pdf" : mime === "image/png" ? "image/png" : "image/jpeg";
  const { base64, size } = await readAsBase64(asset.uri);
  const documentId = await storeDocument("CLAIM_EVIDENCE", { mime: mime_type, base64 });
  onProgress?.(0.8);
  return { document_id: documentId, mime_type, size_bytes: size };
}

/** Uploads a file and attaches it to an existing claim as evidence. */
export async function uploadClaimEvidence(
  claimId: string,
  asset: { uri: string; mimeType?: string | null; name?: string | null; size?: number | null },
  evidenceType: string,
  onProgress?: (fraction: number) => void,
): Promise<{ securityCheck: boolean }> {
  const file = await uploadEvidenceFile(asset, onProgress);
  const ref = "document_id" in file ? { document_id: file.document_id } : { upload_session_id: file.upload_session_id };
  const attached = await CustomerApi.attachClaimEvidence(claimId, { ...ref, evidence_type: evidenceType, purpose: "CLAIM_EVIDENCE" });
  onProgress?.(1);
  return { securityCheck: attached?.security_check_pending === true };
}

/**
 * Application (proposal) document helpers. Pure: node-tested (tests/proposal-documents.test.mjs).
 *  - the file types POST /mobile/documents accepts, from whatever a picker reports,
 *  - base64 -> bytes (for the duplicate check),
 *  - which verified KYC documents already evidence an application requirement.
 */
import { kycPhase, REQUIREMENT_PURPOSES, type KycRequirement } from "./kyc.ts";

export type UploadMime = "image/jpeg" | "image/png" | "application/pdf";

/** The server accepts JPEG, PNG and PDF only; null means "not supported" (e.g. HEIC, WebP, Word). */
export function normalizeUploadMime(mime: string | null | undefined, name?: string | null): UploadMime | null {
  const m = String(mime ?? "").toLowerCase().trim();
  const ext = String(name ?? "").toLowerCase().split(".").pop() ?? "";
  if (m === "image/jpeg" || m === "image/jpg" || m === "image/pjpeg") return "image/jpeg";
  if (m === "image/png") return "image/png";
  if (m === "application/pdf") return "application/pdf";
  if (!m || m === "application/octet-stream") {
    if (ext === "jpg" || ext === "jpeg") return "image/jpeg";
    if (ext === "png") return "image/png";
    if (ext === "pdf") return "application/pdf";
  }
  return null;
}

const B64 = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

/** Decodes standard base64 (padding optional, whitespace ignored). */
export function base64ToBytes(b64: string): Uint8Array<ArrayBuffer> {
  const clean = b64.replace(/[^A-Za-z0-9+/]/g, "");
  const out = new Uint8Array(new ArrayBuffer(Math.floor((clean.length * 3) / 4)));
  let buffer = 0;
  let bits = 0;
  let n = 0;
  for (const ch of clean) {
    buffer = (buffer << 6) | B64.indexOf(ch);
    bits += 6;
    if (bits >= 8) {
      bits -= 8;
      out[n++] = (buffer >> bits) & 0xff;
    }
  }
  return out.slice(0, n);
}

export const bytesToHex = (bytes: ArrayBuffer | Uint8Array) =>
  Array.from(bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes), (b) => b.toString(16).padStart(2, "0")).join("");

type ProposalRequirement = { code: string; status?: string | null; satisfied_by?: string | null };
type KycLike =
  | {
      submission?: {
        status?: string | null;
        expires_at?: string | null;
        requirements?: KycRequirement[] | null;
        documents?: { id: string; purpose: string; verification_status?: string | null }[] | null;
      } | null;
    }
  | null
  | undefined;

/** Extra pivot purposes that also evidence a KYC requirement (config/kyc.php purpose_aliases). */
const PURPOSE_ALIASES: Record<string, string[]> = { NATIONAL_ID: ["NATIONAL_ID", "ID_CARD", "CNI"] };

/**
 * Links to make so an application reuses an APPROVED (unexpired) identity verification: every still-missing
 * upload requirement whose catalogue code a satisfied KYC requirement accepts, paired with the KYC documents
 * that evidence it (both sides of an ID card). Nothing when verification is not approved.
 */
export function kycAutoAttachments(requirements: ProposalRequirement[], kyc: KycLike, now = Date.now()): { requirement_code: string; document_id: string }[] {
  const sub = kyc?.submission;
  if (!sub || kycPhase(sub.status, sub.expires_at, now).phase !== "approved") return [];
  const out: { requirement_code: string; document_id: string }[] = [];
  for (const req of requirements) {
    const status = String(req.status ?? "MISSING").toUpperCase();
    if (req.satisfied_by === "PROPOSAL_FORM" || !["MISSING", "REQUIRED", ""].includes(status)) continue;
    const code = req.code.toUpperCase();
    for (const k of sub.requirements ?? []) {
      if (!k.satisfied || !(k.accepted_canonical_codes ?? []).some((c) => c.toUpperCase() === code)) continue;
      const purposes = new Set<string>([...(REQUIREMENT_PURPOSES[k.requirement_code] ?? []), ...(PURPOSE_ALIASES[k.requirement_code] ?? [])]);
      for (const d of sub.documents ?? []) {
        if (!purposes.has(d.purpose) || String(d.verification_status ?? "").toUpperCase() === "REJECTED") continue;
        if (!out.some((o) => o.requirement_code === req.code && o.document_id === d.id)) out.push({ requirement_code: req.code, document_id: d.id });
      }
    }
  }
  return out;
}

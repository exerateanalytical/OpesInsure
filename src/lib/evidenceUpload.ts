/**
 * Pure helpers for claim evidence uploads (node-tested: tests/customer-account-gaps.test.mjs).
 *  - videoMime: the real container type of a picked video (the server checks the file signature against it);
 *  - chunkRanges: byte ranges of a resumable upload, so a video is read and sent one chunk at a time
 *    instead of being held in memory whole as base64.
 */

/** Raw bytes per resumable chunk (≈2.1 MB of base64 JSON; the server cap is 10 MiB per chunk). */
export const VIDEO_CHUNK_BYTES = 1_572_864;

const extensionOf = (name: string | null | undefined) => {
  const clean = String(name ?? "").split(/[?#]/)[0] ?? "";
  const dot = clean.lastIndexOf(".");
  return dot >= 0 ? clean.slice(dot + 1).toLowerCase() : "";
};

/** Upload MIME type the server accepts for a picked video: video/quicktime (.mov) or video/mp4. */
export function videoMime(mimeType: string | null | undefined, ...names: (string | null | undefined)[]): "video/mp4" | "video/quicktime" {
  const mime = String(mimeType ?? "").toLowerCase();
  if (mime === "video/quicktime") return "video/quicktime";
  if (mime === "video/mp4" || mime === "video/m4v" || mime === "video/x-m4v") return "video/mp4";
  for (const name of names) {
    const ext = extensionOf(name);
    if (ext === "mov" || ext === "qt") return "video/quicktime";
    if (ext === "mp4" || ext === "m4v") return "video/mp4";
  }
  return "video/mp4";
}

export const isVideo = (mimeType: string | null | undefined) => String(mimeType ?? "").toLowerCase().startsWith("video/");

/** [start, end) byte ranges covering `size` bytes; at least one range so an empty file still gets a session. */
export function chunkRanges(size: number, chunkBytes = VIDEO_CHUNK_BYTES): [number, number][] {
  const total = Math.max(1, Math.ceil(Math.max(0, size) / chunkBytes));
  return Array.from({ length: total }, (_, i) => [i * chunkBytes, Math.min(size, (i + 1) * chunkBytes)] as [number, number]);
}

/** A file saved on a claim draft before the claim exists; attached by the server on submit. */
export type DraftEvidence = {
  document_id?: string;
  upload_session_id?: string;
  evidence_type: string;
  name?: string | null;
  mime_type?: string | null;
  size_bytes?: number | null;
};

/** Server rules: at most 20 files on a draft, each with a file reference. */
export const MAX_DRAFT_EVIDENCE = 20;
export function withDraftEvidence(list: DraftEvidence[] | null | undefined, item: DraftEvidence): DraftEvidence[] {
  const current = (list ?? []).filter((x) => x.document_id || x.upload_session_id);
  const key = item.document_id ?? item.upload_session_id;
  const next = current.filter((x) => (x.document_id ?? x.upload_session_id) !== key);
  return [...next, item].slice(-MAX_DRAFT_EVIDENCE);
}
export function withoutDraftEvidence(list: DraftEvidence[] | null | undefined, key: string): DraftEvidence[] {
  return (list ?? []).filter((x) => (x.document_id ?? x.upload_session_id) !== key);
}

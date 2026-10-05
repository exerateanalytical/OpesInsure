/**
 * Sync centre copy for queued offline operations. Queue rows keep the text they were saved with
 * (`resource` was an English label, or already translated at enqueue time), a kind and an error code;
 * these map them to catalogue keys so the screen reads in the app language. Pure (node-tested).
 */

/** Stable catalogue key for a queued resource label (e.g. "Claim incident draft" → syncResource_claim_incident_draft). */
export function syncResourceKey(resource: string | null | undefined): string {
  const slug = String(resource ?? "")
    .trim()
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "");
  return `syncResource_${slug || "unknown"}`;
}

export const syncKindKey = (kind: string | null | undefined) => `syncKind_${String(kind ?? "MUTATION").toUpperCase()}`;

/** "1 change waiting" / "3 changes waiting". */
export const syncPendingKey = (count: number): "syncPendingOne" | "syncPendingMany" => (count === 1 ? "syncPendingOne" : "syncPendingMany");

import { usePathname } from "expo-router";
import { partnerLookFor, type PartnerLook } from "@/lib/partnerLook";

export type { PartnerLook };

/**
 * The partner portal the current screen belongs to, or null (customer app).
 * Shared building blocks (Screen, AppHeader, Card, FlowRow, FilteredList) render the
 * Commercial Agent kit when this is set, so every broker and insurer screen matches the
 * agent portal without per-screen rewrites.
 */
export function usePartnerLook(): PartnerLook | null {
  return partnerLookFor(usePathname());
}

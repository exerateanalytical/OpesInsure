import { useLoad } from "@/hooks/useLoad";

/**
 * Detail data for a record whose API exposes only a (server-scoped) list so
 * far: loads the list and picks the row. `null` = not in the caller's scope
 * (rendered as the 404 state by DetailScreen `isMissing`). Swap for the
 * dedicated GET endpoint once the backend ships it.
 */
export function useListRecord<T extends { id: string }>(load: () => Promise<T[]>, id: string | undefined) {
  const q = useLoad(async () => (await load()).find((r) => r.id === id) ?? null, [id]);
  return q;
}

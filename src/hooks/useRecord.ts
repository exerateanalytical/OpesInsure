import { useLoad } from "@/hooks/useLoad";
import { ApiError } from "@/api/client";

/**
 * Phase-1 fix S (2026-09-30): detail data from a per-id endpoint. `null` = not found / not in the caller's scope
 * (the server answers 404 either way), rendered as the 404 state by DetailScreen `isMissing` — the drop-in
 * replacement for useListRecord, which only found records on the first page of a list.
 */
export function useRecord<T>(load: (id: string) => Promise<T>, id: string | undefined) {
  return useLoad(async () => {
    if (!id) return null;
    try {
      return await load(id);
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) return null;
      throw e;
    }
  }, [id]);
}

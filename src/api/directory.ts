import { api } from "@/api/client";
import type { Institution } from "@/api/extra";
import { DIRECTORY_TTL_MS, createTtlCache, directoryOfType, isDemoInstitution } from "@/lib/institutions";

/**
 * The one call path to the public institution directory (GET /public/institutions).
 * Explore, Search, the insurer/broker lists, product detail, profiles and every
 * logo lookup share a single in-memory copy, refetched after DIRECTORY_TTL_MS:
 * the whole directory (insurers then brokers, register order) is fetched once and
 * split by type locally, so a session costs one request instead of one per screen.
 */
export type DirectoryType = "insurer" | "broker" | "all";

const listCache = createTtlCache<Institution[]>(DIRECTORY_TTL_MS);
const detailCache = createTtlCache<Institution>(DIRECTORY_TTL_MS);

const flatten = (page: Institution[] | { data?: Institution[] } | null | undefined): Institution[] =>
  Array.isArray(page) ? page : (page?.data ?? []);

function allInstitutions(): Promise<Institution[]> {
  return listCache.get("all", async () =>
    flatten(await api<Institution[] | { data?: Institution[] }>("/public/institutions", { anonymous: true })),
  );
}

/** Directory rows of a type (default all), demo rows removed. */
export async function loadDirectory(type: DirectoryType = "all"): Promise<Institution[]> {
  return directoryOfType(await allInstitutions(), type);
}

/** One institution (GET /public/institutions/{id}); a demo row reads as not found. */
export function loadInstitution(id: string): Promise<Institution> {
  return detailCache.get(id, async () => {
    const row = await api<Institution>(`/public/institutions/${encodeURIComponent(id)}`, { anonymous: true });
    if (isDemoInstitution(row)) throw Object.assign(new Error("NOT_FOUND"), { status: 404 });
    return row;
  });
}

/** Drop the cached directory (e.g. pull-to-refresh) so the next load refetches. */
export function invalidateDirectory(): void {
  listCache.clear();
  detailCache.clear();
}

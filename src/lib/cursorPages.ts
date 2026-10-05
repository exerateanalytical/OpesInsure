/**
 * Phase-1 fix S (2026-09-30): cursor-paged staff lists (?cursor= / meta.next_cursor) on top of the app's page-number
 * list hook (usePagedList). Pure (no imports) so it is node-tested.
 *
 * The server answers { data, meta: { next_cursor } }; next_cursor is null on the last page. The adapter remembers the
 * cursor that opens each page, so usePagedList can keep asking for "page n + 1".
 */
export type CursorPage<T> = { items: T[]; next: string | null };

type PageResultLike<T> = { items: T[]; info: { page: number; lastPage: number; total: number | null; hasMore: boolean } };

/** Appends ?cursor= / ?limit= to a path that may already carry a query string. */
export const withCursor = (path: string, cursor: string | null, limit?: number): string => {
  const params: string[] = [];
  if (cursor) params.push(`cursor=${encodeURIComponent(cursor)}`);
  if (limit) params.push(`limit=${limit}`);
  if (!params.length) return path;
  return `${path}${path.includes("?") ? "&" : "?"}${params.join("&")}`;
};

/** next_cursor from any envelope shape the staff endpoints use (meta.next_cursor, or data.next_cursor on modules). */
export const nextCursorOf = (payload: unknown): string | null => {
  if (!payload || typeof payload !== "object") return null;
  const p = payload as { meta?: { next_cursor?: unknown }; data?: { next_cursor?: unknown }; next_cursor?: unknown };
  const c = p.meta?.next_cursor ?? p.next_cursor ?? (p.data && !Array.isArray(p.data) ? p.data.next_cursor : undefined);
  return typeof c === "string" && c !== "" ? c : null;
};

/**
 * Wraps a cursor fetcher as a page-number fetcher. Asking for page 1 always restarts from the first page (a refresh);
 * asking for a page whose cursor is unknown (never reached) returns an empty last page instead of refetching page 1.
 */
export function cursorPager<T>(fetchCursor: (cursor: string | null) => Promise<CursorPage<T>>) {
  const cursors = new Map<number, string | null>([[1, null]]);
  return async (page: number): Promise<PageResultLike<T>> => {
    if (page <= 1) {
      cursors.clear();
      cursors.set(1, null);
    }
    if (!cursors.has(page)) return { items: [], info: { page, lastPage: page, total: null, hasMore: false } };
    const result = await fetchCursor(cursors.get(page) ?? null);
    if (result.next) cursors.set(page + 1, result.next);
    return { items: result.items, info: { page, lastPage: result.next ? page + 1 : page, total: null, hasMore: !!result.next } };
  };
}

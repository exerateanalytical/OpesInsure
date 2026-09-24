import { useCallback, useEffect, useRef, useState } from "react";
import { mergePages, PageResult } from "@/lib/purchase";

/**
 * Paginated list state: first-page loading/error, "Load more", and a
 * refresh that starts again from page 1. Works with every list shape
 * because fetchers go through apiPage()/unwrapPage().
 */
export function usePagedList<T extends { id: string }>(
  fetchPage: (page: number) => Promise<PageResult<T>>,
) {
  const [items, setItems] = useState<T[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [moreError, setMoreError] = useState<unknown>(null);
  const alive = useRef(true);
  const fetcher = useRef(fetchPage);
  fetcher.current = fetchPage;

  const reload = useCallback(async () => {
    setLoading(true);
    setError(null);
    setMoreError(null);
    try {
      const result = await fetcher.current(1);
      if (!alive.current) return;
      setItems(result.items);
      setPage(1);
      setHasMore(result.info.hasMore);
    } catch (e) {
      if (alive.current) setError(e);
    } finally {
      if (alive.current) setLoading(false);
    }
  }, []);

  const loadMore = useCallback(async () => {
    if (loadingMore || !hasMore) return;
    setLoadingMore(true);
    setMoreError(null);
    try {
      const result = await fetcher.current(page + 1);
      if (!alive.current) return;
      setItems((current) => mergePages(current, result.items));
      setPage(page + 1);
      setHasMore(result.info.hasMore && result.items.length > 0);
    } catch (e) {
      if (alive.current) setMoreError(e);
    } finally {
      if (alive.current) setLoadingMore(false);
    }
  }, [hasMore, loadingMore, page]);

  useEffect(() => {
    alive.current = true;
    void reload();
    return () => {
      alive.current = false;
    };
  }, [reload]);

  return { items, loading, loadingMore, error, moreError, hasMore, reload, loadMore };
}

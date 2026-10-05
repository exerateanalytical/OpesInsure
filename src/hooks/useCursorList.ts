import { useMemo } from "react";
import { usePagedList } from "@/hooks/usePagedList";
import type { PageResult } from "@/lib/purchase";

/**
 * Phase-1 fix S (2026-09-30): a cursor-paged staff list (CarrierPagedApi / BrokerPagedApi pagers) in the shape
 * StatePanel reads ({ loading, error, data, reload }) plus the paging state for <LoadMore />.
 */
export function useCursorList<T extends { id: string }>(makePager: () => (page: number) => Promise<PageResult<T>>) {
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const pager = useMemo(makePager, []);
  const list = usePagedList<T>(pager);
  return {
    ...list,
    data: list.loading && !list.items.length ? undefined : list.items,
    reload: () => void list.reload(),
    more: { hasMore: list.hasMore, loading: list.loadingMore, error: list.moreError, onPress: () => void list.loadMore() },
  };
}

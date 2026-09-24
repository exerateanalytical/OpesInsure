import { DependencyList, useCallback, useEffect, useRef, useState } from "react";

/**
 * Loads async data with explicit loading / error states and a retry.
 * Replaces the bare `Api.x().then(setX)` pattern, which left screens blank
 * forever on a network failure.
 */
export function useLoad<T>(fn: () => Promise<T>, deps: DependencyList = []) {
  const [data, setData] = useState<T | undefined>(undefined);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<unknown>(null);
  const alive = useRef(true);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const load = useCallback(fn, deps);
  const reload = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const value = await load();
      if (alive.current) setData(value);
    } catch (e) {
      if (alive.current) setError(e);
    } finally {
      if (alive.current) setLoading(false);
    }
  }, [load]);
  useEffect(() => {
    alive.current = true;
    void reload();
    return () => {
      alive.current = false;
    };
  }, [reload]);
  return { data, setData, loading, error, reload };
}

import { DependencyList, useCallback, useEffect, useRef, useState } from "react";
import { AppState } from "react-native";
import { useFocusEffect } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { FRESHNESS, FreshnessDomain, isStale } from "@/lib/freshness";

/**
 * useLoad plus the freshness contract (REF-001/REF-002): records lastUpdatedAt,
 * revalidates when the screen regains focus, and polls per domain policy only
 * while the app is active and the screen focused. Use inside routed screens.
 */
export function useFreshLoad<T>(fn: () => Promise<T>, deps: DependencyList, domain: FreshnessDomain) {
  const state = useLoad(fn, deps);
  const [lastUpdatedAt, setLastUpdatedAt] = useState<number | null>(null);
  const [now, setNow] = useState(() => Date.now());
  const first = useRef(true);
  const { reload, loading, error, data } = state;

  useEffect(() => {
    if (!loading && !error && data !== undefined) setLastUpdatedAt(Date.now());
  }, [loading, error, data]);

  useFocusEffect(
    useCallback(() => {
      if (first.current) {
        first.current = false;
      } else {
        void reload();
      }
      const { pollMs } = FRESHNESS[domain];
      const tick = setInterval(() => {
        setNow(Date.now());
        if (pollMs > 0 && AppState.currentState === "active") void reload();
      }, pollMs > 0 ? pollMs : 60_000);
      return () => clearInterval(tick);
    }, [reload, domain]),
  );

  return { ...state, lastUpdatedAt, stale: isStale(lastUpdatedAt, domain, now) };
}

import { useCallback, useEffect, useState } from "react";
import { WalletApi, WalletPolicy } from "@/api/client";

/**
 * The signed-in customer's OWN policies, from /mobile/wallet (party-scoped).
 * The old /policies route is a tenant-wide staff list that leaked other
 * customers' policies (audit A1). Handles both paginator shapes.
 */
export function usePolicies() {
  const [data, setData] = useState<WalletPolicy[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const reload = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setData(await WalletApi.all());
    } catch (e) {
      setError(e instanceof Error ? e.message : "Policies could not be loaded.");
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    void reload();
  }, [reload]);
  return { policies: data, loading, error, reload };
}

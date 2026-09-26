import { useCallback, useEffect, useRef, useState } from "react";
import { InsuranceApi, PolicyApi, QuoteResult, WalletApi } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { RenewalFlow, RenewalPolicy } from "@/lib/renewal";

export type RenewalState = "loading" | "ready" | "missing";

/**
 * Shared loader for the renewal screens. The policy record comes from
 * InsuranceApi.policy (as before) and is enriched, best effort, with the
 * wallet view (product name, insured vehicle, carrier name). The renewal
 * quote is POST /policies/{id}/renewal-quote; its {quote, offers} are pushed
 * into the insurance store (setQuoteResult) so the existing offer/proposal
 * path can take over, and remembered in RenewalFlow for the next steps.
 */
export function useRenewal(id: string | undefined, options: { autoQuote?: boolean } = {}) {
  const setQuote = useInsurance((s) => s.setQuoteResult);
  const cached = RenewalFlow.get(id);
  const [policy, setPolicy] = useState<RenewalPolicy | null>(cached?.policy ?? null);
  const [policyState, setPolicyState] = useState<RenewalState>(cached ? "ready" : "loading");
  const [result, setResult] = useState<QuoteResult | null>(cached?.result ?? null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const quoting = useRef(false);

  const loadPolicy = useCallback(async () => {
    if (!id) {
      setPolicyState("missing");
      return null;
    }
    setPolicyState("loading");
    try {
      const [base, wallet] = await Promise.allSettled([InsuranceApi.policy(id), WalletApi.policy(id)]);
      const record: RenewalPolicy | null =
        base.status === "fulfilled" && wallet.status === "fulfilled"
          ? { ...base.value, ...wallet.value }
          : base.status === "fulfilled"
            ? base.value
            : wallet.status === "fulfilled"
              ? wallet.value
              : null;
      if (!record) throw new Error("policy unavailable");
      setPolicy(record);
      setPolicyState("ready");
      RenewalFlow.start(record, RenewalFlow.get(id)?.result ?? null);
      return record;
    } catch {
      setPolicyState("missing");
      return null;
    }
  }, [id]);

  /** Prepares (or re-prepares) the renewal quote from the platform's current tariffs. */
  const prepare = useCallback(async () => {
    if (!id || quoting.current) return null;
    quoting.current = true;
    setBusy(true);
    setError(null);
    try {
      const r = await PolicyApi.renewalQuote(id);
      setResult(r);
      setQuote(r.quote, r.offers);
      RenewalFlow.update({ result: r });
      return r;
    } catch (e) {
      setError(e);
      return null;
    } finally {
      quoting.current = false;
      setBusy(false);
    }
  }, [id, setQuote]);

  useEffect(() => {
    if (!RenewalFlow.get(id)) void loadPolicy();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  useEffect(() => {
    if (options.autoQuote && policyState === "ready" && !result && !busy && !error) void prepare();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [options.autoQuote, policyState, result]);

  return { policy, policyState, result, busy, error, loadPolicy, prepare };
}

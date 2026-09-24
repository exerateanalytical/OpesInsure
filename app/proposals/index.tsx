import React, { useCallback, useEffect, useState } from "react";
import { router } from "expo-router";
import { FileText } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { FlowRow } from "@/components/FlowPrimitives";
import { ErrorCard, LoadMore } from "@/components/purchase/PurchaseUi";
import { ApiError, InsuranceApi, ProposalSummary, ProposalsApi } from "@/api/client";
import { RecentProposals } from "@/store/insurance";
import { localized, mergePages, proposalStatusInfo } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";

/**
 * "My applications". Uses GET /mobile/proposals when the backend has it;
 * otherwise falls back to the proposals this device opened (each re-read
 * from the server, so status is always current and ownership enforced).
 */
export default function Applications() {
  const f = useFormatters();
  const [items, setItems] = useState<ProposalSummary[]>([]);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loading, setLoading] = useState(true);
  const [more, setMore] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await ProposalsApi.list(1);
      setItems(result.items);
      setPage(1);
      setHasMore(result.info.hasMore);
    } catch (e) {
      if (!(e instanceof ApiError) || (e.status !== 404 && e.status !== 405)) {
        setError(e);
      } else {
        const ids = await RecentProposals.list();
        const found = await Promise.all(ids.map((id) => InsuranceApi.proposal(id).catch(() => null)));
        setItems(found.filter((x): x is NonNullable<typeof x> => !!x));
        setHasMore(false);
      }
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    void load();
  }, [load]);

  const loadMore = async () => {
    setMore(true);
    try {
      const result = await ProposalsApi.list(page + 1);
      setItems((x) => mergePages(x, result.items));
      setPage(page + 1);
      setHasMore(result.info.hasMore);
    } catch (e) {
      setError(e);
    } finally {
      setMore(false);
    }
  };

  return (
    <Screen>
      <AppHeader title="My applications" subtitle="Proposals waiting for review, documents or payment" back />
      {loading && !items.length ? <LoadingState label="Loading applications…" /> : null}
      {error ? <ErrorCard error={error} fallback="Applications could not be loaded." onRetry={() => void load()} /> : null}
      {!loading && !error && !items.length ? (
        <EmptyState title="No applications yet" message="When you choose an offer, your application appears here until the policy is issued." action="Get a quote" onPress={() => router.push("/quote/product")} />
      ) : null}
      {items.length ? (
        <Card>
          {items.map((p) => {
            const info = proposalStatusInfo(p.status);
            const name = p.product_name ?? (localized(p.offer?.product?.name, f.language) || "Insurance application");
            return (
              <FlowRow
                key={p.id}
                icon={FileText}
                title={name}
                subtitle={`${p.proposal_number}${p.terms_snapshot?.total_minor ? ` · ${f.xaf(p.terms_snapshot.total_minor)}` : ""}`}
                status={info.label}
                onPress={() => router.push({ pathname: "/proposals/[id]", params: { id: p.id } })}
              />
            );
          })}
        </Card>
      ) : null}
      <LoadMore hasMore={hasMore} loading={more} onPress={() => void loadMore()} />
    </Screen>
  );
}

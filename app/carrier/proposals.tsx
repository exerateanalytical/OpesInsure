import React from "react";
import { FileSignature } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierWorkspaceApi, money, shortDate } from "@/api/partner";

export default function CarrierProposals() {
  const q = useLoad(() => CarrierWorkspaceApi.proposals(), []);
  return (
    <Screen>
      <AppHeader title="Quotes & proposals" subtitle="Proposals made on your products" back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading proposals…"
        emptyTitle="No proposals"
        emptyMessage="Proposals customers make on your products will appear here."
      >
        {(x) => (
          <OperationsList
            icon={FileSignature}
            rows={x.map((p) => ({
              id: p.id,
              title: `${p.reference} · ${p.customer_name}`,
              subtitle: `${p.product} · ${money(p.premium_minor)} · ${shortDate(p.submitted_at ?? p.created_at)}`,
              status: p.status,
            }))}
          />
        )}
      </StatePanel>
    </Screen>
  );
}

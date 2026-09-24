import React from "react";
import { FileSignature } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerWorkspaceApi, money, shortDate } from "@/api/partner";

export default function BrokerQuotes() {
  const q = useLoad(() => BrokerWorkspaceApi.quotes(), []);
  return (
    <Screen>
      <AppHeader title="Quotes" subtitle="Quotes for clients attributed to your firm" back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading quotes…"
        emptyTitle="No quotes yet"
        emptyMessage="Quotes requested for your clients will appear here."
      >
        {(x) => (
          <OperationsList
            icon={FileSignature}
            rows={x.map((r) => ({
              id: r.id,
              title: `${r.customer_name} · ${r.line_code}`,
              subtitle: [
                r.best_premium_minor !== null ? `best ${money(r.best_premium_minor)}` : `${r.offers} offers`,
                shortDate(r.created_at),
              ].join(" · "),
              status: r.status,
            }))}
          />
        )}
      </StatePanel>
    </Screen>
  );
}

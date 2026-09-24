import React from "react";
import { router } from "expo-router";
import { FileSignature, Plus } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { AgentWorkspaceApi, money, shortDate } from "@/api/partner";

export default function AgentQuotes() {
  const q = useLoad(() => AgentWorkspaceApi.quotes(), []);
  return (
    <Screen>
      <AppHeader title="Quotes" subtitle="Quotes you prepared for your clients" back />
      <Button label="New assisted sale" icon={Plus} onPress={() => router.push("/agent/sales/new")} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading quotes…"
        emptyTitle="No quotes yet"
        emptyMessage="Quotes you prepare for clients will appear here with their status."
      >
        {(x) => (
          <OperationsList
            icon={FileSignature}
            onPress={(id) => router.push(`/agent/sales/${id}`)}
            rows={x.map((r) => ({
              id: r.id,
              title: `${r.customer_name} · ${r.line_code}`,
              subtitle: [
                r.best_premium_minor !== null ? money(r.best_premium_minor) : `${r.offers} offers`,
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

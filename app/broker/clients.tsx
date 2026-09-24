import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { router } from "expo-router";
import { ContactRound } from "lucide-react-native";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerApi } from "@/api/client";
export default function BrokerClients() {
  const q = useLoad(() => BrokerApi.clients(), []);
  const x = q.data ?? [];
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader
        title="Broker client ledger"
        subtitle="Access remains branch and role scoped"
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <OperationsList
            icon={ContactRound}
            rows={x.map((c) => ({
              id: c.id,
              title: c.full_name,
              subtitle: `${c.city} · ${c.policies} policies`,
              status: c.origin_locked ? "ORIGIN LOCKED" : "REVIEW",
            }))}
            onPress={(id) => router.push(`/broker/clients/${id}`)}
          />
          </>
        )}
      </StatePanel>
    </PortalScreen>
  );
}

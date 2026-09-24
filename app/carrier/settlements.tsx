import React from "react";
import { router } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { HandCoins } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierApi } from "@/api/client";
import { fcfa } from "@/api/extra";
export default function CarrierSettlements() {
  const q = useLoad(() => CarrierApi.settlements(), []);
  return (
    <Screen>
      <AppHeader
        title="Carrier settlements"
        subtitle="Read-only until finance reconciliation is complete"
        back
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        emptyTitle="No settlements yet"
        emptyMessage="Settlement batches appear here once finance prepares them."
      >
        {(x) => (
          <OperationsList
            icon={HandCoins}
            onPress={(id) =>
              router.push({ pathname: "/carrier/settlement/[id]", params: { id } })
            }
            rows={x.map((i) => ({
              id: i.id,
              title: i.period,
              subtitle: `Net payable ${fcfa(i.net_payable_minor)}`,
              status: i.status,
            }))}
          />
        )}
      </StatePanel>
    </Screen>
  );
}

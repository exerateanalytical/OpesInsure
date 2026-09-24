import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { HandCoins } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierApi } from "@/api/client";
export default function CarrierSettlements() {
  const q = useLoad(() => CarrierApi.settlements(), []);
  const x = q.data ?? [];
  return (
    <Screen>
      <AppHeader
        title="Carrier settlements"
        subtitle="Read-only until finance reconciliation is complete"
        back
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <OperationsList
            icon={HandCoins}
            rows={x.map((i) => ({
              id: i.id,
              title: i.period,
              subtitle: `Net payable ${new Intl.NumberFormat("fr-CM").format(i.net_payable_minor / 100)} FCFA`,
              status: i.status,
            }))}
          />
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

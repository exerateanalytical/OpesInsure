import React, { useEffect, useState } from "react";
import { HandCoins } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierApi, CarrierSettlement } from "@/api/client";
export default function CarrierSettlements() {
  const [x, setX] = useState<CarrierSettlement[]>([]);
  useEffect(() => {
    CarrierApi.settlements().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Carrier settlements"
        subtitle="Read-only until finance reconciliation is complete"
        back
      />
      <OperationsList
        icon={HandCoins}
        rows={x.map((i) => ({
          id: i.id,
          title: i.period,
          subtitle: `Net payable ${new Intl.NumberFormat("fr-CM").format(i.net_payable_minor / 100)} FCFA`,
          status: i.status,
        }))}
      />
    </Screen>
  );
}

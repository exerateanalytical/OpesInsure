import React, { useEffect, useState } from "react";
import { BookOpenCheck } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerApi, BrokerProduction } from "@/api/client";
export default function BrokerProductionScreen() {
  const [x, setX] = useState<BrokerProduction[]>([]);
  useEffect(() => {
    BrokerApi.production().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Production register"
        subtitle="Mobile view of issued business"
        back
      />
      <OperationsList
        icon={BookOpenCheck}
        rows={x.map((p) => ({
          id: p.id,
          title: p.policy_number,
          subtitle: `${p.customer_name} · ${new Intl.NumberFormat("fr-CM").format(p.premium_minor / 100)} FCFA`,
          status: p.status,
        }))}
      />
    </Screen>
  );
}

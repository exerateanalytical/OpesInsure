import React, { useEffect, useState } from "react";
import { RefreshCw } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { AgentRenewal, BrokerApi } from "@/api/client";
export default function BrokerRenewals() {
  const [x, setX] = useState<AgentRenewal[]>([]);
  useEffect(() => {
    BrokerApi.renewals().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader title="Broker renewals" back />
      <OperationsList
        icon={RefreshCw}
        rows={x.map((r) => ({
          id: r.id,
          title: r.customer_name,
          subtitle: `${r.policy_number} · ${r.days_remaining} days`,
          status: r.status,
        }))}
      />
    </Screen>
  );
}

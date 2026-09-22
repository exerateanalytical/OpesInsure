import React, { useEffect, useState } from "react";
import { router } from "expo-router";
import { ContactRound } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerApi, BrokerClient } from "@/api/client";
export default function BrokerClients() {
  const [x, setX] = useState<BrokerClient[]>([]);
  useEffect(() => {
    BrokerApi.clients().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Broker client ledger"
        subtitle="Access remains branch and role scoped"
        back
      />
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
    </Screen>
  );
}

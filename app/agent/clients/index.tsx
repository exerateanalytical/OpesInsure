import React, { useEffect, useState } from "react";
import { router } from "expo-router";
import { ContactRound, Plus } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi, AgentClient } from "@/api/client";
export default function AgentClients() {
  const [x, setX] = useState<AgentClient[]>([]);
  useEffect(() => {
    AgentApi.clients().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Client portfolio"
        subtitle="Origin ownership is enforced by the server"
        back
      />
      <Button
        label="Register a client"
        icon={Plus}
        onPress={() => router.push("/agent/clients/new")}
      />
      <Card>
        {x.map((c) => (
          <FlowRow
            key={c.id}
            icon={ContactRound}
            title={c.full_name}
            subtitle={`${c.phone_e164} · ${c.city}`}
            status={c.kyc_status}
            onPress={() => router.push(`/agent/clients/${c.id}`)}
          />
        ))}
      </Card>
    </Screen>
  );
}

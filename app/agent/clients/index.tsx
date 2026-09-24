import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { router } from "expo-router";
import { ContactRound, Plus } from "lucide-react-native";
import { AppHeader, Button, Card } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi } from "@/api/client";
export default function AgentClients() {
  const q = useLoad(() => AgentApi.clients(), []);
  const x = q.data ?? [];
  return (
    <PortalScreen tabs={agentTabs}>
      <AppHeader
        title="Client portfolio"
        subtitle="Origin ownership is enforced by the server"
      />
      <Button
        label="Register a client"
        icon={Plus}
        onPress={() => router.push("/agent/clients/new")}
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <Card>
            {x.map((c) => (
              <FlowRow
                key={c.id}
                icon={ContactRound}
                title={c.full_name}
                subtitle={[c.phone_e164, c.city].filter(Boolean).join(" · ")}
                status={c.kyc_status}
                onPress={() => router.push(`/agent/clients/${c.id}`)}
              />
            ))}
          </Card>
          </>
        )}
      </StatePanel>
    </PortalScreen>
  );
}

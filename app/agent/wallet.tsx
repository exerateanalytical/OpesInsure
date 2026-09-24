import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { router } from "expo-router";
import { CircleDollarSign } from "lucide-react-native";
import { AppHeader, Button, Card, Money } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi } from "@/api/client";
export default function AgentWallet() {
  const q = useLoad(() => AgentApi.commissions(), []);
  const x = q.data ?? [];
  const available = x
    .filter((c) => c.status === "AVAILABLE")
    .reduce((n, c) => n + c.amount_minor, 0);
  return (
    <PortalScreen tabs={agentTabs}>
      <AppHeader
        title="Commission wallet"
        subtitle="Server-calculated earnings and reversals"
      />
      <Card feature>
        <Money amount={available / 100} size="large" />
        <Button
          label="Withdraw available commission"
          onPress={() => router.push("/agent/withdrawal")}
        />
      </Card>
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <Card>
            {x.map((c) => (
              <FlowRow
                key={c.id}
                icon={CircleDollarSign}
                title={`${new Intl.NumberFormat("fr-CM").format(c.amount_minor / 100)} FCFA`}
                subtitle={c.policy_id ?? c.reason ?? "Commission adjustment"}
                status={c.status}
              />
            ))}
          </Card>
          </>
        )}
      </StatePanel>
    </PortalScreen>
  );
}

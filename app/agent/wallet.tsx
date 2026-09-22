import React, { useEffect, useState } from "react";
import { router } from "expo-router";
import { CircleDollarSign } from "lucide-react-native";
import { AppHeader, Button, Card, Money, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi, AgentCommission } from "@/api/client";
export default function AgentWallet() {
  const [x, setX] = useState<AgentCommission[]>([]);
  useEffect(() => {
    AgentApi.commissions().then(setX);
  }, []);
  const available = x
    .filter((c) => c.status === "AVAILABLE")
    .reduce((n, c) => n + c.amount_minor, 0);
  return (
    <Screen>
      <AppHeader
        title="Commission wallet"
        subtitle="Server-calculated earnings and reversals"
        back
      />
      <Card feature>
        <Money amount={available / 100} size="large" />
        <Button
          label="Withdraw available commission"
          onPress={() => router.push("/agent/withdrawal")}
        />
      </Card>
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
    </Screen>
  );
}

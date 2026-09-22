import React, { useEffect, useState } from "react";
import { RefreshCw } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi, AgentRenewal } from "@/api/client";
export default function AgentRenewals() {
  const [x, setX] = useState<AgentRenewal[]>([]);
  useEffect(() => {
    AgentApi.renewals().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Renewal pipeline"
        subtitle="Follow up without losing origin attribution"
        back
      />
      <Card>
        {x.map((r) => (
          <FlowRow
            key={r.id}
            icon={RefreshCw}
            title={r.customer_name}
            subtitle={`${r.policy_number} · ${r.days_remaining} days remaining`}
            status={r.status}
          />
        ))}
      </Card>
    </Screen>
  );
}

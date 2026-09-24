import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { RefreshCw } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi } from "@/api/client";
export default function AgentRenewals() {
  const q = useLoad(() => AgentApi.renewals(), []);
  const x = q.data ?? [];
  return (
    <Screen>
      <AppHeader
        title="Renewal pipeline"
        subtitle="Follow up without losing origin attribution"
        back
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
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
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

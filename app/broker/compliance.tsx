import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { BadgeCheck } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerApi } from "@/api/client";
export default function BrokerCompliance() {
  const q = useLoad(() => BrokerApi.compliance(), []);
  const x = q.data ?? [];
  return (
    <Screen>
      <AppHeader
        title="Broker compliance"
        subtitle="Deadlines and documentary obligations"
        back
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <OperationsList
            icon={BadgeCheck}
            rows={x.map((c) => ({
              id: c.id,
              title: c.label,
              subtitle: `Due ${c.due_at} · ${c.severity}`,
              status: c.status,
            }))}
          />
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

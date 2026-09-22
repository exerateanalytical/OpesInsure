import React, { useEffect, useState } from "react";
import { BadgeCheck } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerApi, BrokerComplianceItem } from "@/api/client";
export default function BrokerCompliance() {
  const [x, setX] = useState<BrokerComplianceItem[]>([]);
  useEffect(() => {
    BrokerApi.compliance().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Broker compliance"
        subtitle="Deadlines and documentary obligations"
        back
      />
      <OperationsList
        icon={BadgeCheck}
        rows={x.map((c) => ({
          id: c.id,
          title: c.label,
          subtitle: `Due ${c.due_at} · ${c.severity}`,
          status: c.status,
        }))}
      />
    </Screen>
  );
}

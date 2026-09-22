import React, { useEffect, useState } from "react";
import { FileCheck2 } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierApi, CarrierQueueItem } from "@/api/client";
export default function Issuance() {
  const [x, setX] = useState<CarrierQueueItem[]>([]);
  useEffect(() => {
    CarrierApi.issuance().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Issuance queue"
        subtitle="Payment and underwriting must be verified before issue"
        back
      />
      <OperationsList
        icon={FileCheck2}
        rows={x.map((i) => ({
          id: i.id,
          title: i.reference,
          subtitle: i.subject,
          status: i.status,
        }))}
      />
    </Screen>
  );
}

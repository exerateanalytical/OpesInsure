import React, { useEffect, useState } from "react";
import { ShieldAlert } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierApi, CarrierQueueItem } from "@/api/client";
export default function CarrierClaims() {
  const [x, setX] = useState<CarrierQueueItem[]>([]);
  useEffect(() => {
    CarrierApi.claims().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Carrier claims queue"
        subtitle="Permission-scoped assessment work"
        back
      />
      <OperationsList
        icon={ShieldAlert}
        rows={x.map((i) => ({
          id: i.id,
          title: i.reference,
          subtitle: `${i.subject} · ${i.priority}`,
          status: i.status,
        }))}
      />
    </Screen>
  );
}

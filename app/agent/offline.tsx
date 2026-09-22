import React, { useEffect, useState } from "react";
import { CloudUpload } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi, OfflineFieldItem } from "@/api/client";
export default function AgentOffline() {
  const [x, setX] = useState<OfflineFieldItem[]>([]);
  useEffect(() => {
    AgentApi.offlineQueue().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Offline field activity"
        subtitle="Nothing is treated as submitted until acknowledged by the server"
        back
      />
      {x.map((i) => (
        <Card key={i.id}>
          <FlowRow
            icon={CloudUpload}
            title={i.type.replaceAll("_", " ")}
            subtitle={`${i.local_reference} · ${i.error ?? i.updated_at}`}
            status={i.status}
          />
          {i.status === "FAILED" ? (
            <Button
              label="Retry secure sync"
              variant="secondary"
              onPress={async () => {
                const v = await AgentApi.retryOffline(i.id);
                setX(x.map((a) => (a.id === v.id ? v : a)));
              }}
            />
          ) : null}
        </Card>
      ))}
    </Screen>
  );
}

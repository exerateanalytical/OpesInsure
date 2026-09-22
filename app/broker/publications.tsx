import React, { useEffect, useState } from "react";
import { Store } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { BrokerApi, BrokerPublication } from "@/api/client";
import { Text } from "react-native";
export default function Publications() {
  const [x, setX] = useState<BrokerPublication[]>([]);
  useEffect(() => {
    BrokerApi.publications().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Marketplace publications"
        subtitle="Publication requires platform and carrier approval"
        back
      />
      {x.map((p) => (
        <Card key={p.id}>
          <Store size={22} />
          <Text>{p.product_name}</Text>
          <StatusChip
            label={p.status}
            tone={p.status === "PUBLISHED" ? "success" : "warning"}
          />
          <Text>
            {p.channel} · submitted {p.submitted_at}
          </Text>
          <Button
            label={
              p.status === "PUBLISHED" ? "Unpublish" : "Submit for publication"
            }
            variant="secondary"
            onPress={async () => {
              const v = await BrokerApi.togglePublication(
                p.id,
                p.status !== "PUBLISHED",
              );
              setX(x.map((a) => (a.id === v.id ? v : a)));
            }}
          />
        </Card>
      ))}
    </Screen>
  );
}

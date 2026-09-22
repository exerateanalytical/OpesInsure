import React, { useEffect, useState } from "react";
import { router } from "expo-router";
import { CarFront, Plus } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AssetsApi, RiskAsset } from "@/api/client";
export default function Assets() {
  const [items, setItems] = useState<RiskAsset[]>([]);
  useEffect(() => {
    AssetsApi.list().then(setItems);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Vehicles & assets"
        subtitle="Reusable risk details for faster quotes"
        back
      />
      <Button
        label="Add a vehicle"
        icon={Plus}
        onPress={() => router.push("/assets/new")}
      />
      <Card>
        {items.map((x) => (
          <FlowRow
            key={x.id}
            icon={CarFront}
            title={x.label || x.registration_number || "Vehicle"}
            subtitle={[x.make, x.model, x.year].filter(Boolean).join(" · ")}
            status={x.status}
            onPress={() => router.push(`/assets/${x.id}`)}
          />
        ))}
      </Card>
    </Screen>
  );
}

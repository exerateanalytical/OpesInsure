import React, { useEffect, useState } from "react";
import { useLocalSearchParams, router } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { AssetsApi, RiskAsset } from "@/api/client";
export default function Asset() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [a, setA] = useState<RiskAsset>();
  useEffect(() => {
    AssetsApi.show(id).then(setA);
  }, [id]);
  return (
    <Screen>
      <AppHeader title={a?.label ?? "Vehicle"} back />
      <Card>
        <StatusChip
          label={a?.status ?? "LOADING"}
          tone={a?.status === "VERIFIED" ? "success" : "warning"}
        />
        <Text>{a?.registration_number}</Text>
        <Text>{[a?.make, a?.model, a?.year].filter(Boolean).join(" · ")}</Text>
        <Button
          label="Scan registration card"
          onPress={() => router.push(`/assets/${id}/scan`)}
        />
      </Card>
    </Screen>
  );
}

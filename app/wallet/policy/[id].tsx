import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { WalletApi, WalletPolicy } from "@/api/client";
export default function WalletPolicyScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [p, setP] = useState<WalletPolicy>();
  useEffect(() => {
    WalletApi.policy(id).then(setP);
  }, [id]);
  return (
    <Screen>
      <AppHeader title={p?.policy_number ?? "Policy"} back />
      <Card>
        <StatusChip label={p?.status ?? "LOADING"} tone="success" />
        <Text>{p?.carrier_name}</Text>
        <Text>
          Cover: {p?.coverage_starts_at?.slice(0, 10)} —{" "}
          {p?.coverage_ends_at?.slice(0, 10)}
        </Text>
      </Card>
      <Card>
        <Text>Documents</Text>
        {p?.documents?.map((d) => (
          <Text key={d.id}>• {d.label}</Text>
        ))}
      </Card>
      {p?.delivery ? (
        <Card>
          <StatusChip label={p.delivery.status} tone="info" />
          <Text>Sticker tracking: {p.delivery.tracking_code}</Text>
          <Button
            label="Track physical sticker"
            onPress={() => router.push(`/delivery/${p.delivery!.id}`)}
          />
        </Card>
      ) : null}
    </Screen>
  );
}

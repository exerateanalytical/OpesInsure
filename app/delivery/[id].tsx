import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { Step } from "@/components/FlowPrimitives";
import { StickerDelivery, WalletApi } from "@/api/client";
export default function Delivery() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [d, setD] = useState<StickerDelivery>();
  useEffect(() => {
    WalletApi.delivery(id).then(setD);
  }, [id]);
  return (
    <Screen>
      <AppHeader title="Sticker delivery" subtitle={d?.tracking_code} back />
      <Card>
        <StatusChip label={d?.status ?? "LOADING"} tone="info" />
        <Text>{d?.recipient_name}</Text>
        <Text>
          {d?.address_line}, {d?.city}
        </Text>
        {d?.timeline.map((x) => (
          <Step key={x.label} label={x.label} complete={x.complete} />
        ))}
      </Card>
      <Button
        label="Change delivery address"
        variant="secondary"
        onPress={() => router.push(`/delivery/${id}/address`)}
      />
      <Button
        label="Confirm receipt with OTP"
        onPress={() => router.push(`/delivery/${id}/confirm`)}
      />
    </Screen>
  );
}

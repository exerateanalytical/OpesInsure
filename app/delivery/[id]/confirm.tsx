import React, { useState } from "react";
import { useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { WalletApi } from "@/api/client";
export default function Confirm() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [otp, setOtp] = useState("");
  const [status, setStatus] = useState("");
  return (
    <Screen>
      <AppHeader
        title="Confirm sticker receipt"
        subtitle="Only share the code after receiving the envelope"
        back
      />
      <Card>
        <TextField
          label="6-digit delivery code"
          keyboardType="number-pad"
          maxLength={6}
          value={otp}
          onChangeText={setOtp}
        />
        <Button
          label="Confirm receipt"
          disabled={otp.length !== 6}
          onPress={async () =>
            setStatus((await WalletApi.confirmDelivery(id, otp)).status)
          }
        />
        {status ? <Text>Delivery status: {status}</Text> : null}
      </Card>
    </Screen>
  );
}

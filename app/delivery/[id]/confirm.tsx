import React, { useState } from "react";
import { useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { WalletApi } from "@/api/client";
import { useTranslation } from "@/i18n";
export default function Confirm() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t } = useTranslation();
  const [otp, setOtp] = useState("");
  const [status, setStatus] = useState("");
  return (
    <Screen>
      <AppHeader
        title={t("dlvTitle")}
        subtitle={t("dlvSubtitle")}
        back
      />
      <Card>
        <TextField
          label={t("dlvCode")}
          keyboardType="number-pad"
          maxLength={6}
          value={otp}
          onChangeText={setOtp}
        />
        <Button
          label={t("dlvConfirm")}
          disabled={otp.length !== 6}
          onPress={async () =>
            setStatus((await WalletApi.confirmDelivery(id, otp)).status)
          }
        />
        {status ? <Text>{t("dlvStatus", { status })}</Text> : null}
      </Card>
    </Screen>
  );
}

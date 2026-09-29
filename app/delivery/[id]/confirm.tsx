import React, { useState } from "react";
import { useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { WalletApi } from "@/api/client";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/** Confirm receipt of the insurance sticker with the 6-digit delivery code. */
export default function Confirm() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const [otp, setOtp] = useState("");
  const [status, setStatus] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const confirm = async () => {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      setStatus((await WalletApi.confirmDelivery(id, otp)).status);
    } catch (e) {
      setError(e instanceof Error && e.message ? e.message : t("actionFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <AppHeader title={t("dlvTitle")} subtitle={t("dlvSubtitle")} back />
      <Card>
        <TextField label={t("dlvCode")} keyboardType="number-pad" maxLength={6} value={otp} onChangeText={setOtp} />
        <Button label={t("dlvConfirm")} loading={busy} disabled={otp.length !== 6 || busy} onPress={() => void confirm()} />
        {error ? <Text accessibilityRole="alert" style={{ ...type.meta, color: colors.dangerText }}>{error}</Text> : null}
        {status ? <Text accessibilityLiveRegion="polite" style={{ ...type.meta, color: colors.successText }}>{t("dlvStatus", { status: td(`deliveryStatus_${status}`, status) })}</Text> : null}
      </Card>
    </Screen>
  );
}

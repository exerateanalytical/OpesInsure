import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { StyleSheet, Text } from "react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { Step } from "@/components/FlowPrimitives";
import { StatePanel } from "@/components/StatePanel";
import { WalletApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

export default function Delivery() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { data: d, loading, error, reload } = useLoad(() => WalletApi.delivery(id), [id]);
  return (
    <Screen>
      <AppHeader title={t("deliveryTitle")} subtitle={d?.tracking_code} back />
      <StatePanel loading={loading} error={error} data={d} onRetry={() => void reload()} isEmpty={() => false} loadingLabel={t("deliveryLoading")}>
        {(d) => (
          <>
            <Card>
              <StatusChip label={td(`status_${d.status}`, d.status)} tone="info" />
              <Text style={s.title}>{d.recipient_name}</Text>
              <Text style={s.body}>{[d.address_line, d.city].filter(Boolean).join(", ")}</Text>
              {d.timeline.map((x) => (
                <Step key={x.label} label={x.label} complete={x.complete} />
              ))}
            </Card>
            <Button label={t("deliveryChangeAddress")} variant="secondary" onPress={() => router.push(`/delivery/${id}/address`)} />
            <Button label={t("deliveryConfirmOtp")} onPress={() => router.push(`/delivery/${id}/confirm`)} />
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  title: { ...type.label, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
});

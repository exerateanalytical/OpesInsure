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
  const { t, td, date } = useTranslation();
  const { data: d, loading, error, reload } = useLoad(() => WalletApi.delivery(id), [id]);
  return (
    <Screen>
      <AppHeader title={t("deliveryTitle")} subtitle={d?.tracking_code ?? undefined} back />
      <StatePanel loading={loading} error={error} data={d} onRetry={() => void reload()} isEmpty={() => false} loadingLabel={t("deliveryLoading")}>
        {(d) => (
          <>
            <Card>
              <StatusChip label={td(`deliveryStatus_${d.status}`, d.status)} tone={d.status === "DELIVERED" ? "success" : "info"} />
              {d.recipient_name ? <Text style={s.title}>{d.recipient_name}</Text> : null}
              <Text style={s.body}>{[d.address_line, d.city].filter(Boolean).join(", ")}</Text>
              {d.tracking_code ? <Text style={s.body}>{t("deliveryTracking", { code: d.tracking_code })}</Text> : null}
              {d.courier?.name ? <Text style={s.body}>{t("deliveryCourier", { name: d.courier.name })}</Text> : null}
              {d.eta && d.status !== "DELIVERED" ? <Text style={s.body}>{t("deliveryEta", { date: date(d.eta) })}</Text> : null}
              {(d.timeline ?? []).map((x) => (
                <Step
                  key={x.status}
                  label={`${td(`deliveryStatus_${x.status}`, x.status)}${x.occurred_at && x.complete ? ` · ${date(x.occurred_at)}` : ""}`}
                  complete={x.complete}
                />
              ))}
            </Card>
            {d.can_change_address !== false ? (
              <Button label={t("deliveryChangeAddress")} variant="secondary" onPress={() => router.push(`/delivery/${id}/address`)} />
            ) : null}
            {d.can_confirm !== false ? (
              <Button label={t("deliveryConfirmOtp")} onPress={() => router.push(`/delivery/${id}/confirm`)} />
            ) : null}
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

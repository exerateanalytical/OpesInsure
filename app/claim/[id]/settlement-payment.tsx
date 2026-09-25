import React from "react";
import { StyleSheet, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { AppHeader, Card, Money, Screen, StatusChip } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { ClaimsCompletionApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

export default function SettlementPayment() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { data, loading, error, reload } = useLoad(() => ClaimsCompletionApi.settlement(id), [id]);
  return (
    <Screen>
      <AppHeader title={t("settlePayTitle")} subtitle={t("settlePaySubtitle")} back />
      <StatePanel loading={loading} error={error} data={data} onRetry={() => void reload()} isEmpty={() => false} loadingLabel={t("settleLoading")}>
        {(x) => (
          <Card feature>
            <StatusChip
              label={x.payment_status ? td(`status_${x.payment_status}`, x.payment_status) : t("settlePayNotAvailable")}
              tone={x.payment_status === "PAID" ? "success" : "warning"}
            />
            <Money amount={x.net_minor / 100} size="large" />
            <Text style={s.body}>{t("settlePayRef", { ref: x.payment_reference ?? t("settlePayRefPending") })}</Text>
          </Card>
        )}
      </StatePanel>
      <Card>
        <Text style={s.body}>{t("settlePayWarning")}</Text>
      </Card>
    </Screen>
  );
}
const s = StyleSheet.create({ body: { ...type.body, color: colors.neutral700 } });

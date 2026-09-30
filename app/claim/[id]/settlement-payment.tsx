import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { Hash, ShieldAlert } from "lucide-react-native";
import { Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { SettlementHero } from "@/components/claims/SettlementHero";
import { ClaimsCompletionApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

/** Settlement payment tracking (settlement dashboard family): payment status, net amount and reference. */
export default function SettlementPayment() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { data, loading, error, reload } = useLoad(() => ClaimsCompletionApi.settlement(id), [id]);
  return (
    <Screen>
      <BrandHeader title={t("settlePayTitle")} subtitle={t("settlePaySubtitle")} />
      <StatePanel loading={loading} error={error} data={data} onRetry={() => void reload()} isEmpty={(x) => !x} emptyTitle={t("settleNone")} emptyMessage={t("settleNoneBody")} loadingLabel={t("settleLoading")}>
        {(x) => !x ? null : (
          <>
            <SettlementHero settlement={x} />
            <Card>
              <View style={s.row}>
                <Text style={[s.title, s.flex]}>{t("settleTracking")}</Text>
                <StatusChip
                  label={x.payment_status ? td(`status_${x.payment_status}`, x.payment_status) : t("settlePayNotAvailable")}
                  tone={x.payment_status === "PAID" ? "success" : "warning"}
                />
              </View>
              <View style={s.row}>
                <Hash size={18} color={colors.navy800} />
                <Text style={[s.body, s.flex]}>{t("settlePayRef", { ref: x.payment_reference ?? t("settlePayRefPending") })}</Text>
              </View>
            </Card>
          </>
        )}
      </StatePanel>
      <Banner icon={ShieldAlert} tint="gold" body={t("settlePayWarning")} />
    </Screen>
  );
}
const s = StyleSheet.create({
  flex: { flex: 1 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  title: { ...type.cardTitle, color: colors.navy900 },
  body: { ...type.body, color: colors.neutral700 },
});

import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { StyleSheet, Text } from "react-native";
import { AppHeader, Button, Card, Money, Screen, StatusChip } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { ClaimsCompletionApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

export default function Repair() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { xaf } = useFormatters();
  const { data: x, loading, error, reload } = useLoad(() => ClaimsCompletionApi.repair(id), [id]);
  return (
    <Screen>
      <AppHeader title={t("repairTitle")} back />
      <StatePanel loading={loading} error={error} data={x} onRetry={() => void reload()} isEmpty={() => false} loadingLabel={t("repairLoading")}>
        {(x) => (
          <Card feature>
            <StatusChip label={td(`status_${x.status}`, x.status)} tone="warning" />
            {x.garage_name ? <Text style={s.title}>{x.garage_name}</Text> : null}
            {x.estimate_minor != null ? (
              <>
                <Text style={s.body}>{t("repairEstimate")}</Text>
                <Money amount={x.estimate_minor / 100} />
              </>
            ) : null}
            {x.approved_minor != null ? (
              <>
                <Text style={s.body}>{t("repairApproved")}</Text>
                <Money amount={x.approved_minor / 100} />
              </>
            ) : null}
            {x.deductible_minor != null ? <Text style={s.body}>{t("repairDeductible", { amount: xaf(x.deductible_minor) })}</Text> : null}
            {x.authorization_reference ? <Text style={s.body}>{t("repairAuthorization", { ref: x.authorization_reference })}</Text> : null}
          </Card>
        )}
      </StatePanel>
      <Button label={t("repairViewSettlement")} disabled={!x} onPress={() => router.push(`/claim/${id}/settlement`)} />
    </Screen>
  );
}
const s = StyleSheet.create({
  title: { ...type.label, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
});

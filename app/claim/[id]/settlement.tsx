import React, { useState } from "react";
import { Alert, StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Money, Screen, StatusChip } from "@/components/ui";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ClaimsCompletionApi } from "@/api/client";
import { handleStepUpRequired } from "@/security/step-up";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { isNotFound } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

export default function Settlement() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { xaf, date } = useFormatters();
  const { data: x, setData: setX, loading, error, reload } = useLoad(() => ClaimsCompletionApi.settlement(id), [id]);
  const [actionError, setActionError] = useState<unknown>(null);
  const decide = (decision: "ACCEPT" | "REJECT") =>
    Alert.alert(
      decision === "ACCEPT" ? t("settleAcceptQ") : t("settleRejectQ"),
      decision === "ACCEPT" ? t("settleAcceptBody") : t("settleRejectBody"),
      [
        { text: t("cancel"), style: "cancel" },
        {
          text: decision === "ACCEPT" ? t("settleAccept") : t("settleReject"),
          style: decision === "REJECT" ? "destructive" : "default",
          onPress: async () => {
            setActionError(null);
            try {
              setX(await ClaimsCompletionApi.decideSettlement(id, decision));
            } catch (e) {
              if (!handleStepUpRequired(e, "CLAIM_SETTLEMENT_DECISION", `/claim/${id}/settlement`)) setActionError(e);
            }
          },
        },
      ],
    );
  if (!x)
    return (
      <Screen>
        <AppHeader title={t("settleTitle")} back />
        {loading ? (
          <LoadingState label={t("settleLoading")} />
        ) : error && !isNotFound(error) ? (
          <ErrorState error={error} onRetry={() => void reload()} />
        ) : (
          <EmptyState title={t("settleNone")} message={t("settleNoneBody")} action={t("refresh")} onPress={() => void reload()} />
        )}
      </Screen>
    );
  return (
    <Screen>
      <AppHeader title={t("settleTitle")} subtitle={t("settleDue", { date: date(x.decision_deadline) })} back />
      <Card feature>
        <StatusChip label={td(`status_${x.status}`, x.status)} tone={x.status === "ACCEPTED" ? "success" : "warning"} />
        <Text style={s.body}>{t("settleAssessed")}</Text>
        <Money amount={x.offered_minor / 100} />
        <Text style={s.body}>{t("settleDeductible", { amount: xaf(x.deductible_minor) })}</Text>
        <Text style={s.body}>{t("settleNet")}</Text>
        <Money amount={x.net_minor / 100} size="large" />
        <Text style={s.body}>{x.terms}</Text>
      </Card>
      {actionError ? <ErrorCard error={actionError} fallback={t("errGeneric")} onRetry={() => void reload()} retryLabel={t("refresh")} /> : null}
      {x.status === "OFFERED" ? (
        <>
          <Button label={t("settleAcceptCta")} onPress={() => decide("ACCEPT")} />
          <Button label={t("settleRejectCta")} variant="secondary" onPress={() => decide("REJECT")} />
        </>
      ) : null}
      <Button label={t("settleTrackPayment")} variant="secondary" onPress={() => router.push(`/claim/${id}/settlement-payment`)} />
    </Screen>
  );
}
const s = StyleSheet.create({ body: { ...type.body, color: colors.neutral700 } });

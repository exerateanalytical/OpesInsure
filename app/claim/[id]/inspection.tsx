import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { StyleSheet, Text } from "react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ClaimsCompletionApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { isNotFound } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

export default function Inspection() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const f = useFormatters();
  const { data: x, setData: setX, loading, error, reload } = useLoad(() => ClaimsCompletionApi.inspection(id), [id]);
  const [date, setDate] = useState("");
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<unknown>(null);
  useEffect(() => {
    if (x?.appointment_at) setDate(x.appointment_at);
  }, [x?.appointment_at]);
  const reschedule = async () => {
    setBusy(true);
    setActionError(null);
    try {
      setX(await ClaimsCompletionApi.rescheduleInspection(id, date));
    } catch (e) {
      setActionError(e);
    } finally {
      setBusy(false);
    }
  };
  return (
    <Screen>
      <AppHeader title={t("inspTitle")} subtitle={t("inspSubtitle")} back />
      {loading && !x ? (
        <LoadingState label={t("inspLoading")} />
      ) : error && !x && !isNotFound(error) ? (
        <ErrorState error={error} onRetry={() => void reload()} />
      ) : !x ? (
        <EmptyState title={t("inspNone")} message={t("inspNoneBody")} action={t("refresh")} onPress={() => void reload()} />
      ) : (
        <>
          <Card>
            <StatusChip label={x.status ? td(`status_${x.status}`, x.status) : t("inspNotScheduled")} tone="info" />
            {x.appointment_at ? <Text style={s.title}>{f.dateTime(x.appointment_at)}</Text> : null}
            {x.location ? <Text style={s.body}>{x.location}</Text> : null}
            {x.surveyor_name || x.contact_phone ? (
              <Text style={s.body}>{[x.surveyor_name, x.contact_phone].filter(Boolean).join(" · ")}</Text>
            ) : null}
            {x.notes ? <Text style={s.body}>{x.notes}</Text> : null}
          </Card>
          <Card>
            <TextField label={t("inspRequestTime")} value={date} onChangeText={setDate} />
            <Button label={t("inspReschedule")} variant="secondary" loading={busy} disabled={!date.trim()} onPress={() => void reschedule()} />
            {actionError ? <ErrorCard error={actionError} fallback={t("errGeneric")} /> : null}
          </Card>
        </>
      )}
      <Button label={t("inspViewRepair")} onPress={() => router.push(`/claim/${id}/repair`)} />
    </Screen>
  );
}
const s = StyleSheet.create({
  title: { ...type.label, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
});

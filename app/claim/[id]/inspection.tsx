import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { StyleSheet, Text } from "react-native";
import { CalendarClock, Pencil } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ReviewRows, ReviewSection } from "@/components/review/ReviewSummary";
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
  // The requested time is shown read-only next to the current appointment before it is sent.
  const [reviewing, setReviewing] = useState(false);
  useEffect(() => {
    if (x?.appointment_at) setDate(x.appointment_at);
  }, [x?.appointment_at]);
  const reschedule = async () => {
    setBusy(true);
    setActionError(null);
    try {
      setX(await ClaimsCompletionApi.rescheduleInspection(id, date));
      setReviewing(false);
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
          {reviewing ? (
            <>
              <ReviewSection icon={CalendarClock} title={t("inspReviewTitle")} onEdit={() => setReviewing(false)}>
                <ReviewRows
                  rows={[
                    { key: "current", label: t("inspReviewCurrent"), value: x.appointment_at ? f.dateTime(x.appointment_at) : null },
                    { key: "requested", label: t("inspReviewRequested"), value: Number.isFinite(Date.parse(date)) ? f.dateTime(date) : date.trim() || null },
                  ]}
                />
              </ReviewSection>
              {actionError ? <ErrorCard error={actionError} fallback={t("errGeneric")} /> : null}
              <Button label={t("inspReschedule")} icon={CalendarClock} loading={busy} onPress={() => void reschedule()} />
              <Button label={t("reviewBackToForm")} icon={Pencil} variant="tertiary" disabled={busy} onPress={() => setReviewing(false)} />
            </>
          ) : (
            <Card>
              <TextField label={t("inspRequestTime")} value={date} onChangeText={setDate} />
              <Button
                label={t("reviewContinue")}
                variant="secondary"
                disabled={!date.trim()}
                onPress={() => {
                  setActionError(null);
                  setReviewing(true);
                }}
              />
            </Card>
          )}
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

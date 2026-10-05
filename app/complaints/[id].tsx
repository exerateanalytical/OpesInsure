import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { CalendarCheck, CalendarClock, FileText, Gavel, Headset, History, Mail, MessageSquareWarning, Scale } from "lucide-react-native";
import { Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, DetailRow, SectionHeading, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { ComplaintsApi } from "@/api/customerFlows";
import { complaintTone } from "@/lib/customerFlows";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * SHR-014 — one complaint (GET /mobile/complaints/{id}): status, acknowledgement,
 * answer deadline, outcome and resolution, the case timeline and the letters
 * exchanged with the customer. Internal case data is never sent by the server.
 */
export default function ComplaintDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const f = useFormatters();
  const q = useLoad(() => ComplaintsApi.show(id), [id]);
  return (
    <Screen>
      <BrandHeader title={t("cplDetailTitle")} back right="help" />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("cplLoading")}>
        {(c) => (
          <>
            <Card style={s.card}>
              <View style={s.row}>
                <TintedIcon icon={MessageSquareWarning} tint={c.open ? "gold" : "neutral"} size={48} />
                <View style={s.flex}>
                  <Text style={s.ref}>{c.complaint_number}</Text>
                  <StatusChip label={td(`cplStatus_${c.status}`, c.status)} tone={complaintTone(c.status, c.open)} />
                </View>
              </View>
              <Text style={s.body}>{c.description}</Text>
              {c.subject_type && c.subject_id ? (
                <Text
                  style={s.link}
                  accessibilityRole="link"
                  onPress={() =>
                    c.subject_type === "claim"
                      ? router.push({ pathname: "/claim/[id]", params: { id: c.subject_id as string } })
                      : router.push({ pathname: "/policy/[id]", params: { id: c.subject_id as string } })
                  }
                >
                  {c.subject_type === "claim" ? t("cplOpenClaim") : t("cplOpenPolicy")}
                </Text>
              ) : null}
            </Card>

            <Card style={s.card}>
              <SectionHeading title={t("cplHandling")} icon={Scale} />
              <DetailRow icon={CalendarCheck} label={t("cplReceived")} value={f.dateTime(c.received_at)} />
              <DetailRow icon={Mail} label={t("cplAcknowledged")} value={c.acknowledged_at ? f.dateTime(c.acknowledged_at) : t("cplNotYet")} />
              {c.open ? <DetailRow icon={CalendarClock} label={t("cplDue")} value={c.due_at ? f.date(c.due_at) : "—"} strong /> : null}
              {c.escalated_at ? <DetailRow icon={Headset} label={t("cplEscalated")} value={f.dateTime(c.escalated_at)} /> : null}
              {c.closed_at ? <DetailRow icon={CalendarCheck} label={t("cplClosed")} value={f.dateTime(c.closed_at)} /> : null}
            </Card>

            {c.outcome || c.resolution_summary ? (
              <Card style={s.card}>
                <SectionHeading title={t("cplOutcome")} icon={Gavel} />
                {c.outcome ? <StatusChip label={td(`cplOutcome_${c.outcome}`, c.outcome)} tone="info" /> : null}
                {c.resolution_summary ? <Text style={s.body}>{c.resolution_summary}</Text> : null}
                {c.communicated_at ? <Text style={s.meta}>{t("cplCommunicated", { date: f.date(c.communicated_at) })}</Text> : null}
              </Card>
            ) : null}

            <Card style={s.card}>
              <SectionHeading title={t("cplTimeline")} icon={History} />
              {(c.timeline ?? []).length === 0 ? <Text style={s.meta}>{t("cplTimelineEmpty")}</Text> : null}
              {(c.timeline ?? []).map((e, i) => (
                <View key={`${e.occurred_at}-${i}`} style={s.event}>
                  <View style={s.dot} />
                  <View style={s.flex}>
                    <Text style={s.eventTitle}>{e.to_status ? td(`cplStatus_${e.to_status}`, e.to_status) : td(`cplEvent_${e.type}`, e.type)}</Text>
                    <Text style={s.meta}>{f.dateTime(e.occurred_at)}</Text>
                  </View>
                </View>
              ))}
            </Card>

            {(c.correspondence ?? []).length ? (
              <Card style={s.card}>
                <SectionHeading title={t("cplLetters")} icon={FileText} />
                {(c.correspondence ?? []).map((m, i) => (
                  <View key={`${m.reference_number ?? i}`} style={s.letter}>
                    <Text style={s.eventTitle}>{m.subject_line ?? (m.direction === "OUTBOUND" ? t("cplLetterFromUs") : t("cplLetterFromYou"))}</Text>
                    {m.summary ? <Text style={s.body}>{m.summary}</Text> : null}
                    <Text style={s.meta}>
                      {m.direction === "OUTBOUND" ? t("cplLetterFromUs") : t("cplLetterFromYou")} · {f.date(m.dispatched_at ?? m.received_at ?? m.created_at)}
                    </Text>
                  </View>
                ))}
              </Card>
            ) : null}
            {c.open ? <Banner icon={Headset} tint="blue" title={t("cplNeedMore")} body={t("cplNeedMoreBody")} onPress={() => router.push("/support")} /> : null}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  flex: { flex: 1, gap: 4 },
  card: { borderRadius: radius.feature, gap: space.x3 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  ref: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral500 },
  link: { ...type.label, color: colors.blue600 },
  event: { flexDirection: "row", gap: space.x3, alignItems: "flex-start" },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.blue600, marginTop: 6 },
  eventTitle: { ...type.label, color: colors.navy950 },
  letter: { gap: 2, borderTopWidth: 1, borderTopColor: colors.neutral100, paddingTop: space.x2 },
});

import React, { useCallback, useMemo } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useFocusEffect } from "expo-router";
import { CalendarClock, ChevronRight, Info, MessageSquareWarning, Plus } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { applyFilters, FilterToolbar, optionsFrom, periodMatcher, periodSection, useListFilters, type FilterSection, type Matchers } from "@/components/filters";
import { ComplaintsApi, type Complaint } from "@/api/customerFlows";
import { complaintTone } from "@/lib/customerFlows";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * SHR-013/014 — the customer's formal complaints register (GET /mobile/complaints,
 * REQ-CPL-001). Distinct from support tickets: a complaint has a regulatory
 * acknowledgement and answer deadline. Search + one filter sheet (status, period).
 */
export default function Complaints() {
  const { t, td, date } = useTranslation();
  const q = useLoad(() => ComplaintsApi.list());
  const reload = q.reload;
  useFocusEffect(
    useCallback(() => {
      void reload();
    }, [reload]),
  );
  const all = useMemo(() => q.data ?? [], [q.data]);
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "status", title: t("filterStatus"), options: optionsFrom(all, (c) => ({ value: c.status, label: td(`cplStatus_${c.status}`, c.status) })) },
      periodSection(t, "period", t("fltCreated")),
    ],
    [all, t, td],
  );
  const flt = useListFilters("customer.complaints", sections);
  const matchers: Matchers<Complaint> = { status: (c, v) => c.status === v, period: periodMatcher((c) => c.received_at) };
  const haystack = (c: Complaint) => [c.complaint_number, c.description, td(`cplStatus_${c.status}`, c.status)];
  const rows = applyFilters(all, flt.values, matchers, flt.query, haystack);

  return (
    <Screen>
      <BrandHeader title={t("cplTitle")} subtitle={t("cplSubtitle")} back right="help" />
      <Button label={t("cplNew")} icon={Plus} onPress={() => router.push("/complaints/new")} />
      <Banner icon={Info} tint="blue" body={t("cplIntro")} />
      {q.loading && !q.data ? (
        <LoadingState label={t("cplLoading")} />
      ) : q.error && !q.data ? (
        <ErrorState onRetry={() => void q.reload()} error={q.error} />
      ) : all.length === 0 ? (
        <EmptyState title={t("cplEmpty")} message={t("cplEmptyBody")} />
      ) : (
        <View style={s.list}>
          <SectionHeading title={t("cplYours")} icon={MessageSquareWarning} />
          <FilterToolbar
            filters={flt}
            sections={sections}
            placeholder={t("cplSearch")}
            count={(v) => applyFilters(all, v, matchers, flt.query, haystack).length}
            resultCount={flt.active ? rows.length : undefined}
          />
          {!rows.length ? <EmptyState title={t("fltNoMatches")} message={t("fltNoMatchesBody")} action={t("fltClearAll")} onPress={flt.clear} /> : null}
          {rows.map((c) => (
            <Card
              key={c.id}
              style={s.card}
              accessibilityLabel={[c.complaint_number, td(`cplStatus_${c.status}`, c.status)].join(", ")}
              onPress={() => router.push({ pathname: "/complaints/[id]", params: { id: c.id } })}
            >
              <View style={s.row}>
                <TintedIcon icon={MessageSquareWarning} tint={c.open ? "gold" : "neutral"} size={48} />
                <View style={s.flex}>
                  <Text style={s.ref}>{c.complaint_number}</Text>
                  <Text style={s.body} numberOfLines={2}>
                    {c.description}
                  </Text>
                  <Text style={s.meta}>{t("cplReceivedOn", { date: date(c.received_at) })}</Text>
                </View>
                <ChevronRight size={20} color={colors.neutral500} />
              </View>
              <View style={s.foot}>
                <StatusChip label={td(`cplStatus_${c.status}`, c.status)} tone={complaintTone(c.status, c.open)} />
                {c.open && c.due_at ? (
                  <View style={s.due}>
                    <CalendarClock size={14} color={colors.gold600} />
                    <Text style={s.dueText}>{t("cplAnswerBy", { date: date(c.due_at) })}</Text>
                  </View>
                ) : null}
              </View>
            </Card>
          ))}
        </View>
      )}
    </Screen>
  );
}
const s = StyleSheet.create({
  flex: { flex: 1 },
  list: { gap: space.x3 },
  card: { borderRadius: radius.feature },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  ref: { ...type.label, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700, marginTop: 2 },
  meta: { ...type.meta, color: colors.neutral500, marginTop: 2 },
  foot: { flexDirection: "row", alignItems: "center", gap: space.x2, flexWrap: "wrap" },
  due: { flexDirection: "row", alignItems: "center", gap: 6, backgroundColor: colors.gold50, borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 5 },
  dueText: { ...type.caption, color: colors.gold600 },
});

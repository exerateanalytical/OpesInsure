import React, { useCallback, useMemo } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useFocusEffect } from "expo-router";
import { ChevronRight, CircleHelp, Clock3, Plus, Ticket } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { SupportApi } from "@/api/client";
import { rows } from "@/api/extra";
import { SupportContactList } from "@/components/auth/SupportContacts";
import { useLoad } from "@/hooks/useLoad";
import { applyFilters, FilterToolbar, optionsFrom, periodMatcher, periodSection, useListFilters, type FilterSection, type Matchers } from "@/components/filters";
import type { SupportCase } from "@/api/client";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

const CLOSED = ["RESOLVED", "CLOSED", "CANCELLED"];

export default function Support() {
  const { t, td, date } = useTranslation();
  const q = useLoad(async () => rows(await SupportApi.list()));
  const reload = q.reload;
  useFocusEffect(
    useCallback(() => {
      void reload();
    }, [reload]),
  );
  const all = useMemo(() => q.data ?? [], [q.data]);
  // Shared list standard (FLT-001..006): status, category, period + search on reference / subject.
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "status", title: t("filterStatus"), options: optionsFrom(all, (c) => ({ value: c.status, label: td(`supportStatus_${c.status}`, c.status) })) },
      { key: "category", title: t("fltType"), options: optionsFrom(all, (c) => ({ value: c.category, label: td(`supportCategory_${c.category}`, c.category) })) },
      periodSection(t, "period", t("fltCreated")),
    ],
    [all, t, td],
  );
  const flt = useListFilters("customer.support", sections);
  const matchers: Matchers<SupportCase> = { status: (c, v) => c.status === v, category: (c, v) => c.category === v, period: periodMatcher((c) => c.created_at) };
  const haystack = (c: SupportCase) => [c.reference, c.subject, td(`supportStatus_${c.status}`, c.status), td(`supportCategory_${c.category}`, c.category)];
  const cases = applyFilters(all, flt.values, matchers, flt.query, haystack);
  return (
    <Screen>
      <BrandHeader title={t("helpComplaints")} subtitle={t("supportSubtitle")} back right="help" />
      <Button label={t("supportNewTicket")} icon={Plus} onPress={() => router.push("/support/new")} />
      <Button label={t("faqTitle")} icon={CircleHelp} variant="secondary" onPress={() => router.push("/support/faq")} />
      {q.loading && !q.data ? (
        <LoadingState label={t("supportLoading")} />
      ) : q.error && !q.data ? (
        <ErrorState onRetry={() => void q.reload()} />
      ) : all.length === 0 ? (
        <EmptyState title={t("supportEmpty")} message={t("supportEmptyBody")} />
      ) : (
        <View style={styles.list}>
          <SectionHeading title={t("supportYourCases")} icon={Ticket} />
          <FilterToolbar
            filters={flt}
            sections={sections}
            placeholder={t("fltSearchTickets")}
            count={(v) => applyFilters(all, v, matchers, flt.query, haystack).length}
            resultCount={flt.active ? cases.length : undefined}
          />
          {!cases.length ? <EmptyState title={t("fltNoMatches")} message={t("fltNoMatchesBody")} action={t("fltClearAll")} onPress={flt.clear} /> : null}
          {cases.map((c) => {
            const closed = CLOSED.includes(c.status);
            const waiting = c.status === "WAITING_CUSTOMER";
            return (
              <Card
                key={c.id}
                style={styles.card}
                accessibilityLabel={[c.reference, c.subject, td(`supportStatus_${c.status}`, c.status)].join(", ")}
                onPress={() => router.push({ pathname: "/support/[id]", params: { id: c.id } })}
              >
                <View style={styles.headRow}>
                  <TintedIcon icon={Ticket} tint={closed ? "neutral" : waiting ? "gold" : "blue"} size={48} />
                  <View style={styles.flex}>
                    <Text style={styles.reference}>{c.reference}</Text>
                    <Text style={styles.subject}>{c.subject}</Text>
                    <Text style={styles.meta} numberOfLines={1}>
                      {td(`supportCategory_${c.category}`, c.category)} · {date(c.created_at)}
                    </Text>
                  </View>
                  <ChevronRight size={20} color={colors.neutral500} />
                </View>
                <View style={styles.footRow}>
                  <StatusChip label={td(`supportStatus_${c.status}`, c.status)} tone={closed ? "neutral" : waiting ? "warning" : "success"} />
                  {!closed ? (
                    <View style={styles.next}>
                      <Clock3 size={14} color={colors.gold600} />
                      <Text style={styles.nextText} numberOfLines={1}>
                        {t("supportNextUpdate")}: {waiting ? td(`supportStatus_${c.status}`, c.status) : t("supportAwaitingResponse")}
                      </Text>
                    </View>
                  ) : null}
                </View>
              </Card>
            );
          })}
        </View>
      )}
      <SupportContactList heading={t("talkToUs")} />
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1 },
  list: { gap: space.x3 },
  card: { borderRadius: radius.feature },
  headRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  reference: { ...type.label, color: colors.navy950 },
  subject: { ...type.body, color: colors.neutral700, marginTop: 2 },
  meta: { ...type.meta, color: colors.neutral500, marginTop: 2 },
  footRow: { flexDirection: "row", alignItems: "center", gap: space.x2, flexWrap: "wrap" },
  next: { flexDirection: "row", alignItems: "center", gap: 6, backgroundColor: colors.gold50, borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 5, flexShrink: 1 },
  nextText: { ...type.caption, color: colors.gold600, flexShrink: 1 },
});

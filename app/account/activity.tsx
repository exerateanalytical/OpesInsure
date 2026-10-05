import React, { useMemo, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { History } from "lucide-react-native";
import { Card, Screen } from "@/components/ui";
import { Banner, BrandHeader } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { LoadMore } from "@/components/purchase/PurchaseUi";
import { applyFilters, FilterToolbar, optionsFrom, periodMatcher, periodSection, useListFilters, type FilterSection, type Matchers } from "@/components/filters";
import { ActivityApi, type ActivityEntry } from "@/api/customerFlows";
import { activityFallback, activityKey } from "@/lib/customerFlows";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

const PAGE = 50;

/**
 * SHR-008 — what was done on the account (GET mobile/account/activity): the caller's
 * own audit trail, newest first, 50 at a time. Sign-ins stay on Login activity.
 */
export default function AccountActivity() {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const q = useLoad(() => ActivityApi.list(undefined, PAGE));
  const [more, setMore] = useState<{ rows: ActivityEntry[]; done: boolean; loading: boolean; error: unknown }>({ rows: [], done: false, loading: false, error: null });
  const all = useMemo(() => [...(q.data ?? []), ...more.rows], [q.data, more.rows]);
  const label = (a: ActivityEntry) => td(`act_${activityKey(a.action)}`, activityFallback(a.action));
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "subject", title: t("actSubject"), options: optionsFrom(all.filter((a) => a.subject_type), (a) => ({ value: String(a.subject_type), label: td(`actSubject_${a.subject_type}`, activityFallback(String(a.subject_type))) })) },
      periodSection(t, "period", t("actWhen")),
    ],
    [all, t, td],
  );
  const flt = useListFilters("customer.activity", sections);
  const matchers: Matchers<ActivityEntry> = { subject: (a, v) => a.subject_type === v, period: periodMatcher((a) => a.occurred_at) };
  const rows = applyFilters(all, flt.values, matchers, flt.query, (a) => [label(a), a.subject_type ?? "", a.source ?? ""]);
  const hasMore = !more.done && (q.data?.length ?? 0) >= PAGE && (more.rows.length === 0 || more.rows.length % PAGE === 0);

  const loadMore = async () => {
    const last = all[all.length - 1];
    if (!last) return;
    setMore((m) => ({ ...m, loading: true, error: null }));
    try {
      const next = await ActivityApi.list(last.sequence, PAGE);
      setMore((m) => ({ rows: [...m.rows, ...next], done: next.length < PAGE, loading: false, error: null }));
    } catch (e) {
      setMore((m) => ({ ...m, loading: false, error: e }));
    }
  };

  return (
    <Screen>
      <BrandHeader title={t("actTitle")} subtitle={t("actSubtitle")} back right="help" />
      <Banner icon={History} tint="blue" body={t("actIntro")} />
      <StatePanel {...q} onRetry={q.reload} emptyTitle={t("actEmpty")} emptyMessage={t("actEmptyBody")} loadingLabel={t("actLoading")}>
        {() => (
          <>
            <FilterToolbar filters={flt} sections={sections} placeholder={t("actSearch")} count={(v) => applyFilters(all, v, matchers, flt.query, (a) => [label(a)]).length} resultCount={flt.active ? rows.length : undefined} />
            <Card style={s.card}>
              {rows.length === 0 ? <Text style={s.meta}>{t("fltNoMatches")}</Text> : null}
              {rows.map((a) => (
                <View key={a.sequence} style={s.row}>
                  <View style={s.dot} />
                  <View style={s.flex}>
                    <Text style={s.title}>{label(a)}</Text>
                    <Text style={s.meta}>
                      {f.dateTime(a.occurred_at)}
                      {a.source ? ` · ${td(`actSource_${a.source}`, a.source)}` : ""}
                    </Text>
                  </View>
                </View>
              ))}
            </Card>
            <LoadMore hasMore={hasMore} loading={more.loading} error={more.error} onPress={() => void loadMore()} />
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  flex: { flex: 1, gap: 2 },
  card: { borderRadius: radius.feature, gap: space.x3 },
  row: { flexDirection: "row", gap: space.x3, alignItems: "flex-start" },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.blue600, marginTop: 7 },
  title: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
});

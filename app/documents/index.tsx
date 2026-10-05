import React, { useCallback, useMemo, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronRight, FileText, FolderOpen } from "lucide-react-native";
import { Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { LoadMore } from "@/components/purchase/PurchaseUi";
import { applyFilters, FilterToolbar, optionsFrom, periodMatcher, periodSection, useListFilters, type FilterSection, type Matchers } from "@/components/filters";
import { DocumentsApi, type SecureDocument } from "@/api/client";
import { documentHaystack } from "@/lib/customerFlows";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * My documents (GET mobile/documents): every document the customer holds —
 * certificates, schedules, receipts, claim letters and their own uploads — with
 * search and one filter sheet (type, status, period). Opening one goes through
 * documents/[id] (short-lived signed link, in-app viewer).
 */
export default function DocumentsHub() {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const first = useLoad(() => DocumentsApi.list(undefined, undefined, 1));
  const [extra, setExtra] = useState<{ rows: SecureDocument[]; page: number; hasMore: boolean | null; loading: boolean; error: unknown }>({ rows: [], page: 1, hasMore: null, loading: false, error: null });
  const all = useMemo(() => [...(first.data?.items ?? []), ...extra.rows], [first.data, extra.rows]);
  const hasMore = extra.hasMore ?? first.data?.info.hasMore ?? false;
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "owner", title: t("dhType"), options: optionsFrom(all, (d) => ({ value: d.owner_type, label: td(`dhOwner_${d.owner_type}`, d.owner_type) })) },
      { key: "status", title: t("filterStatus"), options: optionsFrom(all, (d) => ({ value: d.status, label: td(`status_${d.status}`, d.status) })) },
      periodSection(t, "period", t("dhIssued")),
    ],
    [all, t, td],
  );
  const flt = useListFilters("customer.documents", sections);
  const matchers: Matchers<SecureDocument> = { owner: (d, v) => d.owner_type === v, status: (d, v) => d.status === v, period: periodMatcher((d) => d.issued_at) };
  const rows = applyFilters(all, flt.values, matchers, flt.query, documentHaystack);

  const loadMore = useCallback(async () => {
    const page = extra.page + 1;
    setExtra((x) => ({ ...x, loading: true, error: null }));
    try {
      const r = await DocumentsApi.list(undefined, undefined, page);
      setExtra((x) => ({ rows: [...x.rows, ...r.items], page, hasMore: r.info.hasMore, loading: false, error: null }));
    } catch (e) {
      setExtra((x) => ({ ...x, loading: false, error: e }));
    }
  }, [extra.page]);

  return (
    <Screen>
      <BrandHeader title={t("dhTitle")} subtitle={t("dhSubtitle")} back right="help" />
      <StatePanel {...first} onRetry={first.reload} isEmpty={(p) => p.items.length === 0} emptyTitle={t("dhEmpty")} emptyMessage={t("dhEmptyBody")} loadingLabel={t("dhLoading")}>
        {() => (
          <View style={s.list}>
            <FilterToolbar
              filters={flt}
              sections={sections}
              placeholder={t("dhSearch")}
              count={(v) => applyFilters(all, v, matchers, flt.query, documentHaystack).length}
              resultCount={flt.active ? rows.length : undefined}
            />
            {rows.length === 0 ? <Text style={s.meta}>{t("fltNoMatches")}</Text> : null}
            {rows.map((d) => (
              <Card key={d.id} style={s.card} accessibilityLabel={[d.label, td(`dhOwner_${d.owner_type}`, d.owner_type)].join(", ")} onPress={() => router.push({ pathname: "/documents/[id]", params: { id: d.id } })}>
                <View style={s.row}>
                  <TintedIcon icon={d.owner_type === "CLAIM" ? FolderOpen : FileText} tint="blue" size={44} />
                  <View style={s.flex}>
                    <Text style={s.title} numberOfLines={2}>{d.label}</Text>
                    <Text style={s.meta}>
                      {td(`dhOwner_${d.owner_type}`, d.owner_type)} · {f.date(d.issued_at)}
                    </Text>
                    <StatusChip label={td(`status_${d.status}`, d.status)} tone={/REVOKED|EXPIRED|CANCELLED|SUPERSEDED/.test(d.status) ? "neutral" : "success"} />
                  </View>
                  <ChevronRight size={20} color={colors.neutral500} />
                </View>
              </Card>
            ))}
            <LoadMore hasMore={hasMore} loading={extra.loading} error={extra.error} onPress={() => void loadMore()} />
          </View>
        )}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  list: { gap: space.x3 },
  card: { borderRadius: radius.feature },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  flex: { flex: 1, gap: 4, alignItems: "flex-start" },
  title: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
});

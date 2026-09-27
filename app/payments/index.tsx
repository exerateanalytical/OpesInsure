import React, { useEffect, useMemo, useState } from "react";
import { FlatList, RefreshControl, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { CalendarDays, CarFront, ChevronRight, CreditCard, HeartPulse, Home, Plane, ShieldCheck, Wallet } from "lucide-react-native";
import { Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, SectionHeading, TintedIcon, type Tint } from "@/components/design";
import { InstitutionMark } from "@/components/InstitutionMark";
import { EmptyState, LoadingState } from "@/components/StatePanel";
import { ErrorCard, LoadMore } from "@/components/purchase/PurchaseUi";
import { useInsurerLogos } from "@/components/offers/useInsurerLogo";
import { Payment, PaymentsApi, WalletApi, WalletPolicy } from "@/api/client";
import { usePagedList } from "@/hooks/usePagedList";
import { useFormatters } from "@/hooks/useFormatters";
import { useListState } from "@/hooks/useListState";
import { byDate, byNumber, FilterToolbar, inPeriod, optionsFrom, periodMatcher, periodSection, runList, sortSection, totals, useListFilters, type FilterSection, type FilterValues, type Matchers, type Sorters } from "@/components/filters";
import { networkName, paymentStatusInfo, type Tone } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

const toneTint = (tone: Tone): Tint => (tone === "success" ? "green" : tone === "warning" ? "gold" : tone === "danger" ? "red" : tone === "info" ? "blue" : "neutral");

/** Status filter chips (design: All / Successful / Pending / Failed / Refunded). */
type Filter = "all" | "succeeded" | "pending" | "failed" | "refunded";
const FILTERS: Record<Exclude<Filter, "all">, string[]> = {
  succeeded: ["SUCCEEDED"],
  pending: ["CREATED", "PENDING_CUSTOMER", "PROCESSING", "REFUND_PENDING"],
  failed: ["FAILED", "EXPIRED", "CANCELLED"],
  refunded: ["REFUNDED"],
};
const bucketOf = (p: Payment): Filter => (Object.keys(FILTERS) as Exclude<Filter, "all">[]).find((k) => FILTERS[k].includes(String(p.status).toUpperCase())) ?? "all";

/** Line icon from the wallet policy's product name (payments carry no line code). */
function lineIcon(name: string | null | undefined) {
  const n = String(name ?? "").toLowerCase();
  if (/motor|auto|véhicule|vehicle|car/.test(n)) return CarFront;
  if (/health|santé|sante|medical/.test(n)) return HeartPulse;
  if (/travel|voyage/.test(n)) return Plane;
  if (/home|habitation|property|multirisque/.test(n)) return Home;
  return ShieldCheck;
}

/**
 * Payment history (design opesinsure_payment_history_dashboard): status
 * filters, a paid-this-year total once every page is loaded, and one row per
 * payment with the product and insurer from the customer's wallet policy.
 */
export default function Payments() {
  const { t } = useTranslation();
  const f = useFormatters();
  const logoFor = useInsurerLogos();
  const list = usePagedList<Payment>((page) => PaymentsApi.list(page));
  const [policies, setPolicies] = useState<Record<string, WalletPolicy>>({});

  // Payments carry policy_id only; product and insurer come from the wallet (same data as the web app).
  useEffect(() => {
    let live = true;
    WalletApi.all()
      // Indexed by policy id and by proposal id: payment rows carry proposal_id, and policy_id only once issued.
      .then((rows) => live && setPolicies(Object.fromEntries(rows.flatMap((p) => [[p.id, p] as const, ...(p.proposal_id ? [[`proposal:${p.proposal_id}`, p] as const] : [])]))))
      .catch(() => undefined);
    return () => {
      live = false;
    };
  }, []);

  const policyOf = (p: Payment) => (p.policy_id ? policies[p.policy_id] : undefined) ?? (p.proposal_id ? policies[`proposal:${p.proposal_id}`] : undefined);
  // Shared list standard (FLT-001..006): status tabs + sheet (status, network, period, sort) + search.
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "status", title: t("filterStatus"), options: (Object.keys(FILTERS) as Exclude<Filter, "all">[]).map((k) => ({ value: k, label: t(`phFilter_${k}`) })) },
      { key: "network", title: t("fltPaymentNetwork"), options: optionsFrom(list.items, (p) => ({ value: p.provider, label: networkName(p.provider) })) },
      periodSection(t, "period", t("fltPaidOn")),
      sortSection(t, [
        { value: "recent", label: t("fltSortRecent") },
        { value: "oldest", label: t("fltSortOldest") },
        { value: "amount_desc", label: t("fltSortAmountHigh") },
        { value: "amount_asc", label: t("fltSortAmountLow") },
      ]),
    ],
    [list.items, t],
  );
  const flt = useListFilters("customer.payments", sections);
  const matchers: Matchers<Payment> = { status: (p, v) => bucketOf(p) === v, network: (p, v) => p.provider === v, period: periodMatcher((p) => p.created_at) };
  const sorters: Sorters<Payment> = {
    recent: byDate((p) => p.created_at),
    oldest: byDate((p) => p.created_at, "asc"),
    amount_desc: byNumber((p) => p.amount_minor),
    amount_asc: byNumber((p) => p.amount_minor, "asc"),
  };
  const haystack = (p: Payment) => {
    const pol = policyOf(p);
    return [pol?.product_name, pol?.carrier_name, pol?.policy_number, networkName(p.provider), p.provider_reference, p.payer_phone_e164, paymentStatusInfo(p.status, f.language).label];
  };
  const run = (v: FilterValues) => runList(list.items, { values: v, text: flt.query, matchers, haystack, sorters });
  const visible = run(flt.values);
  // The endpoint has no server filters: while a filter is on, fetch every page so results and totals cover the whole history.
  const { hasMore, loadAll } = list;
  useEffect(() => {
    if (flt.active && hasMore) void loadAll();
  }, [flt.active, hasMore, loadAll]);
  const filteredPaid = totals(visible, (p) => p.amount_minor, (p) => p.status === "SUCCEEDED");
  // Only a complete history gives an honest yearly total.
  const year = new Date().getFullYear();
  const paidThisYear = useMemo(() => totals(list.items, (p) => p.amount_minor, (p) => p.status === "SUCCEEDED" && inPeriod(p.created_at, "this_year")), [list.items]);
  const complete = !list.hasMore && !list.loading && list.items.length > 0;

  const listState = useListState("customer.payments");
  return (
    <Screen scroll={false}>
      <FlatList
        {...listState}
        data={visible}
        keyExtractor={(p) => p.id}
        showsVerticalScrollIndicator={false}
        contentContainerStyle={s.content}
        refreshControl={<RefreshControl refreshing={list.loading && list.items.length > 0} onRefresh={() => void list.reload()} />}
        onEndReachedThreshold={0.4}
        onEndReached={() => {
          if (!list.moreError) void list.loadMore();
        }}
        ListHeaderComponent={
          <View style={s.header}>
            <BrandHeader title={t("paymentsReceipts")} subtitle={t("paymentsSubtitle")} back right={null} />
            <FilterToolbar
              filters={flt}
              sections={sections}
              filled
              placeholder={t("fltSearchPayments")}
              count={(v) => run(v).length}
              resultCount={flt.active ? visible.length : undefined}
            />
            {list.fetchingAll ? <Text style={s.meta}>{t("fltLoadingAll")}</Text> : null}
            {flt.active && !list.hasMore && visible.length ? (
              <Text style={s.meta} accessibilityLiveRegion="polite">
                {t("fltTotalFiltered", { count: visible.length })}: {f.xaf(filteredPaid.total)} ({t("phPaymentsCount", { count: filteredPaid.count })}, {t("phFilter_succeeded")})
              </Text>
            ) : null}
            {complete ? (
              <View style={s.summary} accessible accessibilityLabel={`${t("phPaidThisYear", { year })}: ${f.xaf(paidThisYear.total)}`}>
                <TintedIcon icon={Wallet} tint="blue" size={52} />
                <View style={s.flex}>
                  <Text style={s.summaryLabel}>{t("phPaidThisYear", { year })}</Text>
                  <Text style={s.summaryValue}>{f.xaf(paidThisYear.total)}</Text>
                  <Text style={s.meta}>{t("phPaymentsCount", { count: paidThisYear.count })}</Text>
                </View>
              </View>
            ) : null}
            {list.loading && !list.items.length ? <LoadingState label={t("paymentsLoading")} /> : null}
            {list.error && !list.items.length ? <ErrorCard error={list.error} fallback={t("paymentsLoadFailed")} onRetry={() => void list.reload()} /> : null}
            {list.items.length ? <SectionHeading title={t("phHistory")} right={<Text style={s.meta}>{t("phPaymentsCount", { count: visible.length })}</Text>} /> : null}
          </View>
        }
        renderItem={({ item: p }) => {
          const status = paymentStatusInfo(p.status, f.language);
          const policy = policyOf(p);
          const network = networkName(p.provider);
          const when = p.created_at ? f.date(p.created_at) : null;
          const Icon = policy ? lineIcon(policy.product_name) : CreditCard;
          const insurer = policy?.carrier_name ?? null;
          return (
            <Card
              style={s.card}
              accessibilityLabel={[policy?.product_name, insurer, f.xaf(p.amount_minor), network, when, status.label].filter(Boolean).join(", ")}
              onPress={() => router.push({ pathname: "/payments/[id]", params: { id: p.id } })}
            >
              <View style={s.row}>
                <TintedIcon icon={Icon} tint={policy ? "blue" : toneTint(status.tone)} size={48} />
                <View style={s.flex}>
                  <Text style={s.title}>{policy?.product_name ?? t("phPremiumPayment")}</Text>
                  {insurer ? (
                    <View style={s.insurerRow}>
                      <InstitutionMark logoUrl={logoFor(policy?.carrier_id, insurer)} initials={insurer.slice(0, 2).toUpperCase()} size={22} />
                      <Text style={s.sub}>{insurer}</Text>
                    </View>
                  ) : null}
                  <View style={s.right}>
                    <Text style={s.amount}>{f.xaf(p.amount_minor)}</Text>
                    <StatusChip label={status.label} tone={status.tone} />
                  </View>
                  {policy?.policy_number ? <Text style={s.meta}>{policy.policy_number}</Text> : null}
                  <View style={s.metaRow}>
                    {when ? (
                      <>
                        <CalendarDays size={14} color={colors.neutral600} />
                        <Text style={s.meta}>{when}</Text>
                      </>
                    ) : null}
                    {network ? <Text style={s.meta}>{when ? " · " : ""}{network}</Text> : null}
                  </View>
                </View>
                <ChevronRight size={20} color={colors.neutral500} />
              </View>
            </Card>
          );
        }}
        ListEmptyComponent={
          !list.loading && !list.error ? (
            list.items.length ? (
              <EmptyState title={t("phNoMatch")} message={t("phNoMatchBody")} action={t("fltClearAll")} onPress={flt.clear} />
            ) : (
              <EmptyState title={t("paymentsEmpty")} message={t("paymentsEmptyBody")} action={t("refresh")} onPress={() => void list.reload()} />
            )
          ) : null
        }
        ListFooterComponent={
          <View style={s.footer}>
            <LoadMore hasMore={list.hasMore} loading={list.loadingMore} error={list.moreError} onPress={() => void list.loadMore()} />
          </View>
        }
      />
    </Screen>
  );
}
const s = StyleSheet.create({
  flex: { flex: 1 },
  content: { paddingBottom: space.x16 },
  header: { gap: space.x4, marginBottom: space.x4 },
  footer: { marginTop: space.x4 },
  summary: { flexDirection: "row", alignItems: "center", gap: space.x3, backgroundColor: colors.blue50, borderRadius: radius.feature, padding: space.x4 },
  summaryLabel: { ...type.body, color: colors.neutral700 },
  summaryValue: { fontFamily: "Inter_700Bold", fontSize: 22, lineHeight: 28, color: colors.navy950, fontVariant: ["tabular-nums"] },
  card: { borderRadius: radius.feature, marginBottom: space.x3 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  right: { flexDirection: "row", alignItems: "center", flexWrap: "wrap", gap: space.x2, marginVertical: 4 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  insurerRow: { flexDirection: "row", alignItems: "center", gap: 6, marginTop: 2 },
  metaRow: { flexDirection: "row", alignItems: "center", gap: 4, marginTop: 4, flexWrap: "wrap" },
  amount: { fontFamily: "Inter_700Bold", fontSize: 16, lineHeight: 22, color: colors.navy950, fontVariant: ["tabular-nums"], },
  sub: { ...type.body, fontSize: 14, lineHeight: 20, color: colors.neutral700, flexShrink: 1 },
  meta: { ...type.meta, color: colors.neutral600 },
});

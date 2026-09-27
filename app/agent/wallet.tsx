import React, { useMemo, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ArrowUpRight, ChevronDown, CircleDollarSign, WalletCards } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentButton, AgentCard, AgentEmptyState, AgentSection, AgentShell, AgentSkeleton, HeritageAccent } from "@/components/agent";
import { SearchBar } from "@/components/SearchBar";
import { OptionSheet } from "@/components/forms/SelectField";
import { AgentApi } from "@/api/client";
import { AgentWorkspaceApi, money } from "@/api/partner";
import { PERIOD_PRESETS, type FilterValues } from "@/components/filters/core";
import { useListFilters } from "@/components/filters";
import { earningsSummary, runEarnings, TILE_FILTER, withdrawalVocab, type EarningsRow, type Tile } from "@/components/partner/agentEarnings";
import { CommissionItem, EarningsFiltersSheet, earningsFilterCount, WithdrawalItem } from "@/components/partner/AgentEarningsUi";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";

/**
 * Commercial Agent Earnings Overview (AGENT_UI_SPEC_V2 screen 05). Live
 * server rows only; the hero and tiles are display sums over the rows in the
 * chosen period (agentEarnings.earningsSummary), never a balance decided here:
 * the server re-checks the available balance on every withdrawal request.
 */
export default function AgentEarnings() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentWorkspaceApi.commissionLedger() as Promise<EarningsRow[]>, []);
  const wq = useLoad(() => AgentApi.withdrawals(), []);
  const rows = useMemo(() => q.data ?? [], [q.data]);
  const withdrawals = useMemo(() => wq.data ?? [], [wq.data]);

  // Selection + search kept for the app session (back / tab switch keeps them).
  const f = useListFilters("agent-earnings", []);
  const [sheet, setSheet] = useState(false);
  const [periodSheet, setPeriodSheet] = useState(false);
  const [paidOnly, setPaidOnly] = useState(false);

  const period = f.values.period?.[0] ?? "this_month";
  const inPeriod = useMemo(() => runEarnings(rows, { period: [period] }), [rows, period]);
  const summary = earningsSummary(inPeriod, withdrawals);
  const shown = useMemo(() => runEarnings(rows, { ...f.values, period: [period] }, f.query), [rows, f.values, period, f.query]);
  const filterCount = earningsFilterCount({ ...f.values, period: period === "this_month" ? [] : [period] });
  const shownWithdrawals = paidOnly ? withdrawals.filter((w) => withdrawalVocab(w.status) === "paid") : withdrawals;
  const periodLabel = period.startsWith("custom:") ? period.slice(7).replace("..", " – ") : t(`fltPeriod_${period}` as CopyKey);

  const tileOn = (k: Tile) =>
    k === "withdrawn" ? paidOnly : JSON.stringify(Object.fromEntries(Object.keys(TILE_FILTER[k]).map((x) => [x, f.values[x] ?? []]))) === JSON.stringify(TILE_FILTER[k]);
  const tap = (k: Tile) => {
    if (k === "withdrawn") return setPaidOnly((v) => !v);
    const on = tileOn(k);
    const base: FilterValues = { ...f.values, vocab: [], payment: [] };
    f.setValues(on ? base : { ...base, ...TILE_FILTER[k] });
  };
  const tiles: [Tile, CopyKey, number][] = [
    ["available", "ernAvailable", summary.available],
    ["pending", "ernPending", summary.pending],
    ["paid_month", "ernPaidMonth", summary.paidMonth],
    ["withdrawn", "ernWithdrawn", summary.withdrawn],
  ];
  const setPeriod = (v: string) => {
    f.setValues({ ...f.values, period: [v] });
    setPeriodSheet(false);
  };
  const reload = () => {
    q.reload();
    wq.reload();
  };

  return (
    <AgentShell refreshing={q.loading && !!q.data} onRefresh={reload}>
      <View style={s.head}>
        <View style={s.headText}>
          <Text accessibilityRole="header" style={s.title}>{t("ernTitle")}</Text>
          <Text style={s.subtitle}>{t("ernSubtitle")}</Text>
        </View>
        <Pressable accessibilityRole="button" accessibilityLabel={`${t("ernPeriod")}: ${periodLabel}`} onPress={() => setPeriodSheet(true)} hitSlop={4} style={({ pressed }) => [s.period, pressed && s.pressed]}>
          <Text style={s.periodText} numberOfLines={1}>{periodLabel}</Text>
          <ChevronDown size={16} color={c.navy} strokeWidth={2} />
        </Pressable>
      </View>

      {q.loading && !q.data ? (
        <AgentSkeleton rows={4} height={72} />
      ) : q.error && !q.data ? (
        <AgentEmptyState icon={CircleDollarSign} title={t("ernLoadError")} body={t("loadErrorBody")} actionLabel={t("ernRetry")} onAction={reload} />
      ) : (
        <>
          <View style={s.hero} accessibilityLabel={`${t("ernTotalEarned")}: ${money(summary.total)}, ${periodLabel}`}>
            <HeritageAccent variant="africa" size={200} opacity={0.08} style={s.heroArt} />
            <View style={s.goldRule} />
            <Text style={s.heroLabel}>{t("ernTotalEarned").toUpperCase()}</Text>
            <Text style={s.heroAmount} numberOfLines={1} adjustsFontSizeToFit>{money(summary.total)}</Text>
            <Text style={s.heroMeta}>{periodLabel}</Text>
          </View>

          <View style={s.tiles}>
            {tiles.map(([k, label, v]) => {
              const on = tileOn(k);
              return (
                <Pressable
                  key={k}
                  accessibilityRole="button"
                  accessibilityState={{ selected: on }}
                  accessibilityLabel={t("ernTileA11y", { label: t(label), amount: money(v) })}
                  onPress={() => tap(k)}
                  style={({ pressed }) => [s.tile, on && s.tileOn, pressed && s.pressed]}
                >
                  <Text style={s.tileLabel} numberOfLines={1}>{t(label)}</Text>
                  <Text style={s.tileValue} numberOfLines={1} adjustsFontSizeToFit>{money(v)}</Text>
                </Pressable>
              );
            })}
          </View>

          <AgentButton icon={ArrowUpRight} label={t("ernRequestWithdrawal")} onPress={() => router.push("/agent/withdrawal")} />

          <SearchBar
            value={f.text}
            onChangeText={f.setText}
            placeholder={t("ernSearch")}
            label={t("ernSearch")}
            clearLabel={t("clearSearch")}
            onFilter={() => setSheet(true)}
            filterLabel={t("ernFilters")}
            filterCount={filterCount}
          />
          {filterCount > 0 || f.query ? (
            <View style={s.resultRow}>
              <Text style={s.meta} accessibilityLiveRegion="polite">{t("ernShowing", { n: shown.length, total: rows.length })}</Text>
              <Pressable accessibilityRole="button" onPress={() => { f.setValues({ period: [period] }); f.setText(""); }} hitSlop={8}>
                <Text style={s.link}>{t("ernClear")}</Text>
              </Pressable>
            </View>
          ) : null}

          <AgentSection title={t("ernCommissions")}>
            {rows.length === 0 ? (
              <AgentEmptyState icon={CircleDollarSign} title={t("ernNoCommissions")} body={t("ernNoCommissionsBody")} />
            ) : shown.length === 0 ? (
              <AgentEmptyState icon={CircleDollarSign} title={t("ernNoMatch")} body={t("ernNoMatchBody")} actionLabel={t("ernClear")} onAction={() => { f.setValues({ period: ["any"] }); f.setText(""); }} />
            ) : (
              <AgentCard padded={false}>
                {shown.map((r, i) => (
                  <CommissionItem key={r.id} row={r} first={i === 0} onPress={() => router.push({ pathname: "/agent/commissions/[id]", params: { id: r.id } })} />
                ))}
              </AgentCard>
            )}
          </AgentSection>

          <View>
            <AgentSection title={t("ernWithdrawals")}>
              {wq.loading && !wq.data ? (
                <AgentSkeleton rows={2} height={62} />
              ) : shownWithdrawals.length === 0 ? (
                <AgentEmptyState icon={WalletCards} title={t("ernNoWithdrawals")} body={t("ernNoWithdrawalsBody")} />
              ) : (
                <AgentCard padded={false}>
                  {shownWithdrawals.map((w, i) => (
                    <WithdrawalItem key={w.id} row={w} first={i === 0} onPress={() => router.push({ pathname: "/agent/withdrawals/[id]", params: { id: w.id } })} />
                  ))}
                </AgentCard>
              )}
            </AgentSection>
          </View>
        </>
      )}

      <OptionSheet
        visible={periodSheet}
        title={t("ernPeriod")}
        value={period}
        options={PERIOD_PRESETS.map((p) => ({ value: p, label: t(`fltPeriod_${p}` as CopyKey) }))}
        onPick={setPeriod}
        onClose={() => setPeriodSheet(false)}
      />
      <EarningsFiltersSheet visible={sheet} onClose={() => setSheet(false)} rows={rows} value={{ ...f.values, period: [period] }} onApply={(v) => f.setValues(v)} text={f.query} />
    </AgentShell>
  );
}

const s = StyleSheet.create({
  pressed: { opacity: 0.85 },
  head: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: 12 },
  headText: { flex: 1, gap: 4 },
  title: { ...T.screenTitle, color: c.heading },
  subtitle: { ...T.secondary, color: c.secondary },
  period: { minHeight: 40, maxWidth: 150, flexDirection: "row", alignItems: "center", gap: 4, paddingHorizontal: 12, borderRadius: 99, borderWidth: 1, borderColor: c.borderStrong, backgroundColor: c.surface, marginTop: 2 },
  periodText: { ...T.caption, fontFamily: "Inter_600SemiBold", color: c.navy, flexShrink: 1 },
  hero: { backgroundColor: c.deepNavy, borderRadius: L.cardRadius, padding: 20, gap: 6, overflow: "hidden" },
  heroArt: { position: "absolute", right: -40, top: -30 },
  goldRule: { width: 28, height: 3, borderRadius: 2, backgroundColor: c.gold, marginBottom: 6 },
  heroLabel: { ...T.caption, color: c.lightBlue, letterSpacing: 0.6 },
  heroAmount: { ...T.heroAmount, color: c.surface },
  heroMeta: { ...T.secondary, color: c.lightBlue },
  tiles: { flexDirection: "row", flexWrap: "wrap", gap: L.rowGap + 4 },
  tile: { flexBasis: "46%", flexGrow: 1, minHeight: 76, backgroundColor: c.surface, borderWidth: 1, borderColor: c.border, borderRadius: L.cardRadius, padding: 14, gap: 4, justifyContent: "center" },
  tileOn: { borderColor: c.gold, backgroundColor: c.softGold },
  tileLabel: { ...T.caption, color: c.secondary },
  tileValue: { ...T.cardTitle, fontFamily: "Inter_700Bold", color: c.heading },
  resultRow: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", gap: 12 },
  meta: { ...T.caption, color: c.secondary, flex: 1 },
  link: { ...T.caption, fontFamily: "Inter_600SemiBold", color: c.actionBlue },
});

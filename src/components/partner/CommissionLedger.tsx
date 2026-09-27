import React, { useMemo } from "react";
import { StyleSheet, Text, View } from "react-native";
import { CircleDollarSign, TrendingDown, TrendingUp } from "lucide-react-native";
import { Card, Chip, ChipRow } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { EmptyState } from "@/components/StatePanel";
import { useColumns } from "@/components/responsive";
import { FilterToolbar, periodSection, sortSection, useListFilters, type FilterSection, type FilterValues } from "@/components/filters";
import { humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";
import {
  availableBases,
  basisDate,
  basisOf,
  commissionTotals,
  COMMISSION_STATES,
  COMMISSION_SORTS,
  CommissionRow,
  distinct,
  kpiRows,
  LIFECYCLES,
  Lifecycle,
  owedAmount,
  periodComparison,
  quarterValue,
  runCommissions,
  stageOf,
} from "./commissionFilters";

const QUICK_PERIODS = ["any", "this_month", "last_month", "quarter", "this_year"] as const;

/**
 * Filterable commission ledger shared by the agent wallet and the broker
 * earnings screen, on the shared list framework (search, sheet, active pills,
 * clear all, saved + recent filters, state kept on back). KPI cards sum the
 * current selection minus the lifecycle and drill into exactly those rows
 * (COM-006); lifecycle + period quick chips (COM-004/005); insurer / product /
 * producer / status / date basis / sort in the sheet (COM-001/002/003).
 * `producers` only for a broker admin; rows arrive already scoped by the server.
 */
export function CommissionLedger({
  rows,
  onOpen,
  producers = false,
  listKey = "commissions",
  initial,
}: {
  rows: CommissionRow[];
  onOpen?: (row: CommissionRow) => void;
  producers?: boolean;
  /** Filter memory key (per portal). */
  listKey?: string;
  /** Deep-link preselection (`?f_lifecycle=unpaid`). */
  initial?: { values: FilterValues; text?: string };
}) {
  const { t, td } = useTranslation();
  const grid = useColumns();
  const statusLabel = (s: string) => td(`commissionStatus_${s}`, humanize(s));
  const stageLabel = (r: CommissionRow) => t(`pcStage_${stageOf(r)}` as const);
  const bases = availableBases(rows);

  const sections = useMemo<FilterSection[]>(
    () =>
      [
        {
          key: "lifecycle",
          single: true,
          title: t("pcLifecycle"),
          options: LIFECYCLES.map((l) => ({ value: l, label: t(`pcLife_${l}` as const) })),
        },
        periodSection(t),
        {
          key: "basis",
          title: t("pcDateBasis"),
          single: true,
          options: bases.map((b) => ({ value: b, label: t(`pcBasis_${b}` as const) })),
        },
        {
          key: "statuses",
          title: t("pcStatus"),
          options: [...new Set([...COMMISSION_STATES.filter((s) => rows.some((r) => r.status === s)), ...rows.map((r) => r.status)])].map((s) => ({
            value: s,
            label: statusLabel(s),
          })),
        },
        { key: "carriers", title: t("pcInsurer"), options: distinct(rows, (r) => [r.carrier_id, r.carrier_name]) },
        {
          key: "lines",
          title: t("pcProduct"),
          options: distinct(rows, (r) => [r.line_code, r.line_code ? td(`line_${r.line_code}`, humanize(r.line_code)) : null]),
        },
        ...(producers ? [{ key: "producers", title: t("pcProducer"), options: distinct(rows, (r) => [r.producer_id, r.producer_name]) }] : []),
        sortSection(
          t,
          COMMISSION_SORTS.map((s) => ({ value: s, label: t(`pcSort_${s}` as const) })),
        ),
      ].filter((s) => s.options.length > 0 && !(s.key === "basis" && s.options.length < 2)),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [rows, producers, t, td, bases.join()],
  );
  const f = useListFilters(listKey, sections, initial);
  const shown = useMemo(() => runCommissions(rows, f.values, f.query), [rows, f.values, f.query]);
  const totals = commissionTotals(kpiRows(rows, f.values, f.query));
  const shownTotals = commissionTotals(shown);
  const compare = periodComparison(rows, f.values, f.query);
  const basis = basisOf(f.values, rows);
  const lifecycle = (f.pick("lifecycle") ?? "all") as Lifecycle;
  const period = f.pick("period") ?? "any";
  const setValue = (key: string, v: string) => f.setValues({ ...f.values, [key]: [v] });

  /** KPI -> the exact lifecycle rows it sums (COM-006). */
  const kpis: [string, number, Lifecycle][] = [
    [t("pcEarned"), totals.total, "all"],
    [t("pcUnpaid"), totals.unpaid, "unpaid"],
    [t("pcLife_payable"), totals.available, "payable"],
    [t("pcPaid"), totals.paid, "paid"],
  ];
  const quick = (
    <>
      <ChipRow exclusive>
        {LIFECYCLES.map((l) => (
          <Chip
            key={l}
            role="tab"
            label={t(`pcLife_${l}` as const)}
            selected={lifecycle === l}
            count={runCommissions(rows, { ...f.values, lifecycle: [l] }, f.query).length}
            onPress={() => setValue("lifecycle", l)}
          />
        ))}
      </ChipRow>
      <ChipRow exclusive>
        {QUICK_PERIODS.map((p) => {
          const value = p === "quarter" ? quarterValue() : p;
          return (
            <Chip
              key={p}
              role="tab"
              label={p === "quarter" ? t("pcPeriod_quarter") : t(`fltPeriod_${p}` as const)}
              selected={period === value}
              onPress={() => setValue("period", value)}
            />
          );
        })}
        <Chip
          role="tab"
          label={t("pcPeriod_custom")}
          selected={period.startsWith("custom:") && period !== quarterValue()}
          onPress={() => f.setOpen(true)}
        />
      </ChipRow>
    </>
  );

  return (
    <View style={s.wrap}>
      <View style={grid.row}>
        {kpis.map(([label, v, l]) => (
          <Card
            key={label}
            style={[s.metric, grid.item, lifecycle === l && s.metricOn]}
            onPress={() => setValue("lifecycle", l)}
            accessibilityLabel={t("pcKpiA11y", { label, amount: money(v) })}
          >
            <Text style={s.meta}>{label}</Text>
            <Text style={s.value}>{money(v)}</Text>
          </Card>
        ))}
      </View>
      {totals.estimated > 0 ? <Text style={s.meta}>{t("pcEstimatedNote", { amount: money(totals.estimated) })}</Text> : null}
      {compare ? (
        <View style={s.compare} accessibilityLabel={t("pcCompare", { amount: money(compare.previous), change: compare.change === null ? "—" : `${compare.change > 0 ? "+" : ""}${compare.change} %` })}>
          {compare.change !== null && compare.change < 0 ? <TrendingDown size={16} color={colors.dangerText} /> : <TrendingUp size={16} color={colors.successText} />}
          <Text style={s.meta}>
            {t("pcCompare", { amount: money(compare.previous), change: compare.change === null ? "—" : `${compare.change > 0 ? "+" : ""}${compare.change} %` })}
          </Text>
        </View>
      ) : null}
      <FilterToolbar
        filters={f}
        sections={sections}
        count={(v) => runCommissions(rows, v, f.query).length}
        placeholder={t("pcSearch")}
        subtitle={t("pcFiltersSubtitle")}
        quick={quick}
      />
      <Text style={s.meta} accessibilityLiveRegion="polite">
        {t("pcShowing", { n: shown.length, total: rows.length, basis: t(`pcBasis_${basis}` as const) })}
        {shown.length ? ` · ${t("pcShownTotal", { amount: money(shownTotals.total + shownTotals.estimated) })}` : ""}
      </Text>
      {shown.length === 0 ? (
        <EmptyState title={t("pcNoMatch")} message={t("fltNoMatchesBody")} action={t("fltClearAll")} onPress={f.clear} />
      ) : (
        <Card>
          {shown.map((r) => (
            <FlowRow
              key={r.id}
              icon={CircleDollarSign}
              title={`${money(r.amount_minor)} · ${r.policy_number ?? r.reason ?? t("agCommissionAdjustment")}`}
              subtitle={[
                r.customer_name,
                r.carrier_name,
                r.producer_name,
                shortDate(basisDate(r, basis)),
                owedAmount(r) > 0 && (r.paid_minor ?? 0) > 0 ? t("pcOutstanding", { amount: money(owedAmount(r)) }) : null,
              ]
                .filter(Boolean)
                .join(" · ")}
              status={`${stageLabel(r)} · ${statusLabel(r.status)}`}
              onPress={onOpen ? () => onOpen(r) : undefined}
            />
          ))}
        </Card>
      )}
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { gap: 12 },
  metric: { minHeight: 88 },
  metricOn: { borderColor: colors.blue700, borderWidth: 2 },
  meta: { ...type.meta, color: colors.neutral600 },
  value: { ...type.sectionTitle, color: colors.navy950 },
  compare: { flexDirection: "row", alignItems: "center", gap: 6 },
});

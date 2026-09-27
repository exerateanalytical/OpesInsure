import React, { useMemo, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { CircleDollarSign, SlidersHorizontal } from "lucide-react-native";
import { Button, Card, Chip, ChipRow, TextField } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { useColumns } from "@/components/responsive";
import { activeFilterCount, FiltersSheet, type FilterSection, type FilterValues } from "@/components/customer/FiltersSheet";
import { humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";
import {
  applyCommissionFilter,
  basisDate,
  commissionTotals,
  COMMISSION_STATES,
  CommissionFilter,
  CommissionRow,
  DateBasis,
  defaultCommissionFilter,
  distinct,
  Lifecycle,
  Period,
} from "./commissionFilters";

const PERIODS: Period[] = ["all", "today", "week", "month", "prev_month", "quarter", "year", "custom"];
const BASES: DateBasis[] = ["accrual", "sale", "issue", "payment"];

/**
 * Filterable commission ledger shared by the agent wallet and the broker
 * commissions screen: clickable KPI cards that drill into the exact rows
 * they sum (COM-006), lifecycle shortcuts (COM-004), period + date basis
 * (COM-005) and an insurer / product / producer sheet (COM-001/002/003).
 * `producers` must only be enabled for a broker admin; rows arrive already
 * scoped by the server.
 */
export function CommissionLedger({
  rows,
  onOpen,
  producers = false,
}: {
  rows: CommissionRow[];
  onOpen?: (row: CommissionRow) => void;
  producers?: boolean;
}) {
  const { t, td } = useTranslation();
  const grid = useColumns();
  const [f, setF] = useState<CommissionFilter>(defaultCommissionFilter);
  const [sheet, setSheet] = useState(false);
  const shown = useMemo(() => applyCommissionFilter(rows, f), [rows, f]);
  const totals = commissionTotals(shown);
  const statusLabel = (s: string) => td(`commissionStatus_${s}`, humanize(s));

  const sections: FilterSection[] = [
    {
      key: "statuses",
      title: t("pcStatus"),
      options: [...new Set([...COMMISSION_STATES.filter((s) => rows.some((r) => r.status === s)), ...rows.map((r) => r.status)])].map(
        (s) => ({ value: s, label: statusLabel(s) }),
      ),
    },
    { key: "carriers", title: t("pcInsurer"), options: distinct(rows, (r) => [r.carrier_id, r.carrier_name]) },
    {
      key: "lines",
      title: t("pcProduct"),
      options: distinct(rows, (r) => [r.line_code, r.line_code ? td(`line_${r.line_code}`, humanize(r.line_code)) : null]),
    },
    ...(producers ? [{ key: "producers", title: t("pcProducer"), options: distinct(rows, (r) => [r.producer_id, r.producer_name]) }] : []),
    {
      key: "basis",
      title: t("pcDateBasis"),
      single: true,
      options: BASES.map((b) => ({ value: b, label: t(`pcBasis_${b}` as const) })),
    },
  ].filter((s) => s.options.length > 0);
  const toValues = (x: CommissionFilter): FilterValues => ({
    statuses: x.statuses,
    carriers: x.carriers,
    lines: x.lines,
    producers: x.producers,
    basis: [x.basis],
  });
  const fromValues = (v: FilterValues): CommissionFilter => ({
    ...f,
    statuses: v.statuses ?? [],
    carriers: v.carriers ?? [],
    lines: v.lines ?? [],
    producers: producers ? (v.producers ?? []) : [],
    basis: ((v.basis ?? [])[0] as DateBasis) ?? "accrual",
  });
  const extra = activeFilterCount(toValues(f), sections);

  /** KPI → the exact filter it sums (COM-006). */
  const kpis: [string, number, Lifecycle][] = [
    [t("pcEarned"), totals.total, "all"],
    [t("pcUnpaid"), totals.unpaid, "unpaid"],
    [t("pcAvailable"), totals.available, "AVAILABLE"],
    [t("pcPaid"), totals.paid, "paid"],
  ];

  return (
    <View>
      <View style={grid.row}>
        {kpis.map(([label, v, l]) => (
          <Card
            key={label}
            style={[s.metric, grid.item, f.lifecycle === l && s.metricOn]}
            onPress={() => setF({ ...f, lifecycle: l })}
            accessibilityLabel={t("pcKpiA11y", { label, amount: money(v) })}
          >
            <Text style={s.meta}>{label}</Text>
            <Text style={s.value}>{money(v)}</Text>
          </Card>
        ))}
      </View>
      <ChipRow exclusive>
        {(["all", "unpaid", "paid"] as const).map((l) => (
          <Chip key={l} role="tab" label={t(`pcLife_${l}`)} selected={f.lifecycle === l} onPress={() => setF({ ...f, lifecycle: l })} />
        ))}
        {!["all", "unpaid", "paid"].includes(f.lifecycle) ? (
          <Chip role="tab" label={statusLabel(f.lifecycle)} selected onPress={() => setF({ ...f, lifecycle: "all" })} />
        ) : null}
      </ChipRow>
      <ChipRow exclusive>
        {PERIODS.map((p) => (
          <Chip key={p} role="tab" label={t(`pcPeriod_${p}`)} selected={f.period === p} onPress={() => setF({ ...f, period: p })} />
        ))}
      </ChipRow>
      {f.period === "custom" ? (
        <View style={grid.row}>
          <View style={grid.item}>
            <TextField label={t("pcFrom")} placeholder="2026-09-01" value={f.from ?? ""} onChangeText={(v) => setF({ ...f, from: v.trim() })} />
          </View>
          <View style={grid.item}>
            <TextField label={t("pcTo")} placeholder="2026-09-30" value={f.to ?? ""} onChangeText={(v) => setF({ ...f, to: v.trim() })} />
          </View>
        </View>
      ) : null}
      <Button
        variant="secondary"
        size="small"
        icon={SlidersHorizontal}
        label={extra ? t("pcFiltersN", { n: extra }) : t("pcFilters")}
        onPress={() => setSheet(true)}
      />
      <Text style={s.meta} accessibilityLiveRegion="polite">
        {t("pcShowing", { n: shown.length, total: rows.length, basis: t(`pcBasis_${f.basis}` as const) })}
      </Text>
      <Card>
        {shown.length === 0 ? <Text style={s.meta}>{t("pcNoMatch")}</Text> : null}
        {shown.map((r) => (
          <FlowRow
            key={r.id}
            icon={CircleDollarSign}
            title={`${money(r.amount_minor)} · ${r.policy_number ?? r.reason ?? t("agCommissionAdjustment")}`}
            subtitle={[r.customer_name, r.carrier_name, r.producer_name, shortDate(basisDate(r, f.basis))].filter(Boolean).join(" · ")}
            status={statusLabel(r.status)}
            onPress={onOpen ? () => onOpen(r) : undefined}
          />
        ))}
      </Card>
      <FiltersSheet
        visible={sheet}
        onClose={() => setSheet(false)}
        sections={sections}
        value={toValues(f)}
        onApply={(v) => setF(fromValues(v))}
        count={(v) => applyCommissionFilter(rows, fromValues(v)).length}
        subtitle={t("pcFiltersSubtitle")}
      />
    </View>
  );
}

const s = StyleSheet.create({
  metric: { minHeight: 88 },
  metricOn: { borderColor: colors.blue700, borderWidth: 2 },
  meta: { ...type.meta, color: colors.neutral600 },
  value: { ...type.sectionTitle, color: colors.navy950 },
});

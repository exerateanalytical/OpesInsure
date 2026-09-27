/**
 * Commercial Agent Earnings building blocks (spec v2 screens 05-08) on the
 * shared agent kit and src/theme/agent.ts tokens: commission / withdrawal
 * rows, key-value rows, the status timeline and the Earnings filter bottom
 * sheet. Logic lives in agentEarnings.ts + commissionFilters.ts.
 */
import React, { useEffect, useMemo, useState } from "react";
import { Modal, Pressable, ScrollView, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { Check, ChevronRight, X } from "lucide-react-native";
import { AgentButton, AgentStatusChip } from "@/components/agent";
import type { AgentStatusKey } from "@/components/agent";
import { SelectField } from "@/components/forms/SelectField";
import { DateField } from "@/components/purchase/PurchaseUi";
import { customPeriod, PERIOD_PRESETS, type FilterValues } from "@/components/filters/core";
import { humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { agentColors as c, agentIcon, agentLayout as L, agentType as T } from "@/theme/agent";
import {
  COMMISSION_VOCAB,
  commissionVocab,
  maskPhone,
  optionsOf,
  paymentState,
  providerLabel,
  runEarnings,
  withdrawalVocab,
  type CommissionVocab,
  type EarningsRow,
  type WithdrawalVocab,
} from "./agentEarnings";

// ------------------------------------------------------------ vocabulary chips

const C_WORD: Record<CommissionVocab, AgentStatusKey> = {
  accrued: "Accrued",
  pending: "Pending",
  available: "Available",
  paid: "Paid",
  reversed: "Reversed",
  disputed: "Disputed",
};
const W_WORD: Record<WithdrawalVocab, AgentStatusKey> = {
  requested: "Requested",
  under_review: "Under Review",
  processing: "Processing",
  paid: "Paid",
  failed: "Failed",
  rejected: "Rejected",
  reversed: "Reversed",
  cancelled: "Cancelled",
};
export const commissionWord = (v: CommissionVocab) => C_WORD[v];
export const withdrawalWord = (v: WithdrawalVocab) => W_WORD[v];

export function CommissionChip({ row }: { row: EarningsRow }) {
  const v = commissionVocab(row);
  return <AgentStatusChip status={C_WORD[v]} tone={v === "available" ? "info" : v === "accrued" ? "neutral" : undefined} />;
}
export function WithdrawalChip({ status }: { status: string }) {
  return <AgentStatusChip status={W_WORD[withdrawalVocab(status)]} />;
}

// ------------------------------------------------------------ rows

/** Commission list row: amount strongest, status chip, customer, product · insurer, policy · date. */
export function CommissionItem({ row, first, onPress }: { row: EarningsRow; first?: boolean; onPress?: () => void }) {
  const { t, td } = useTranslation();
  const product = row.product_name ?? (row.line_code ? td(`line_${row.line_code}`, humanize(row.line_code)) : null);
  const date = row.accrued_at ?? row.issued_at ?? row.available_at ?? row.paid_at;
  const title = row.customer_name ?? row.reason ?? t("agCommissionAdjustment");
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={[money(row.amount_minor), title, product, row.carrier_name, row.policy_number].filter(Boolean).join(", ")}
      onPress={onPress}
      disabled={!onPress}
      style={({ pressed }) => [s.item, !first && s.divider, pressed && s.pressed]}
    >
      <View style={s.itemBody}>
        <View style={s.itemTop}>
          <Text style={s.amount} numberOfLines={1}>{money(row.amount_minor)}</Text>
          <CommissionChip row={row} />
        </View>
        <Text style={s.itemTitle} numberOfLines={1}>{title}</Text>
        {product || row.carrier_name ? <Text style={s.itemSub} numberOfLines={1}>{[product, row.carrier_name].filter(Boolean).join(" · ")}</Text> : null}
        <Text style={s.itemMeta} numberOfLines={1}>{[row.policy_number, date ? shortDate(date) : null].filter(Boolean).join(" · ") || "—"}</Text>
      </View>
      {onPress ? <ChevronRight size={agentIcon.small} color={c.muted} strokeWidth={agentIcon.stroke} /> : null}
    </Pressable>
  );
}

export type WithdrawalItemRow = { id: string; amount_minor: number; status: string; provider: string; destination_phone: string; requested_at: string };

/** Withdrawal history row: amount, status, method, masked number, date. */
export function WithdrawalItem({ row, first, onPress }: { row: WithdrawalItemRow; first?: boolean; onPress?: () => void }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={[money(row.amount_minor), providerLabel(row.provider), shortDate(row.requested_at)].join(", ")}
      onPress={onPress}
      disabled={!onPress}
      style={({ pressed }) => [s.item, !first && s.divider, pressed && s.pressed]}
    >
      <View style={s.itemBody}>
        <View style={s.itemTop}>
          <Text style={s.amount} numberOfLines={1}>{money(row.amount_minor)}</Text>
          <WithdrawalChip status={row.status} />
        </View>
        <Text style={s.itemSub} numberOfLines={1}>{`${providerLabel(row.provider)} · ${maskPhone(row.destination_phone)}`}</Text>
        <Text style={s.itemMeta}>{shortDate(row.requested_at)}</Text>
      </View>
      {onPress ? <ChevronRight size={agentIcon.small} color={c.muted} strokeWidth={agentIcon.stroke} /> : null}
    </Pressable>
  );
}

/** Label / value line inside an AgentCard. Missing values read "—". */
export function KV({ label, value, strong, first }: { label: string; value?: string | null; strong?: boolean; first?: boolean }) {
  return (
    <View style={[s.kv, !first && s.divider]}>
      <Text style={s.kvLabel}>{label}</Text>
      <Text style={[s.kvValue, strong && s.kvStrong]} selectable>{value || "—"}</Text>
    </View>
  );
}

/** Vertical status timeline. `state`: done | current | todo | failed. */
export function Timeline({ steps }: { steps: { label: string; date?: string | null; state: "done" | "current" | "todo" | "failed"; note?: string | null }[] }) {
  return (
    <View>
      {steps.map((st, i) => {
        const col = st.state === "failed" ? c.danger : st.state === "done" ? c.success : st.state === "current" ? c.gold : c.borderStrong;
        return (
          <View key={`${st.label}-${i}`} style={s.tlRow} accessibilityLabel={[st.label, st.date, st.note].filter(Boolean).join(", ")}>
            <View style={s.tlRail}>
              <View style={[s.tlDot, { borderColor: col, backgroundColor: st.state === "todo" ? c.surface : col }]}>
                {st.state === "done" ? <Check size={10} color={c.surface} strokeWidth={3} /> : st.state === "failed" ? <X size={10} color={c.surface} strokeWidth={3} /> : null}
              </View>
              {i < steps.length - 1 ? <View style={[s.tlLine, st.state === "done" && { backgroundColor: c.success }]} /> : null}
            </View>
            <View style={s.tlText}>
              <Text style={[s.tlLabel, st.state === "todo" && { color: c.muted }]}>{st.label}</Text>
              {st.date ? <Text style={s.itemMeta}>{st.date}</Text> : null}
              {st.note ? <Text style={[s.itemSub, st.state === "failed" && { color: c.danger }]}>{st.note}</Text> : null}
            </View>
          </View>
        );
      })}
    </View>
  );
}

// ------------------------------------------------------------ filter sheet

const PAY_STATES = ["unpaid", "partial", "paid"] as const;

/** Empty Earnings selection. */
export const EMPTY_EARNINGS: FilterValues = {};
/** Active filter count (period "any"/unset and sort are not counted). */
export const earningsFilterCount = (v: FilterValues) =>
  Object.entries(v).reduce((n, [k, xs]) => (k === "sort" || k === "basis" ? n : k === "period" ? n + (xs[0] && xs[0] !== "any" ? 1 : 0) : n + xs.length), 0);

/**
 * Earnings Filters (screen 06): bottom sheet with bottom-sheet selectors,
 * status checkboxes, Reset and a live "Show N Commissions". Options come
 * only from the loaded rows; empty dimensions are hidden.
 */
export function EarningsFiltersSheet({
  visible,
  onClose,
  rows,
  value,
  onApply,
  text,
}: {
  visible: boolean;
  onClose: () => void;
  rows: EarningsRow[];
  value: FilterValues;
  onApply: (v: FilterValues) => void;
  text: string;
}) {
  const { t, td } = useTranslation();
  const insets = useSafeAreaInsets();
  const { height } = useWindowDimensions();
  const [draft, setDraft] = useState<FilterValues>(value);
  useEffect(() => {
    if (visible) setDraft(value);
    // Draft resets only when the sheet opens (callers pass a fresh object each render).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [visible]);
  const n = useMemo(() => runEarnings(rows, draft, text).length, [rows, draft, text]);
  const all = { value: "", label: t("ernAll") };
  const set = (key: string, v: string) => setDraft((d) => ({ ...d, [key]: v ? [v] : [] }));
  const toggle = (key: string, v: string) =>
    setDraft((d) => {
      const cur = d[key] ?? [];
      return { ...d, [key]: cur.includes(v) ? cur.filter((x) => x !== v) : [...cur, v] };
    });

  const dims = useMemo(
    () =>
      [
        { key: "carriers", label: t("ernCompany"), options: optionsOf(rows, (r) => [r.carrier_id, r.carrier_name]) },
        { key: "products", label: t("ernProduct"), options: optionsOf(rows, (r) => [r.product_name, r.product_name]) },
        { key: "lines", label: t("ernPolicyType"), options: optionsOf(rows, (r) => [r.line_code, r.line_code ? td(`line_${r.line_code}`, humanize(r.line_code)) : null]) },
        { key: "customers", label: t("ernCustomer"), options: optionsOf(rows, (r) => [r.customer_id ?? r.customer_name, r.customer_name]) },
        // Hidden for a single agent: only when the server sends a producer dimension.
        { key: "producers", label: t("ernSeller"), options: optionsOf(rows, (r) => [r.producer_id, r.producer_name]) },
      ].filter((d) => d.options.length > 0),
    [rows, t, td],
  );
  const later = useMemo(
    () =>
      [
        { key: "policy_status", label: t("ernPolicyStatus"), options: optionsOf(rows, (r) => [r.policy_status, r.policy_status ? td(`policyStatus_${r.policy_status}`, humanize(r.policy_status)) : null]) },
        { key: "branches", label: t("ernBranch"), options: optionsOf(rows, (r) => [r.branch_id, r.branch_name]) },
      ].filter((d) => d.options.length > 0),
    [rows, t, td],
  );
  const payStates = PAY_STATES.filter((p) => rows.some((r) => paymentState(r) === p));
  const period = draft.period?.[0] ?? "any";
  const custom = period.startsWith("custom:");
  const [from = "", to = ""] = custom ? period.slice(7).split("..") : [];
  const periodOptions = [...PERIOD_PRESETS.map((p) => ({ value: p, label: t(`fltPeriod_${p}` as CopyKey) })), { value: "custom", label: t("fltPeriod_custom") }];

  const check = (key: string, v: string, label: string) => {
    const on = (draft[key] ?? []).includes(v);
    return (
      <Pressable key={v} accessibilityRole="checkbox" accessibilityState={{ checked: on }} accessibilityLabel={label} onPress={() => toggle(key, v)} style={({ pressed }) => [s.check, pressed && s.pressed]}>
        <View style={[s.box, on && s.boxOn]}>{on ? <Check size={14} color={c.surface} strokeWidth={3} /> : null}</View>
        <Text style={s.checkText}>{label}</Text>
      </Pressable>
    );
  };

  return (
    <Modal visible={visible} transparent animationType="slide" statusBarTranslucent onRequestClose={onClose}>
      <View style={s.sheetRoot}>
        <Pressable style={s.backdrop} onPress={onClose} accessibilityRole="button" accessibilityLabel={t("mdClose")} />
        <View style={[s.sheet, { maxHeight: Math.round(height * 0.92) }]} accessibilityViewIsModal>
          <View style={s.handle} />
          <View style={s.sheetHead}>
            <Text accessibilityRole="header" style={s.sheetTitle}>{t("ernFilters")}</Text>
            <Pressable accessibilityRole="button" accessibilityLabel={t("mdClose")} onPress={onClose} hitSlop={8} style={s.close}>
              <X size={agentIcon.action} color={c.navy} strokeWidth={agentIcon.stroke} />
            </Pressable>
          </View>
          <ScrollView style={s.sheetScroll} contentContainerStyle={s.sheetBody} keyboardShouldPersistTaps="handled">
            <SelectField
              label={t("ernDateRange")}
              value={custom ? "custom" : period}
              options={periodOptions}
              onChange={(v) => set("period", v === "custom" ? customPeriod("", "") : v)}
            />
            {custom ? (
              <>
                <DateField label={t("ernFrom")} value={from || undefined} onChange={(v) => set("period", customPeriod(v, to))} minYear={2020} />
                <DateField label={t("ernTo")} value={to || undefined} onChange={(v) => set("period", customPeriod(from, v))} minYear={2020} />
              </>
            ) : null}
            {dims.map((d) => (
              <SelectField key={d.key} label={d.label} value={draft[d.key]?.[0] ?? ""} options={[all, ...d.options]} onChange={(v) => set(d.key, v)} />
            ))}
            <View style={s.group}>
              <Text style={s.groupTitle}>{t("ernCommissionStatus")}</Text>
              {COMMISSION_VOCAB.map((v) => check("vocab", v, td(`agentSt_${commissionWord(v)}`, commissionWord(v))))}
            </View>
            {payStates.length ? (
              <View style={s.group}>
                <Text style={s.groupTitle}>{t("ernPaymentStatus")}</Text>
                {payStates.map((p) => check("payment", p, t(`ernPay_${p}` as CopyKey)))}
              </View>
            ) : null}
            {later.map((d) => (
              <SelectField key={d.key} label={d.label} value={draft[d.key]?.[0] ?? ""} options={[all, ...d.options]} onChange={(v) => set(d.key, v)} />
            ))}
          </ScrollView>
          <View style={[s.sheetBar, { paddingBottom: Math.max(insets.bottom, L.minSafeArea) }]}>
            <AgentButton variant="secondary" label={t("ernReset")} onPress={() => setDraft({ ...EMPTY_EARNINGS, sort: draft.sort ?? [] })} style={s.reset} />
            <AgentButton
              label={n === 1 ? t("ernShowOne") : t("ernShowN", { n })}
              onPress={() => {
                onApply(draft);
                onClose();
              }}
              style={s.apply}
            />
          </View>
        </View>
      </View>
    </Modal>
  );
}

const s = StyleSheet.create({
  pressed: { opacity: 0.85 },
  divider: { borderTopWidth: 1, borderTopColor: c.border },
  item: { minHeight: L.rowMinHeight, flexDirection: "row", alignItems: "center", gap: 12, paddingHorizontal: L.cardPadding, paddingVertical: 12 },
  itemBody: { flex: 1, gap: 2, minWidth: 0 },
  itemTop: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: L.grid },
  amount: { ...T.cardTitle, fontFamily: "Inter_700Bold", fontSize: 16, color: c.heading, flexShrink: 1 },
  itemTitle: { ...T.body, color: c.text },
  itemSub: { ...T.secondary, color: c.secondary },
  itemMeta: { ...T.caption, color: c.muted },
  kv: { flexDirection: "row", justifyContent: "space-between", alignItems: "flex-start", gap: 12, paddingVertical: 12 },
  kvLabel: { ...T.secondary, color: c.secondary, flexShrink: 1 },
  kvValue: { ...T.body, color: c.text, textAlign: "right", flexShrink: 1 },
  kvStrong: { fontFamily: "Inter_700Bold", color: c.heading },
  tlRow: { flexDirection: "row", gap: 12 },
  tlRail: { width: 18, alignItems: "center" },
  tlDot: { width: 18, height: 18, borderRadius: 9, borderWidth: 2, alignItems: "center", justifyContent: "center" },
  tlLine: { width: 2, flex: 1, minHeight: 18, backgroundColor: c.border, marginVertical: 2 },
  tlText: { flex: 1, paddingBottom: 16, gap: 2 },
  tlLabel: { ...T.body, fontFamily: "Inter_600SemiBold", color: c.text },
  sheetRoot: { flex: 1, justifyContent: "flex-end" },
  backdrop: { ...StyleSheet.absoluteFillObject, backgroundColor: "rgba(6,52,84,0.45)" },
  sheet: { backgroundColor: c.page, borderTopLeftRadius: 24, borderTopRightRadius: 24, width: "100%", maxWidth: 560, alignSelf: "center" },
  handle: { width: 40, height: 4, borderRadius: 2, backgroundColor: c.borderStrong, alignSelf: "center", marginTop: 8 },
  sheetHead: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", paddingHorizontal: L.screenPadding, paddingTop: 8 },
  sheetTitle: { ...T.sectionTitle, color: c.heading },
  close: { width: L.touchTarget, height: L.touchTarget, alignItems: "center", justifyContent: "center" },
  sheetScroll: { flexGrow: 0 },
  sheetBody: { paddingHorizontal: L.screenPadding, paddingBottom: L.sectionGap, gap: L.subsectionGap },
  group: { backgroundColor: c.surface, borderWidth: 1, borderColor: c.border, borderRadius: L.cardRadius, paddingHorizontal: L.cardPadding, paddingVertical: 8 },
  groupTitle: { ...T.cardTitle, color: c.heading, paddingVertical: 8 },
  check: { minHeight: L.touchTarget, flexDirection: "row", alignItems: "center", gap: 12 },
  box: { width: 22, height: 22, borderRadius: 6, borderWidth: 1.5, borderColor: c.borderStrong, backgroundColor: c.surface, alignItems: "center", justifyContent: "center" },
  boxOn: { backgroundColor: c.actionBlue, borderColor: c.actionBlue },
  checkText: { ...T.body, color: c.text, flex: 1 },
  sheetBar: { flexDirection: "row", gap: 12, paddingHorizontal: L.screenPadding, paddingTop: 12, borderTopWidth: 1, borderTopColor: c.border, backgroundColor: c.surface },
  reset: { flex: 1 },
  apply: { flex: 2 },
});

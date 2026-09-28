import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { ChevronRight, type LucideIcon } from "lucide-react-native";
import { Card, ripple } from "@/components/ui";
import { InstitutionMark } from "@/components/InstitutionMark";
import { OperationsList } from "@/components/OperationsList";
import { EmptyState } from "@/components/StatePanel";
import { AgentCard, AgentEmptyState } from "@/components/agent";
import { AgentListRow } from "@/components/partner/AgentListUi";
import { SearchX } from "lucide-react-native";
import { agentColors as ac, agentType as aT } from "@/theme/agent";
import { FilterToolbar, runList, totals, useListFilters, type FilterSection, type FilterValues, type Matchers, type Sorters } from "@/components/filters";
import { money } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { usePartnerLook } from "@/hooks/usePartnerLook";
import { colors, space, type } from "@/theme/tokens";

/** Initials for an insurer without a logo ("SanlamAllianz Cameroun" -> "SC"). */
export const initialsOf = (name?: string | null) =>
  (name ?? "")
    .split(/\s+/)
    .filter((w) => /^[A-Za-zÀ-ÿ]/.test(w))
    .slice(0, 2)
    .map((w) => w[0]!.toUpperCase())
    .join("") || "?";

/** List row with the insurer's InstitutionMark instead of an icon (same layout as FlowRow). */
function MarkRow({ title, subtitle, status, mark, onPress }: Pick<Row, "title" | "subtitle" | "status"> & { mark: { logoUrl?: string | null; name?: string | null }; onPress?: () => void }) {
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={[title, subtitle, status].filter(Boolean).join(", ")} onPress={onPress} android_ripple={ripple()} style={({ pressed }) => [st.row, pressed && { opacity: 0.85 }]}>
      <InstitutionMark logoUrl={mark.logoUrl} initials={initialsOf(mark.name)} />
      <View style={st.copy}>
        <Text style={st.title}>{title}</Text>
        {subtitle ? <Text style={st.sub}>{subtitle}</Text> : null}
        {status ? <Text style={st.status}>{status.replaceAll("_", " ")}</Text> : null}
      </View>
      <View accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
        <ChevronRight size={20} color={colors.neutral400} />
      </View>
    </Pressable>
  );
}

const st = StyleSheet.create({
  row: { minHeight: 78, flexDirection: "row", alignItems: "center", gap: space.x3, borderBottomWidth: 1, borderBottomColor: colors.neutral100 },
  copy: { flex: 1, gap: 2, paddingVertical: space.x2 },
  title: { ...type.label, color: colors.navy950 },
  sub: { ...type.meta, color: colors.neutral600 },
  status: { ...type.meta, color: colors.blue700 },
});

type Row = {
  id: string;
  title: string;
  subtitle: string;
  status: string;
  /** Agent variant: raw server status code (chip tone / locked vocabulary). */
  statusCode?: string | null;
  /** Agent variant: amount shown strongest on the right. */
  amount?: string | null;
};

/**
 * App-wide list standard (customer, agent, broker, carrier): search + filter sheet + active pills + clear all +
 * saved / recent filters + sort (shared framework), result count, a money total
 * over exactly the rows shown, an empty-match state with "Clear all", and a
 * drill-down on every row. Rows arrive server-scoped; filtering is local.
 */
export function FilteredList<T extends { id: string }>({
  list,
  rows,
  sections,
  matchers,
  haystack,
  sorters,
  icon,
  render,
  onPress,
  amount,
  totalLabel,
  placeholder,
  initial,
  mark,
  action,
  variant = "default",
}: {
  /** Filter memory key, e.g. "broker.renewals". */
  list: string;
  rows: T[];
  sections: FilterSection[];
  matchers: Matchers<T>;
  haystack: (r: T) => readonly unknown[];
  sorters?: Sorters<T>;
  icon: LucideIcon;
  render: (r: T) => Omit<Row, "id">;
  onPress?: (r: T) => void;
  /** Amount summed into the total line (minor units). */
  amount?: (r: T) => number | null | undefined;
  /** "Total premium shown: {amount}" style label key output. */
  totalLabel?: (amount: string, count: number) => string;
  placeholder?: string;
  initial?: { values: FilterValues; text?: string };
  /** Insurer logo per row (InstitutionMark) instead of the list icon. */
  mark?: (r: T) => { logoUrl?: string | null; name?: string | null } | null;
  /** Primary list action rendered above the toolbar (e.g. "Report a claim"). */
  action?: React.ReactNode;
  /** "agent" = Commercial Agent spec v2 rows (AgentCard, icon tiles, agent chips). Partner routes (/broker, /carrier) get it automatically. */
  variant?: "default" | "agent";
}) {
  const { t } = useTranslation();
  const f = useListFilters(list, sections, initial);
  const run = (v: FilterValues) => runList(rows, { values: v, text: f.query, matchers, haystack, sorters });
  const shown = run(f.values);
  const sum = amount ? totals(shown, amount) : null;
  // Broker / insurer lists take the agent rows too (insurer-logo rows keep MarkRow).
  const partner = usePartnerLook();
  if (variant === "agent" || (partner && !mark)) {
    return (
      <>
        {action}
        <FilterToolbar filters={f} sections={sections} count={(v) => run(v).length} resultCount={shown.length} placeholder={placeholder} />
        {sum && shown.length ? (
          <Text accessibilityLiveRegion="polite" style={{ ...aT.secondary, color: ac.secondary }}>
            {totalLabel ? totalLabel(money(sum.total), sum.count) : `${t("fltTotalFiltered", { count: sum.count })}: ${money(sum.total)}`}
          </Text>
        ) : null}
        {shown.length === 0 ? (
          <AgentEmptyState icon={SearchX} title={t("fltNoMatches")} body={t("fltNoMatchesBody")} actionLabel={t("fltClearAll")} onAction={f.clear} />
        ) : (
          <AgentCard padded={false}>
            {shown.map((r, i) => {
              const row = render(r);
              return (
                <AgentListRow
                  key={r.id}
                  first={i === 0}
                  icon={icon}
                  title={row.title}
                  subtitle={row.subtitle}
                  status={row.statusCode ?? row.status}
                  statusLabel={row.status}
                  amount={row.amount}
                  onPress={onPress ? () => onPress(r) : undefined}
                />
              );
            })}
          </AgentCard>
        )}
      </>
    );
  }
  return (
    <>
      {action}
      <FilterToolbar filters={f} sections={sections} count={(v) => run(v).length} resultCount={shown.length} placeholder={placeholder} />
      {sum && shown.length ? (
        <Text accessibilityLiveRegion="polite" style={{ ...type.meta, color: colors.neutral600 }}>
          {totalLabel ? totalLabel(money(sum.total), sum.count) : `${t("fltTotalFiltered", { count: sum.count })}: ${money(sum.total)}`}
        </Text>
      ) : null}
      {shown.length === 0 ? (
        <EmptyState title={t("fltNoMatches")} message={t("fltNoMatchesBody")} action={t("fltClearAll")} onPress={f.clear} />
      ) : mark ? (
        <Card>
          {shown.map((r) => {
            const m = mark(r);
            const { statusCode: _c, amount: _a, ...row } = render(r);
            return m ? (
              <MarkRow key={r.id} {...row} mark={m} onPress={onPress ? () => onPress(r) : undefined} />
            ) : (
              <OperationsList key={r.id} icon={icon} rows={[{ id: r.id, ...row }]} onPress={onPress ? () => onPress(r) : undefined} />
            );
          })}
        </Card>
      ) : (
        <OperationsList
          icon={icon}
          onPress={onPress ? (id) => {
            const r = shown.find((x) => x.id === id);
            if (r) onPress(r);
          } : undefined}
          rows={shown.map((r) => {
            const { statusCode: _c, amount: _a, ...row } = render(r);
            return { id: r.id, ...row };
          })}
        />
      )}
    </>
  );
}

import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { AlertTriangle, ChevronRight } from "lucide-react-native";
import { Card } from "@/components/ui";
import { useColumns } from "@/components/responsive";
import { colors, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
import type { CopyKey as TranslationKey } from "@/i18n/strings";
import { useCapabilities } from "@/store/capabilities";
import { hrefVisible } from "@/lib/capabilities";
import { agentColors as ac, agentIcon, agentLayout as AL, agentType as AT } from "@/theme/agent";

export type DashboardMetric = { label: string; value: string; tone?: string; key?: string; href?: string };

/** Server label (stable English string) -> localized label + the queue it drills into. */
export type KpiRoute = { label: TranslationKey; href: string };

const URGENT = new Set(["warning", "danger"]);

/**
 * DASH-002/003: partner dashboard KPIs. Each known metric is a tappable card
 * that opens its work queue; metrics the server marks warning/danger are
 * lifted into a "Needs attention" row above the rest and carry an icon +
 * text, never colour alone. Unknown metrics still render (read-only) so a
 * new server metric is never hidden. Access to each queue is still enforced
 * server-side; this only links.
 */
export function KpiGrid({
  metrics,
  routes,
  variant = "default",
}: {
  metrics: DashboardMetric[];
  routes: Record<string, KpiRoute>;
  /** "agent" = Commercial Agent spec v2 look (src/theme/agent.ts); broker/carrier keep the default. */
  variant?: "default" | "agent";
}) {
  const ag = variant === "agent";
  const { t } = useTranslation();
  const grid = useColumns({ minItem: 150 });
  const caps = useCapabilities((s) => s.caps);
  const urgent = metrics.filter((m) => URGENT.has(m.tone ?? "") && m.value !== "0");
  const rest = metrics.filter((m) => !urgent.includes(m));

  const card = (m: DashboardMetric) => {
    const route = routes[m.key ?? m.label];
    // The metric stays visible; only the drill-in link is dropped for a module the server reports as not viewable.
    const rawHref = m.href ?? route?.href;
    const href = rawHref && hrefVisible(caps, rawHref) ? rawHref : undefined;
    const label = route ? t(route.label) : m.label;
    const isUrgent = urgent.includes(m);
    const body = (
      <>
        <View style={s.head}>
          {isUrgent ? <AlertTriangle size={16} color={ag ? ac.warning : colors.warningText} strokeWidth={ag ? agentIcon.stroke : undefined} /> : null}
          <Text style={[s.meta, ag && a.meta]}>{label}</Text>
        </View>
        <View style={s.valueRow}>
          <Text style={[s.value, ag && a.value]}>{m.value}</Text>
          {href ? <ChevronRight size={18} color={ag ? ac.muted : colors.neutral600} strokeWidth={ag ? agentIcon.stroke : undefined} /> : null}
        </View>
        {isUrgent ? <Text style={[s.flag, ag && a.flag]}>{t("kpiNeedsAttention")}</Text> : null}
      </>
    );
    const style = [s.metric, grid.item, ag && a.metric, isUrgent && (ag ? a.urgent : s.urgent)];
    return href ? (
      <Card
        key={m.label}
        style={style}
        onPress={() => router.push(href as never)}
        accessibilityLabel={`${label}: ${m.value}${isUrgent ? `, ${t("kpiNeedsAttention")}` : ""}`}
      >
        {body}
      </Card>
    ) : (
      <Card key={m.label} style={style}>
        {body}
      </Card>
    );
  };

  return (
    <View style={[s.wrap, ag && a.wrap]}>
      {urgent.length ? (
        <>
          <Text accessibilityRole="header" style={[s.section, ag && a.section]}>{ag ? t("kpiNeedsAttention").toUpperCase() : t("kpiNeedsAttention")}</Text>
          <View style={grid.row}>{urgent.map(card)}</View>
          <Text accessibilityRole="header" style={[s.section, ag && a.section]}>{ag ? t("kpiOverview").toUpperCase() : t("kpiOverview")}</Text>
        </>
      ) : null}
      <View style={grid.row}>{rest.map(card)}</View>
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { gap: space.x3 },
  section: { ...type.label, color: colors.navy950 },
  metric: { minHeight: 96, gap: space.x1 },
  urgent: { borderColor: colors.warningText, borderWidth: 1.5 },
  head: { flexDirection: "row", alignItems: "center", gap: 6 },
  meta: { ...type.meta, color: colors.neutral700, flexShrink: 1 },
  valueRow: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: space.x2 },
  value: { ...type.sectionTitle, color: colors.navy950, flexShrink: 1 },
  flag: { ...type.caption, color: colors.warningText },
});

/** variant="agent": white card, 1px #E9EAEB, radius 18, no shadow; amber only for "needs attention". */
const a = StyleSheet.create({
  wrap: { gap: 12 },
  metric: { backgroundColor: ac.surface, borderWidth: 1, borderColor: ac.border, borderRadius: AL.cardRadius, padding: 14, minHeight: 88, shadowOpacity: 0, elevation: 0 },
  urgent: { borderColor: ac.warning, backgroundColor: ac.warningBg },
  section: { ...AT.caption, color: ac.secondary, letterSpacing: 0.6 },
  meta: { ...AT.caption, color: ac.secondary },
  value: { ...AT.sectionTitle, fontSize: 20, lineHeight: 26, color: ac.heading, flexShrink: 1 },
  flag: { ...AT.caption, color: ac.warning },
});

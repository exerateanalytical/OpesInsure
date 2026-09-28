import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { AlertTriangle, BadgeCheck, LayoutDashboard, type LucideIcon } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { KpiGrid, type DashboardMetric, type KpiRoute } from "@/components/portal/KpiGrid";
import { useCapabilities } from "@/store/capabilities";
import { hrefVisible } from "@/lib/capabilities";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";
import { AgentShell, type PartnerPortal } from "./AgentShell";
import {
  AgentActionTiles,
  AgentCard,
  AgentCountBadge,
  AgentEmptyState,
  AgentIconBadge,
  AgentNavRow,
  AgentSection,
  AgentSkeleton,
  HeritageAccent,
} from "./primitives";

export type PartnerHomeLink = { title: string; subtitle: string; icon: LucideIcon; href: string };

const URGENT = new Set(["warning", "danger"]);
const numeric = (v: string) => /^\d[\d\s]*$/.test(v.trim());

/**
 * Broker / insurer Home on the Commercial Agent kit: hero, Needs attention (the server's
 * warning/danger metrics), Overview, Quick actions, Work queues, More. `allowed` narrows links
 * a workspace may not open (the server still authorizes every route).
 */
export function PartnerHome({
  portal,
  title,
  subtitle,
  load,
  kpiRoutes,
  quick,
  queues,
  more,
  allowed = () => true,
}: {
  portal: Exclude<PartnerPortal, "agent">;
  title: string;
  subtitle: string;
  load: () => Promise<{ metrics: DashboardMetric[] }>;
  kpiRoutes: Record<string, KpiRoute>;
  quick: PartnerHomeLink[];
  queues: PartnerHomeLink[];
  more: PartnerHomeLink[];
  allowed?: (href: string) => boolean;
}) {
  const { t } = useTranslation();
  const caps = useCapabilities((st) => st.caps);
  const q = useLoad(load, []);
  const ok = (href: string) => hrefVisible(caps, href) && allowed(href);
  const go = (href: string) => router.push(href as never);
  const routes = Object.fromEntries(Object.entries(kpiRoutes).filter(([, r]) => ok(r.href)));

  const metrics = q.data?.metrics ?? [];
  const attention = metrics.filter((m) => URGENT.has(m.tone ?? "") && m.value !== "0");
  const overview = metrics.filter((m) => !attention.includes(m));
  const rows = (links: PartnerHomeLink[]) => links.filter((l) => ok(l.href));

  return (
    <AgentShell portal={portal} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      <View style={s.hero}>
        <HeritageAccent variant="africa" size={190} opacity={0.08} style={s.heroArt} />
        <View style={s.goldRule} />
        <Text accessibilityRole="header" style={s.heroTitle} numberOfLines={2}>
          {title}
        </Text>
        <Text style={s.heroSub}>{subtitle}</Text>
      </View>

      {q.loading && !q.data ? (
        <AgentSkeleton rows={3} />
      ) : q.error && !q.data ? (
        <AgentEmptyState icon={LayoutDashboard} title={t("agHomeLoadError")} body={t("loadErrorBody")} actionLabel={t("agHomeRetry")} onAction={q.reload} />
      ) : (
        <>
          <AgentSection title={t("agHomeNeedsAttention")}>
            {attention.length === 0 ? (
              <AgentCard>
                <View style={s.clear}>
                  <AgentIconBadge icon={BadgeCheck} tone="success" />
                  <View style={s.clearText}>
                    <Text style={s.clearTitle}>{t("agHomeAllClear")}</Text>
                    <Text style={s.clearBody}>{t("agHomeAllClearBody")}</Text>
                  </View>
                </View>
              </AgentCard>
            ) : (
              <AgentCard padded={false}>
                {attention.map((m, i) => {
                  const route = kpiRoutes[m.key ?? m.label];
                  const href = m.href ?? route?.href;
                  const label = route ? t(route.label) : m.label;
                  const tone = m.tone === "danger" ? "danger" : "warning";
                  return (
                    <AgentNavRow
                      key={m.label}
                      icon={AlertTriangle}
                      iconTone={tone}
                      title={label}
                      subtitle={numeric(m.value) ? null : m.value}
                      right={numeric(m.value) ? <AgentCountBadge count={parseInt(m.value.replace(/\s/g, ""), 10)} tone={tone} /> : null}
                      accessibilityLabel={`${label}: ${m.value}`}
                      divider={i > 0}
                      chevron={!!href && ok(href)}
                      onPress={href && ok(href) ? () => go(href) : undefined}
                    />
                  );
                })}
              </AgentCard>
            )}
          </AgentSection>

          {overview.length ? (
            <AgentSection title={t("agHomeOverview")}>
              <KpiGrid variant="agent" metrics={overview} routes={routes} />
            </AgentSection>
          ) : metrics.length === 0 ? (
            <AgentEmptyState icon={LayoutDashboard} title={t("agNoActivity")} body={t("agNoActivityBody")} />
          ) : null}
        </>
      )}

      {rows(quick).length ? (
        <AgentSection title={t("agHomeQuickActions")}>
          <AgentActionTiles actions={rows(quick).map((a) => ({ key: a.href, title: a.title, subtitle: a.subtitle, icon: a.icon, onPress: () => go(a.href) }))} />
        </AgentSection>
      ) : null}

      {[
        { key: "queues", title: t("agHomeWorkQueues"), links: rows(queues) },
        { key: "more", title: t("ptHomeMore"), links: rows(more) },
      ]
        .filter((g) => g.links.length)
        .map((g) => (
          <AgentSection key={g.key} title={g.title}>
            <AgentCard padded={false}>
              {g.links.map((l, i) => (
                <AgentNavRow key={l.href} icon={l.icon} title={l.title} subtitle={l.subtitle} divider={i > 0} onPress={() => go(l.href)} />
              ))}
            </AgentCard>
          </AgentSection>
        ))}
    </AgentShell>
  );
}

const s = StyleSheet.create({
  hero: { backgroundColor: c.deepNavy, borderRadius: L.cardRadius, padding: 20, gap: 6, overflow: "hidden" },
  heroArt: { position: "absolute", right: -36, top: -24 },
  goldRule: { width: 28, height: 3, borderRadius: 2, backgroundColor: c.gold, marginBottom: 6 },
  heroTitle: { ...T.screenTitle, color: c.surface },
  heroSub: { ...T.secondary, color: c.lightBlue },
  clear: { flexDirection: "row", alignItems: "center", gap: 12 },
  clearText: { flex: 1, gap: 2 },
  clearTitle: { ...T.cardTitle, color: c.heading },
  clearBody: { ...T.secondary, color: c.secondary },
});

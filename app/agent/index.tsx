import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import {
  BadgeCheck,
  ClipboardList,
  FileSignature,
  FileWarning,
  LayoutDashboard,
  RefreshCw,
  ShieldAlert,
  ShieldCheck,
  Store,
  TrendingUp,
  UserPlus,
  UserRoundPlus,
  type LucideIcon,
} from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { KpiGrid, type KpiRoute } from "@/components/portal/KpiGrid";
import {
  AgentActionTiles,
  AgentCard,
  AgentCountBadge,
  AgentEmptyState,
  AgentIconBadge,
  AgentNavRow,
  AgentSection,
  AgentShell,
  AgentSkeleton,
  HeritageAccent,
} from "@/components/agent";
import { AgentApi } from "@/api/client";
import { AgentWorkspaceApi, shortDate } from "@/api/partner";
import { useSession } from "@/store/session";
import { useCapabilities } from "@/store/capabilities";
import { hrefVisible } from "@/lib/capabilities";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { OfflineQueueNudge } from "@/components/OfflineQueueNudge";
import {
  type Activity,
  type Attention,
  claimsNeedingInfo,
  kycActions,
  metricCount,
  needsAttention,
  openClaims,
  pipelineCounts,
  quoteFollowUps,
  recentActivity,
  returnedApplications,
} from "@/lib/agentHome";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";

/** Overview: the four book figures; each opens its queue (server still authorizes it). */
const KPI_ROUTES: Record<string, KpiRoute> = {
  Clients: { label: "kpiClients", href: "/agent/clients" },
  "Active policies": { label: "kpiActivePolicies", href: "/agent/policies" },
  "Commission available": { label: "kpiCommissionAvailable", href: "/agent/wallet" },
  "Commission pending": { label: "kpiCommissionPending", href: "/agent/wallet" },
};
/** Shown under Needs attention instead of Overview. */
const RENEWALS_METRIC = "Renewals due";

type Action = { label: CopyKey; subtitle: CopyKey; icon: LucideIcon; href: string };

const QUICK: Action[] = [
  // "Create quote" starts the assisted sale directly (real rating → client application → payment request).
  { label: "agQuickCreateQuote", subtitle: "agQuoteAndRequest", icon: FileSignature, href: "/agent/sales/new" },
  { label: "agAddLead", subtitle: "agProspectsToFollow", icon: UserPlus, href: "/agent/leads/new" },
  { label: "agRegisterClientTitle", subtitle: "agQuickRegisterSub", icon: UserRoundPlus, href: "/agent/clients/new" },
  { label: "catTitle", subtitle: "catMenuSubtitle", icon: Store, href: "/agent/catalogue" },
];

const ATTENTION: Record<Attention, { icon: LucideIcon; href: string; tone: "warning" | "danger" }> = {
  kyc: { icon: BadgeCheck, href: "/agent/onboarding", tone: "danger" },
  returned: { icon: FileWarning, href: "/agent/proposals", tone: "danger" },
  claims: { icon: ShieldAlert, href: "/agent/claims", tone: "warning" },
  renewals: { icon: RefreshCw, href: "/agent/renewals", tone: "warning" },
  quotes: { icon: ClipboardList, href: "/agent/quotes", tone: "warning" },
};

const ACTIVITY_ICON: Record<Activity["kind"], LucideIcon> = { lead: UserPlus, quote: FileSignature, proposal: ClipboardList, claim: ShieldAlert };
const ACTIVITY_STATUS: Record<Activity["kind"], string> = { lead: "leadStatus_", quote: "quoteStatus_", proposal: "proposalStatus_", claim: "claimStatus_" };

const partOfDay = (h = new Date().getHours()): CopyKey => (h < 12 ? "greetingMorning" : h < 18 ? "greetingAfternoon" : "greetingEvening");

/** Each list is optional: one failing source never blanks the rest of Home. */
const settle = <T,>(p: Promise<T>) => p.then((v) => v, () => null);

/**
 * Commercial Agent Home (field desk): Needs attention -> Overview -> Quick actions ->
 * Work queues -> Recent activity. Secondary account functions live behind the header avatar.
 */
export default function AgentHome() {
  const { t, td } = useTranslation();
  const caps = useCapabilities((st) => st.caps);
  const q = useLoad(() => AgentApi.dashboard(), []);
  const w = useLoad(
    async () => {
      const [profile, leads, quotes, proposals, claims] = await Promise.all([
        settle(AgentApi.profile()),
        settle(AgentWorkspaceApi.leads()),
        settle(AgentWorkspaceApi.quotes()),
        settle(AgentWorkspaceApi.proposals()),
        settle(AgentWorkspaceApi.claims()),
      ]);
      return { profile, leads, quotes, proposals, claims };
    },
    [],
  );
  const name = useSession((st) => st.bootstrap?.user.full_name) ?? "";
  const greeting = t("homeGreetingTime", { part: t(partOfDay()) }).replace(/,\s*$/, "");
  const firstName = name.trim().split(/\s+/)[0] ?? "";
  const go = (href: string) => router.push(href as never);
  const visible = (href: string) => hrefVisible(caps, href);

  const metrics = q.data?.metrics ?? [];
  const d = w.data;
  const attention = needsAttention({
    renewals: metricCount(metrics, RENEWALS_METRIC),
    kyc: kycActions(d?.profile),
    quotes: quoteFollowUps(d?.quotes),
    returned: returnedApplications(d?.proposals),
    claims: claimsNeedingInfo(d?.claims),
  }).filter((a) => visible(ATTENTION[a.key].href));
  const pipeline = pipelineCounts({ leads: d?.leads, quotes: d?.quotes, proposals: d?.proposals });
  const pipelineOpen = pipeline.leads + pipeline.quotes + pipeline.applications + pipeline.returned;
  const renewalsDue = metricCount(metrics, RENEWALS_METRIC);
  const claimsOpen = openClaims(d?.claims);
  const kycOpen = kycActions(d?.profile);
  const recent = recentActivity({ leads: d?.leads, quotes: d?.quotes, proposals: d?.proposals, claims: d?.claims });
  const loading = (q.loading && !q.data) || (w.loading && !w.data);

  const queues: { key: string; title: string; subtitle: string; icon: LucideIcon; href: string; count: number; tone: "warning" | "danger" | "neutral" }[] = [
    { key: "pipeline", title: t("agQueuePipeline"), subtitle: t("agQueuePipelineSub"), icon: TrendingUp, href: "/agent/pipeline", count: pipelineOpen, tone: pipeline.returned ? "danger" : "neutral" },
    { key: "renewals", title: t("notifPref_renewals"), subtitle: t("agPoliciesDueSoon"), icon: RefreshCw, href: "/agent/renewals", count: renewalsDue, tone: renewalsDue ? "warning" : "neutral" },
    { key: "claims", title: t("claims"), subtitle: t("ptClaimsSubtitle"), icon: ShieldAlert, href: "/agent/claims", count: claimsOpen, tone: claimsNeedingInfo(d?.claims) ? "warning" : "neutral" },
    { key: "verification", title: t("agVerification"), subtitle: t("agIdentityMandate"), icon: ShieldCheck, href: "/agent/onboarding", count: kycOpen, tone: kycOpen ? "danger" : "neutral" },
  ];

  const reload = () => {
    void q.reload();
    void w.reload();
  };

  return (
    <AgentShell refreshing={(q.loading && !!q.data) || (w.loading && !!w.data)} onRefresh={reload}>
      <View style={s.hero}>
        <HeritageAccent variant="africa" size={190} opacity={0.08} style={s.heroArt} />
        <View style={s.goldRule} />
        <Text style={s.heroLabel}>{firstName ? `${greeting}, ${firstName}` : greeting}</Text>
        <Text accessibilityRole="header" style={s.heroTitle} numberOfLines={2}>
          {t("agFieldDesk")}
        </Text>
        <Text style={s.heroSub}>{t("agFieldDeskSubtitle")}</Text>
      </View>

      <OfflineQueueNudge />

      {q.error && !q.data ? (
        <AgentEmptyState icon={LayoutDashboard} title={t("agHomeLoadError")} body={t("loadErrorBody")} actionLabel={t("agHomeRetry")} onAction={reload} />
      ) : null}

      <AgentSection title={t("agHomeNeedsAttention")}>
        {loading ? (
          <AgentSkeleton rows={2} />
        ) : attention.length === 0 ? (
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
            {attention.map((a, i) => {
              const cfg = ATTENTION[a.key];
              return (
                <AgentNavRow
                  key={a.key}
                  icon={cfg.icon}
                  title={t(`agAttn_${a.key}` as CopyKey)}
                  subtitle={t(`agAttn_${a.key}Sub` as CopyKey)}
                  iconTone={cfg.tone}
                  right={<AgentCountBadge count={a.count} tone={cfg.tone} />}
                  accessibilityLabel={`${t(`agAttn_${a.key}` as CopyKey)}: ${a.count}`}
                  divider={i > 0}
                  onPress={() => go(cfg.href)}
                />
              );
            })}
          </AgentCard>
        )}
      </AgentSection>

      <AgentSection title={t("agHomeOverview")}>
        {q.loading && !q.data ? (
          <AgentSkeleton rows={2} height={88} />
        ) : metrics.length === 0 ? (
          q.error ? null : <AgentEmptyState icon={LayoutDashboard} title={t("agNoActivity")} body={t("agNoActivityBody")} />
        ) : (
          <KpiGrid variant="agent" metrics={metrics.filter((m) => m.label !== RENEWALS_METRIC)} routes={KPI_ROUTES} />
        )}
      </AgentSection>

      <AgentSection title={t("agHomeQuickActions")}>
        <AgentActionTiles
          actions={QUICK.filter((a) => visible(a.href)).map((a) => ({ key: a.href, title: t(a.label), subtitle: t(a.subtitle), icon: a.icon, onPress: () => go(a.href) }))}
        />
      </AgentSection>

      <AgentSection title={t("agHomeWorkQueues")}>
        <AgentCard padded={false}>
          {queues
            .filter((x) => visible(x.href))
            .map((x, i) => (
              <AgentNavRow
                key={x.key}
                icon={x.icon}
                title={x.title}
                subtitle={x.subtitle}
                right={d || x.key === "renewals" ? <AgentCountBadge count={x.count} tone={x.tone} /> : null}
                divider={i > 0}
                onPress={() => go(x.href)}
              />
            ))}
        </AgentCard>
      </AgentSection>

      <AgentSection title={t("agHomeRecent")}>
        {w.loading && !w.data ? (
          <AgentSkeleton rows={3} />
        ) : recent.length === 0 ? (
          <AgentEmptyState icon={ClipboardList} title={t("agHomeNoRecent")} body={t("agHomeNoRecentBody")} />
        ) : (
          <AgentCard padded={false}>
            {recent.map((a, i) => (
              <AgentNavRow
                key={a.id}
                icon={ACTIVITY_ICON[a.kind]}
                title={a.title}
                subtitle={`${t(`agAct_${a.kind}` as CopyKey)} · ${td(`${ACTIVITY_STATUS[a.kind]}${a.status}`, a.status)} · ${shortDate(a.at)}`}
                divider={i > 0}
                onPress={() => go(a.href)}
              />
            ))}
          </AgentCard>
        )}
      </AgentSection>
    </AgentShell>
  );
}

const s = StyleSheet.create({
  hero: { backgroundColor: c.deepNavy, borderRadius: L.cardRadius, padding: 20, gap: 6, overflow: "hidden" },
  heroArt: { position: "absolute", right: -36, top: -24 },
  goldRule: { width: 28, height: 3, borderRadius: 2, backgroundColor: c.gold, marginBottom: 6 },
  heroLabel: { ...T.caption, color: c.lightBlue, letterSpacing: 0.3 },
  heroTitle: { ...T.screenTitle, color: c.surface },
  heroSub: { ...T.secondary, color: c.lightBlue },
  clear: { flexDirection: "row", alignItems: "center", gap: 12 },
  clearText: { flex: 1, gap: 2 },
  clearTitle: { ...T.cardTitle, color: c.heading },
  clearBody: { ...T.secondary, color: c.secondary },
});

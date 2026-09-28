import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import {
  CircleDollarSign,
  CircleUserRound,
  CloudUpload,
  ContactRound,
  FileSignature,
  FileText,
  LayoutDashboard,
  RefreshCw,
  ShieldCheck,
  ShoppingBag,
  Store,
  UserPlus,
  Search,
  ShieldAlert,
  type LucideIcon,
} from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { KpiGrid, type KpiRoute } from "@/components/portal/KpiGrid";
import { AgentCard, AgentEmptyState, AgentNavRow, AgentSection, AgentShell, AgentSkeleton, HeritageAccent } from "@/components/agent";
import { AgentApi } from "@/api/client";
import { useSession } from "@/store/session";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { OfflineQueueNudge } from "@/components/OfflineQueueNudge";
import { agentColors as c, agentIcon, agentLayout as L, agentType as T } from "@/theme/agent";

/** DASH-002: each KPI opens its work queue (server still authorizes it). */
const KPI_ROUTES: Record<string, KpiRoute> = {
  Clients: { label: "kpiClients", href: "/agent/clients" },
  "Active policies": { label: "kpiActivePolicies", href: "/agent/policies" },
  "Renewals due": { label: "kpiRenewalsDue", href: "/agent/renewals" },
  "Commission available": { label: "kpiCommissionAvailable", href: "/agent/wallet" },
  "Commission pending": { label: "kpiCommissionPending", href: "/agent/wallet" },
};

type Action = { label: CopyKey; subtitle: CopyKey; icon: LucideIcon; href: string };

/** The four field actions an agent reaches for first (2-col tiles). */
const QUICK: Action[] = [
  { label: "agNewSale", subtitle: "agQuoteAndRequest", icon: ShoppingBag, href: "/agent/sales/new" },
  { label: "agAddLead", subtitle: "agProspectsToFollow", icon: UserPlus, href: "/agent/leads/new" },
  { label: "searchTitle", subtitle: "searchOpenSubtitle", icon: Search, href: "/search?role=agent" },
  { label: "catTitle", subtitle: "catMenuSubtitle", icon: Store, href: "/agent/catalogue" },
];

/** Every other workspace destination (navigation rows). */
const WORKSPACE: Action[] = [
  { label: "portalTab_Leads", subtitle: "agProspectsToFollow", icon: UserPlus, href: "/agent/leads" },
  { label: "agQuotes", subtitle: "agQuotesPrepared", icon: FileSignature, href: "/agent/quotes" },
  { label: "portalTab_Customers", subtitle: "agOriginProtectedClients", icon: ContactRound, href: "/agent/clients" },
  { label: "policies", subtitle: "agClientsCover", icon: FileText, href: "/agent/policies" },
  { label: "ptProposals", subtitle: "ptProposalsSubtitle", icon: FileSignature, href: "/agent/proposals" },
  { label: "claims", subtitle: "ptClaimsSubtitle", icon: ShieldAlert, href: "/agent/claims" },
  { label: "notifPref_renewals", subtitle: "agPoliciesDueSoon", icon: RefreshCw, href: "/agent/renewals" },
  { label: "agCommissions", subtitle: "agEarningsWithdrawals", icon: CircleDollarSign, href: "/agent/wallet" },
  { label: "agVerification", subtitle: "agIdentityMandate", icon: ShieldCheck, href: "/agent/onboarding" },
  { label: "agOfflineActivity", subtitle: "agReviewRetry", icon: CloudUpload, href: "/agent/offline" },
  { label: "account", subtitle: "agProfileSecurity", icon: CircleUserRound, href: "/agent/account" },
];

const partOfDay = (h = new Date().getHours()): CopyKey => (h < 12 ? "greetingMorning" : h < 18 ? "greetingAfternoon" : "greetingEvening");

/** Commercial Agent Home (AGENT_UI_SPEC_V2): hero, KPIs (needs attention first), quick actions, workspace. */
export default function AgentHome() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentApi.dashboard(), []);
  const name = useSession((st) => st.bootstrap?.user.full_name) ?? "";
  const greeting = t("homeGreetingTime", { part: t(partOfDay()) }).replace(/,\s*$/, "");
  const firstName = name.trim().split(/\s+/)[0] ?? "";

  return (
    <AgentShell refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      {/* The one heritage moment on Home. */}
      <View style={s.hero}>
        <HeritageAccent variant="africa" size={190} opacity={0.08} style={s.heroArt} />
        <View style={s.goldRule} />
        <Text style={s.heroLabel}>{t("agFieldDesk").toUpperCase()}</Text>
        <Text accessibilityRole="header" style={s.heroTitle} numberOfLines={2}>
          {firstName ? `${greeting}, ${firstName}` : greeting}
        </Text>
        <Text style={s.heroSub}>{t("agFieldDeskSubtitle")}</Text>
      </View>

      <OfflineQueueNudge />

      {q.loading && !q.data ? (
        <AgentSkeleton rows={2} height={88} />
      ) : q.error && !q.data ? (
        <AgentEmptyState icon={LayoutDashboard} title={t("agHomeLoadError")} body={t("loadErrorBody")} actionLabel={t("agHomeRetry")} onAction={q.reload} />
      ) : !q.data || q.data.metrics.length === 0 ? (
        <AgentEmptyState icon={LayoutDashboard} title={t("agNoActivity")} body={t("agNoActivityBody")} />
      ) : (
        <KpiGrid variant="agent" metrics={q.data.metrics} routes={KPI_ROUTES} />
      )}

      <AgentSection title={t("agHomeQuickActions")}>
        <View style={s.tiles}>
          {QUICK.map((a) => (
            <Pressable
              key={a.href}
              accessibilityRole="button"
              accessibilityLabel={`${t(a.label)}, ${t(a.subtitle)}`}
              onPress={() => router.push(a.href as never)}
              style={({ pressed }) => [s.tile, pressed && s.pressed]}
            >
              <a.icon size={agentIcon.row} color={agentIcon.color} strokeWidth={agentIcon.stroke} />
              <Text style={s.tileTitle} numberOfLines={2}>{t(a.label)}</Text>
              <Text style={s.tileSub} numberOfLines={2}>{t(a.subtitle)}</Text>
            </Pressable>
          ))}
        </View>
      </AgentSection>

      <AgentSection title={t("agHomeWorkspace")}>
        <AgentCard padded={false}>
          {WORKSPACE.map((a, i) => (
            <AgentNavRow key={a.href} icon={a.icon} title={t(a.label)} subtitle={t(a.subtitle)} divider={i > 0} onPress={() => router.push(a.href as never)} />
          ))}
        </AgentCard>
      </AgentSection>
    </AgentShell>
  );
}

const s = StyleSheet.create({
  pressed: { opacity: 0.85 },
  hero: { backgroundColor: c.deepNavy, borderRadius: L.cardRadius, padding: 20, gap: 6, overflow: "hidden" },
  heroArt: { position: "absolute", right: -36, top: -24 },
  goldRule: { width: 28, height: 3, borderRadius: 2, backgroundColor: c.gold, marginBottom: 6 },
  heroLabel: { ...T.caption, color: c.lightBlue, letterSpacing: 0.6 },
  heroTitle: { ...T.screenTitle, color: c.surface },
  heroSub: { ...T.secondary, color: c.lightBlue },
  tiles: { flexDirection: "row", flexWrap: "wrap", gap: 12 },
  tile: {
    flexBasis: "46%",
    flexGrow: 1,
    minHeight: 112,
    backgroundColor: c.surface,
    borderWidth: 1,
    borderColor: c.border,
    borderRadius: L.cardRadius,
    padding: L.cardPadding,
    gap: 6,
  },
  tileTitle: { ...T.cardTitle, color: c.heading, marginTop: 4 },
  tileSub: { ...T.caption, color: c.secondary },
});

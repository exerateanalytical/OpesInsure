import React from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { ClipboardList, CloudUpload, FileSignature, FileWarning, Search, Store, TrendingUp, UserPlus, type LucideIcon } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentCard, AgentCountBadge, AgentEmptyState, AgentNavRow, AgentSection, AgentShell, AgentSkeleton } from "@/components/agent";
import { AgentWorkspaceApi } from "@/api/partner";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { type PipelineStage, pipelineCounts } from "@/lib/agentHome";
import { agentColors as c, agentType as T } from "@/theme/agent";

const STAGES: { key: PipelineStage; icon: LucideIcon; href: string }[] = [
  { key: "leads", icon: UserPlus, href: "/agent/leads" },
  { key: "quotes", icon: FileSignature, href: "/agent/quotes" },
  { key: "applications", icon: ClipboardList, href: "/agent/proposals" },
  { key: "returned", icon: FileWarning, href: "/agent/proposals" },
];

const TOOLS: { label: CopyKey; subtitle: CopyKey; icon: LucideIcon; href: string }[] = [
  { label: "catTitle", subtitle: "catMenuSubtitle", icon: Store, href: "/agent/catalogue" },
  { label: "searchTitle", subtitle: "searchOpenSubtitle", icon: Search, href: "/search?role=agent" },
  { label: "agOfflineActivity", subtitle: "agReviewRetry", icon: CloudUpload, href: "/agent/offline" },
];

/** Sales pipeline work queue: prospect -> quote -> application, each stage opens its list. */
export default function AgentPipeline() {
  const { t } = useTranslation();
  const q = useLoad(async () => {
    const [leads, quotes, proposals] = await Promise.all([AgentWorkspaceApi.leads(), AgentWorkspaceApi.quotes(), AgentWorkspaceApi.proposals()]);
    return pipelineCounts({ leads, quotes, proposals });
  }, []);
  const go = (href: string) => router.push(href as never);

  return (
    <AgentShell variant="drilldown" title={t("agQueuePipeline")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      <Text style={s.sub}>{t("agPipelineSub")}</Text>
      {q.loading && !q.data ? (
        <AgentSkeleton rows={4} />
      ) : q.error && !q.data ? (
        <AgentEmptyState icon={TrendingUp} title={t("loadErrorTitle")} body={t("loadErrorBody")} actionLabel={t("retry")} onAction={q.reload} />
      ) : (
        <AgentCard padded={false}>
          {STAGES.map((st, i) => {
            const n = q.data?.[st.key] ?? 0;
            return (
              <AgentNavRow
                key={st.key}
                icon={st.icon}
                title={t(`agStage_${st.key}` as CopyKey)}
                subtitle={t(`agStage_${st.key}Sub` as CopyKey)}
                right={<AgentCountBadge count={n} tone={st.key === "returned" && n > 0 ? "danger" : "neutral"} />}
                accessibilityLabel={`${t(`agStage_${st.key}` as CopyKey)}: ${n}`}
                divider={i > 0}
                onPress={() => go(st.href)}
              />
            );
          })}
        </AgentCard>
      )}
      <AgentSection title={t("agPipelineTools")}>
        <AgentCard padded={false}>
          {TOOLS.map((a, i) => (
            <AgentNavRow key={a.href} icon={a.icon} title={t(a.label)} subtitle={t(a.subtitle)} divider={i > 0} onPress={() => go(a.href)} />
          ))}
        </AgentCard>
      </AgentSection>
    </AgentShell>
  );
}

const s = StyleSheet.create({
  sub: { ...T.secondary, color: c.secondary },
});

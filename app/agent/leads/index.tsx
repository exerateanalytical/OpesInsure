import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Plus, UserPlus } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentButton, AgentCard, AgentEmptyState, AgentNavRow, AgentShell, AgentSkeleton, AgentStatusChip } from "@/components/agent";
import { FilterToolbar, runList, useListFilters } from "@/components/filters";
import { listSpec } from "@/components/filters/spec";
import { AgentWorkspaceApi, LeadStatus, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { isOpenLead, leadTone } from "@/lib/crm";
import { agentColors as c, agentType as T } from "@/theme/agent";

type Filter = "OPEN" | LeadStatus;
type Lead = NonNullable<Awaited<ReturnType<typeof AgentWorkspaceApi.leads>>>[number];

/** Leads (tab root, AGENT_UI_SPEC_V2): search + one filter icon; pipeline (default Open) is the first sheet section. */
export default function AgentLeads() {
  const { t, td } = useTranslation();
  const q = useLoad(() => AgentWorkspaceApi.leads(), []);
  const rows = q.data ?? [];
  const pipeline = {
    key: "pipeline",
    single: true,
    title: t("agFilterLeads"),
    options: [
      { value: "OPEN", label: t("supportStatus_OPEN") },
      { value: "CONVERTED", label: t("agLeadConverted") },
      { value: "LOST", label: t("agLeadLost") },
    ],
  };
  const base = listSpec(rows, t, {
    status: (l) => l.status,
    statusLabel: (v) => td(`leadStatus_${v}`, v),
    dims: [{ key: "product_interest", title: t("fltProductLine"), get: (l) => ({ value: l.product_interest }) }],
    date: (l) => l.created_at,
    dateTitle: t("fltCreated"),
    name: (l) => l.full_name,
  });
  const sections = [pipeline, ...base.sections];
  const matchers = { ...base.matchers, pipeline: (l: Lead, v: string) => (v === "OPEN" ? isOpenLead(l.status) : l.status === (v as Filter)) };
  const haystack = (l: Lead) => [l.full_name, l.phone_e164, l.product_interest, td(`leadStatus_${l.status}`, l.status)];
  const f = useListFilters("agent.leads", sections);
  const run = (v: typeof f.values) => runList(rows, { values: v, text: f.query, matchers, haystack, sorters: base.sorters });
  const shown = run(f.values);

  return (
    <AgentShell refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      <View style={s.head}>
        <Text accessibilityRole="header" style={s.title}>{t("portalTab_Leads")}</Text>
        <Text style={s.subtitle}>{t("agLeadsSubtitle")}</Text>
      </View>
      <AgentButton icon={Plus} label={t("agAddLead")} onPress={() => router.push("/agent/leads/new")} />

      {q.loading && !q.data ? (
        <AgentSkeleton rows={4} height={62} />
      ) : q.error && !q.data ? (
        <AgentEmptyState icon={UserPlus} title={t("agLeadsLoadError")} body={t("loadErrorBody")} actionLabel={t("agHomeRetry")} onAction={q.reload} />
      ) : rows.length === 0 ? (
        <AgentEmptyState icon={UserPlus} title={t("agNoLeads")} body={t("agNoLeadsBody")} />
      ) : (
        <View style={s.list}>
          <FilterToolbar filters={f} sections={sections} count={(v) => run(v).length} resultCount={shown.length} placeholder={t("fltSearchQueue")} />
          {shown.length === 0 ? (
            <AgentEmptyState icon={UserPlus} title={t("fltNoMatches")} body={t("fltNoMatchesBody")} actionLabel={t("fltClearAll")} onAction={f.clear} />
          ) : (
            <AgentCard padded={false}>
              {shown.map((l, i) => {
                const label = td(`leadStatus_${l.status}`, l.status);
                return (
                  <AgentNavRow
                    key={l.id}
                    icon={UserPlus}
                    divider={i > 0}
                    title={l.full_name}
                    subtitle={[l.phone_e164, l.product_interest, t("agLeadAdded", { date: shortDate(l.created_at) })].filter(Boolean).join(" · ")}
                    right={<AgentStatusChip status="Pending" label={label} tone={leadTone(l.status)} />}
                    accessibilityLabel={`${l.full_name}, ${label}`}
                    onPress={() => router.push(`/agent/leads/${l.id}`)}
                  />
                );
              })}
            </AgentCard>
          )}
        </View>
      )}
    </AgentShell>
  );
}

const s = StyleSheet.create({
  head: { gap: 4 },
  title: { ...T.screenTitle, color: c.heading },
  subtitle: { ...T.secondary, color: c.secondary },
  list: { gap: 12 },
});

import React from "react";
import { router } from "expo-router";
import { Plus, UserPlus } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button } from "@/components/ui";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { AgentWorkspaceApi, LeadStatus, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { isOpenLead } from "@/lib/crm";

type Filter = "OPEN" | LeadStatus;
type Lead = NonNullable<Awaited<ReturnType<typeof AgentWorkspaceApi.leads>>>[number];

export default function AgentLeads() {
  const { t, td } = useTranslation();
  const q = useLoad(() => AgentWorkspaceApi.leads(), []);
  // Pipeline (Open / Converted / Lost) was an inline chip row; it is the first sheet section now (default Open).
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
  const withPipeline = <S extends ReturnType<typeof listSpec<Lead>>>(spec: S): S => ({
    ...spec,
    sections: [pipeline, ...spec.sections],
    matchers: { ...spec.matchers, pipeline: (l: Lead, v: string) => (v === "OPEN" ? isOpenLead(l.status) : l.status === (v as Filter)) },
  });
  return (
    <PortalScreen tabs={agentTabs}>
      <AppHeader title={t("portalTab_Leads")} subtitle={t("agLeadsSubtitle")} />
      <Button label={t("agAddLead")} icon={Plus} onPress={() => router.push("/agent/leads/new")} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("agLoadingLeads")}
        emptyTitle={t("agNoLeads")}
        emptyMessage={t("agNoLeadsBody")}
      >
        {(x) => (
          <FilteredList
            list="agent.leads"
            icon={UserPlus}
            onPress={({ id }) => router.push(`/agent/leads/${id}`)}
            rows={x}
            {...withPipeline(listSpec(x, t, { status: (l) => l.status, statusLabel: (v) => td(`leadStatus_${v}`, v), dims: [{ key: "product_interest", title: t("fltProductLine"), get: (l) => ({ value: l.product_interest }) }], date: (l) => l.created_at, dateTitle: t("fltCreated"), name: (l) => l.full_name }))}
            haystack={(l) => [l.full_name, l.phone_e164, l.product_interest, td(`leadStatus_${l.status}`, l.status)]}
            placeholder={t("fltSearchQueue")}
            render={(l) => ({
              title: l.full_name,
              subtitle: [l.phone_e164, l.product_interest, t("agLeadAdded", { date: shortDate(l.created_at) })].filter(Boolean).join(" · "),
              status: td(`leadStatus_${l.status}`, l.status),
            })}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}

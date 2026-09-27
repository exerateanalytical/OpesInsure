import React, { useMemo } from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { router } from "expo-router";
import { ContactRound, Plus } from "lucide-react-native";
import { AppHeader, Button, Card } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi } from "@/api/client";
import { AgentWorkspaceApi, type PartnerPolicy } from "@/api/partner";
import { applyFilters, FilterToolbar, useListFilters } from "@/components/filters";
import { portfolioHaystack, portfolioMatchers, portfolioSections } from "@/components/filters/portfolio";
import { useTranslation } from "@/i18n";
const NO_POLICIES: PartnerPolicy[] = [];

export default function AgentClients() {
  const { t, td } = useTranslation();
  const q = useLoad(() => AgentApi.clients(), []);
  const pol = useLoad(() => AgentWorkspaceApi.policies().catch(() => NO_POLICIES), []);
  const rows = useMemo(() => q.data ?? [], [q.data]);
  const policies = pol.data ?? NO_POLICIES;
  // Universal portfolio filters (FLT-001..006, CUST-001/002); the server scopes the list.
  const sections = useMemo(() => portfolioSections(rows, policies, { t, td }), [rows, policies, t, td]);
  const matchers = useMemo(() => portfolioMatchers(policies), [policies]);
  const f = useListFilters("agent.clients", sections);
  const x = applyFilters(rows, f.values, matchers, f.text, portfolioHaystack);
  return (
    <PortalScreen tabs={agentTabs}>
      <AppHeader
        title={t("agClientPortfolio")}
        subtitle={t("agOriginEnforced")}
      />
      <Button
        label={t("agRegisterClient")}
        icon={Plus}
        onPress={() => router.push("/agent/clients/new")}
      />
      <FilterToolbar filters={f} sections={sections} count={(v) => applyFilters(rows, v, matchers, f.text, portfolioHaystack).length} placeholder={t("fltSearchClients")} />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <Card>
            {x.map((c) => (
              <FlowRow
                key={c.id}
                icon={ContactRound}
                title={c.full_name}
                subtitle={[c.phone_e164, c.city].filter(Boolean).join(" · ")}
                status={c.kyc_status}
                onPress={() => router.push(`/agent/clients/${c.id}`)}
              />
            ))}
          </Card>
          </>
        )}
      </StatePanel>
    </PortalScreen>
  );
}

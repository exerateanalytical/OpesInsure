import React, { useMemo } from "react";
import { useLoad } from "@/hooks/useLoad";
import { router } from "expo-router";
import { ContactRound, SearchX, UserPlus } from "lucide-react-native";
import { AgentButton, AgentCard, AgentEmptyState, AgentShell } from "@/components/agent";
import { AgentListRow } from "@/components/partner/AgentListUi";
import { BookLoad, BookTitle } from "@/components/partner/AgentBookUi";
import { AgentApi } from "@/api/client";
import { AgentWorkspaceApi, type PartnerPolicy } from "@/api/partner";
import { applyFilters, FilterToolbar, useListFilters } from "@/components/filters";
import { portfolioHaystack, portfolioMatchers, portfolioSections } from "@/components/filters/portfolio";
import { useTranslation } from "@/i18n";
const NO_POLICIES: PartnerPolicy[] = [];

/** Customers tab (AGENT_UI_SPEC_V2 operational list): search + one filter icon, rows open the customer 360. */
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
  const x = applyFilters(rows, f.values, matchers, f.query, portfolioHaystack);
  const add = () => router.push("/agent/clients/new");
  return (
    <AgentShell refreshing={q.loading && !!q.data} onRefresh={() => { q.reload(); pol.reload(); }}>
      <BookTitle title={t("agClientPortfolio")} subtitle={t("agOriginEnforced")} />
      <AgentButton label={t("agRegisterClient")} icon={UserPlus} onPress={add} />
      <BookLoad q={q} icon={ContactRound} emptyTitle={t("agkNoCustomers")} emptyBody={t("agkNoCustomersBody")} emptyAction={t("agRegisterClient")} onEmptyAction={add}>
        {() => (
          <>
            <FilterToolbar filters={f} sections={sections} count={(v) => applyFilters(rows, v, matchers, f.text, portfolioHaystack).length} placeholder={t("fltSearchClients")} resultCount={f.active ? x.length : undefined} />
            {x.length === 0 ? (
              <AgentEmptyState icon={SearchX} title={t("fltNoMatches")} body={t("fltNoMatchesBody")} actionLabel={t("fltClearAll")} onAction={f.clear} />
            ) : (
              <AgentCard padded={false}>
                {x.map((c, i) => (
                  <AgentListRow
                    key={c.id}
                    first={i === 0}
                    icon={ContactRound}
                    title={c.full_name}
                    subtitle={[c.phone_e164, c.city].filter(Boolean).join(" · ")}
                    status={c.kyc_status}
                    statusLabel={td(`kycStatus_${c.kyc_status}`, c.kyc_status?.replaceAll("_", " "))}
                    onPress={() => router.push(`/agent/clients/${c.id}`)}
                  />
                ))}
              </AgentCard>
            )}
          </>
        )}
      </BookLoad>
    </AgentShell>
  );
}

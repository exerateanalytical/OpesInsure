import React, { useEffect, useMemo } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { ContactRound, UserPlus } from "lucide-react-native";
import { View } from "react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { EmptyState, StatePanel } from "@/components/StatePanel";
import { AppHeader, Button } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { applyFilters, FilterToolbar, useListFilters } from "@/components/filters";
import { portfolioHaystack, portfolioMatchers, portfolioSections, type PortfolioClient } from "@/components/filters/portfolio";
import { BrokerApi } from "@/api/client";
import { BrokerWorkspaceApi, type PartnerPolicy } from "@/api/partner";
import { useTranslation } from "@/i18n";

const NO_POLICIES: PartnerPolicy[] = [];

/** Broker client ledger with the universal portfolio filters (FLT-001..006, CUST-001/002). */
export default function BrokerClients() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<{ q?: string }>();
  const q = useLoad(() => BrokerApi.clients(), []);
  // Policies give the insurer / product family / policy status dimensions until client rows carry them.
  const pol = useLoad(() => BrokerWorkspaceApi.policies().catch(() => NO_POLICIES), []);
  const rows: PortfolioClient[] = useMemo(() => q.data ?? [], [q.data]);
  const policies = pol.data ?? NO_POLICIES;
  const sections = useMemo(() => portfolioSections(rows, policies, { t, td }), [rows, policies, t, td]);
  const matchers = useMemo(() => portfolioMatchers(policies), [policies]);
  const f = useListFilters("broker.clients", sections);
  const { setText } = f;
  useEffect(() => {
    if (typeof params.q === "string" && params.q) setText(params.q);
  }, [params.q, setText]);
  const shown = applyFilters(rows, f.values, matchers, f.text, portfolioHaystack);
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader title={t("brClientLedger")} subtitle={t("brAccessScoped")} />
      <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 8 }}>
        <Button label={t("agRegisterClient")} icon={UserPlus} onPress={() => router.push("/broker/clients/new")} />
        <Button label={t("leadNewTitle")} variant="secondary" icon={UserPlus} onPress={() => router.push("/broker/leads/new")} />
      </View>
      <FilterToolbar
        filters={f}
        sections={sections}
        count={(v) => applyFilters(rows, v, matchers, f.text, portfolioHaystack).length}
        placeholder={t("fltSearchClients")}
      />
      <StatePanel {...q} onRetry={q.reload} emptyTitle={t("agNoClients")} emptyMessage={t("agNoClientsBody")}>
        {() =>
          shown.length === 0 ? (
            <EmptyState title={t("fltNoMatches")} message={t("fltNoMatchesBody")} action={t("fltClearAll")} onPress={f.clear} />
          ) : (
            <OperationsList
              icon={ContactRound}
              rows={shown.map((c) => ({
                id: c.id,
                title: c.full_name,
                subtitle: [c.city, t("bkPolicyCount", { count: c.policies ?? 0 })].filter(Boolean).join(" · "),
                status: c.origin_locked ? t("brOriginLocked") : t("fltOriginOpen"),
              }))}
              onPress={(id) => router.push(`/broker/clients/${id}`)}
            />
          )
        }
      </StatePanel>
    </PortalScreen>
  );
}

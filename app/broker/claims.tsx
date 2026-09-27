import React, { useEffect, useMemo } from "react";
import { ShieldAlert } from "lucide-react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { EmptyState, StatePanel } from "@/components/StatePanel";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { applyFilters, FilterToolbar, filtersFromParams, optionsFrom, useListFilters, type FilterSection, type Matchers } from "@/components/filters";
import { BrokerWorkspaceApi, money, shortDate, type PartnerClaim } from "@/api/partner";
import { useTranslation } from "@/i18n";

const matchers: Matchers<PartnerClaim> = {
  status: (c, v) => c.status === v,
  priority: (c, v) => c.priority === v,
};
const haystack = (c: PartnerClaim) => [c.claim_number, c.customer_name, c.policy_number];

export default function BrokerClaims() {
  const { t, td } = useTranslation();
  const params = useLocalSearchParams<Record<string, string>>();
  const q = useLoad(() => BrokerWorkspaceApi.claims(), []);
  const rows = useMemo(() => q.data ?? [], [q.data]);
  const sections = useMemo<FilterSection[]>(
    () => [
      { key: "status", title: t("fltClaimState"), options: optionsFrom(rows, (c) => ({ value: c.status, label: td(`claimStatus_${c.status}`, c.status) })) },
      { key: "priority", title: t("bkPriority"), options: optionsFrom(rows, (c) => (c.priority ? { value: c.priority, label: td(`priority_${c.priority}`, c.priority) } : null)) },
    ],
    [rows, t, td],
  );
  const f = useListFilters("broker.claims", sections, filtersFromParams(params));
  const { setText } = f;
  useEffect(() => {
    if (typeof params.q === "string" && params.q) setText(params.q);
  }, [params.q, setText]);
  const shown = applyFilters(rows, f.values, matchers, f.text, haystack);
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader title={t("claims")} subtitle={t("brClaimsSubtitle")} />
      <FilterToolbar filters={f} sections={sections} count={(v) => applyFilters(rows, v, matchers, f.text, haystack).length} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("claimsLoading")}
        emptyTitle={t("brNoClaims")}
        emptyMessage={t("brNoClaimsBody")}
      >
        {() =>
          shown.length === 0 ? (
            <EmptyState title={t("fltNoMatches")} message={t("fltNoMatchesBody")} action={t("fltClearAll")} onPress={f.clear} />
          ) : (
            <OperationsList
              icon={ShieldAlert}
              onPress={(id) => router.push({ pathname: "/broker/claims/[id]", params: { id: id } })}
              rows={shown.map((c) => ({
                id: c.id,
                title: `${c.claim_number} · ${c.customer_name}`,
                subtitle: [
                  c.policy_number,
                  c.approved_amount_minor !== null
                    ? `${t("bkApprovedAmount")} ${money(c.approved_amount_minor)}`
                    : c.estimated_loss_minor !== null
                      ? `${t("bkEstimatedLoss")} ${money(c.estimated_loss_minor)}`
                      : null,
                  `${t("bkFiled")} ${shortDate(c.submitted_at)}`,
                ]
                  .filter(Boolean)
                  .join(" · "),
                status: td(`claimStatus_${c.status}`, c.status),
              }))}
            />
          )
        }
      </StatePanel>
    </PortalScreen>
  );
}

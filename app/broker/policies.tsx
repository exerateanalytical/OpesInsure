import React from "react";
import { FileText } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerWorkspaceApi, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

export default function BrokerPolicies() {
  const { t } = useTranslation();
  const q = useLoad(() => BrokerWorkspaceApi.policies(), []);
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader title={t("policies")} subtitle={t("brPoliciesSubtitle")} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("policiesLoading")}
        emptyTitle={t("policiesEmpty")}
        emptyMessage={t("agNoPoliciesBody")}
      >
        {(x) => (
          <OperationsList
            icon={FileText}
            rows={x.map((p) => ({
              id: p.id,
              title: `${p.policy_number ?? "Pending number"} · ${p.customer_name}`,
              subtitle: `${p.carrier_name} · ${money(p.premium_minor)} · ends ${shortDate(p.coverage_ends_at)}`,
              status: p.status,
            }))}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}

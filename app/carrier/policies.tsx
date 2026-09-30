import { router } from "expo-router";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import React from "react";
import { FileText } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierWorkspaceApi, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

export default function CarrierPolicies() {
  return (
    <CarrierGate module="policies">
      <CarrierPoliciesBody />
    </CarrierGate>
  );
}

function CarrierPoliciesBody() {
  const { t } = useTranslation();
  const q = useLoad(() => CarrierWorkspaceApi.policies(), []);
  return (
    <PortalScreen tabs={carrierTabs}>
      <AppHeader title={t("policies")} subtitle={t("caPoliciesSubtitle")} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("policiesLoading")}
        emptyTitle={t("policiesEmpty")}
        emptyMessage={t("caNoPoliciesBody")}
      >
        {(x) => (
          <OperationsList
            icon={FileText}
            onPress={(id) => router.push(`/carrier/policies/${id}` as never)}
            rows={x.map((p) => ({
              id: p.id,
              title: `${p.policy_number ?? t("caPendingNumber")} · ${p.customer_name}`,
              subtitle: `${money(p.premium_minor)} · ${shortDate(p.coverage_starts_at)} – ${shortDate(p.coverage_ends_at)}`,
              status: p.status,
            }))}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}

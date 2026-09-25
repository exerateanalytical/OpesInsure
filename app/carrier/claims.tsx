import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { ShieldAlert } from "lucide-react-native";
import { router } from "expo-router";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierApi } from "@/api/client";
import { useTranslation } from "@/i18n";
export default function CarrierClaims() {
  const { t } = useTranslation();
  const q = useLoad(() => CarrierApi.claims(), []);
  const x = q.data ?? [];
  return (
    <PortalScreen tabs={carrierTabs}>
      <AppHeader
        title={t("claims")}
        subtitle={t("caClaimsSubtitle")}
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("claimsLoading")}
        emptyTitle={t("caNoClaims")}
        emptyMessage={t("caNoClaimsBody")}
      >
        {() => (
          <>
          <OperationsList
            icon={ShieldAlert}
            onPress={(id) => router.push(`/carrier/claims/${id}`)}
            rows={x.map((i) => ({
              id: i.id,
              title: i.reference,
              subtitle: `${i.subject} · ${i.priority}`,
              status: i.status,
            }))}
          />
          </>
        )}
      </StatePanel>
    </PortalScreen>
  );
}

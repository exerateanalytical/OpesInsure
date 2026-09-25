import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { router } from "expo-router";
import { ContactRound } from "lucide-react-native";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerApi } from "@/api/client";
import { useTranslation } from "@/i18n";
export default function BrokerClients() {
  const { t } = useTranslation();
  const q = useLoad(() => BrokerApi.clients(), []);
  const x = q.data ?? [];
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader
        title={t("brClientLedger")}
        subtitle={t("brAccessScoped")}
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <OperationsList
            icon={ContactRound}
            rows={x.map((c) => ({
              id: c.id,
              title: c.full_name,
              subtitle: [c.city, `${c.policies} policies`].filter(Boolean).join(" · "),
              status: c.origin_locked ? t("brOriginLocked") : "REVIEW",
            }))}
            onPress={(id) => router.push(`/broker/clients/${id}`)}
          />
          </>
        )}
      </StatePanel>
    </PortalScreen>
  );
}

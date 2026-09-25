import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { RefreshCw } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerApi } from "@/api/client";
import { useTranslation } from "@/i18n";
export default function BrokerRenewals() {
  const { t } = useTranslation();
  const q = useLoad(() => BrokerApi.renewals(), []);
  const x = q.data ?? [];
  return (
    <Screen>
      <AppHeader title={t("brRenewals")} back />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <OperationsList
            icon={RefreshCw}
            rows={x.map((r) => ({
              id: r.id,
              title: r.customer_name,
              subtitle: `${r.policy_number} · ${r.days_remaining} days`,
              status: r.status,
            }))}
          />
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

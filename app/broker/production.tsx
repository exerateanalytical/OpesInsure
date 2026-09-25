import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { BookOpenCheck } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerApi } from "@/api/client";
import { useTranslation } from "@/i18n";
export default function BrokerProductionScreen() {
  const { t } = useTranslation();
  const q = useLoad(() => BrokerApi.production(), []);
  const x = q.data ?? [];
  return (
    <Screen>
      <AppHeader
        title={t("brProductionRegister")}
        subtitle={t("brProductionSubtitle")}
        back
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <OperationsList
            icon={BookOpenCheck}
            rows={x.map((p) => ({
              id: p.id,
              title: p.policy_number,
              subtitle: `${p.customer_name} · ${new Intl.NumberFormat("fr-CM").format(p.premium_minor / 100)} FCFA`,
              status: p.status,
            }))}
          />
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

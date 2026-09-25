import React from "react";
import { router } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { HandCoins } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierApi } from "@/api/client";
import { fcfa } from "@/api/extra";
import { useTranslation } from "@/i18n";
export default function CarrierSettlements() {
  const { t } = useTranslation();
  const q = useLoad(() => CarrierApi.settlements(), []);
  return (
    <Screen>
      <AppHeader
        title={t("caCarrierSettlements")}
        subtitle={t("caSettlementsSubtitle")}
        back
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        emptyTitle={t("caNoSettlements")}
        emptyMessage={t("caNoSettlementsBody")}
      >
        {(x) => (
          <OperationsList
            icon={HandCoins}
            onPress={(id) =>
              router.push({ pathname: "/carrier/settlement/[id]", params: { id } })
            }
            rows={x.map((i) => ({
              id: i.id,
              title: i.period,
              subtitle: t("caNetPayable", { amount: fcfa(i.net_payable_minor) }),
              status: i.status,
            }))}
          />
        )}
      </StatePanel>
    </Screen>
  );
}

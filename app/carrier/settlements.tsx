import { CarrierGate } from "@/components/carrier/CarrierGate";
import React from "react";
import { router } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { HandCoins } from "lucide-react-native";
import { AppHeader, Screen } from "@/components/ui";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { CarrierApi } from "@/api/client";
import { fcfa } from "@/api/extra";
import { useTranslation } from "@/i18n";
export default function CarrierSettlements() {
  return (
    <CarrierGate module="settlements">
      <CarrierSettlementsBody />
    </CarrierGate>
  );
}

function CarrierSettlementsBody() {
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
          <FilteredList
            list="carrier.settlements"
            icon={HandCoins}
            onPress={({ id }) =>
              router.push({ pathname: "/carrier/settlement/[id]", params: { id } })
            }
            rows={x}
            {...listSpec(x, t, { status: (i) => i.status, amount: (i) => i.net_payable_minor, name: (i) => i.period })}
            haystack={(i) => [i.period, i.status]}
            placeholder={t("fltSearchPlaceholder")}
            amount={(i) => i.net_payable_minor}
            render={(i) => ({
              title: i.period,
              subtitle: t("caNetPayable", { amount: fcfa(i.net_payable_minor) }),
              status: i.status,
            })}
          />
        )}
      </StatePanel>
    </Screen>
  );
}

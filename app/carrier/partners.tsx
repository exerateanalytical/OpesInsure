import { router } from "expo-router";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import React from "react";
import { Handshake } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Screen } from "@/components/ui";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { CarrierWorkspaceApi, humanize, money } from "@/api/partner";
import { useTranslation } from "@/i18n";

export default function CarrierPartners() {
  return (
    <CarrierGate module="partners">
      <CarrierPartnersBody />
    </CarrierGate>
  );
}

function CarrierPartnersBody() {
  const { t } = useTranslation();
  const q = useLoad(() => CarrierWorkspaceApi.partners(), []);
  return (
    <Screen>
      <AppHeader title={t("caDistributionPartners")} subtitle={t("caPartnersSubtitle")} back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("caLoadingPartners")}
        emptyTitle={t("caNoPartners")}
        emptyMessage={t("caNoPartnersBody")}
      >
        {(x) => (
          <FilteredList
            list="carrier.partners"
            icon={Handshake}
            onPress={({ id }) => router.push(`/carrier/partners/${id}` as never)}
            rows={x}
            {...listSpec(x, t, { status: (p) => p.status, dims: [{ key: "type", title: t("fltType"), get: (p) => ({ value: p.type, label: humanize(p.type) }) }], amount: (p) => p.premium_minor, name: (p) => p.name })}
            haystack={(p) => [p.name, p.type, p.agreement_number, p.status]}
            placeholder={t("fltSearchQueue")}
            amount={(p) => p.premium_minor}
            render={(p) => ({
              title: p.name,
              subtitle: [
                humanize(p.type),
                `${p.policies} policies · ${money(p.premium_minor)}`,
                p.agreement_number ? `agreement ${p.agreement_number}` : null,
              ]
                .filter(Boolean)
                .join(" · "),
              status: p.status,
            })}
          />
        )}
      </StatePanel>
    </Screen>
  );
}

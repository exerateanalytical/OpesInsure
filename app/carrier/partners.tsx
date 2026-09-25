import React from "react";
import { Handshake } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierWorkspaceApi, humanize, money } from "@/api/partner";
import { useTranslation } from "@/i18n";

export default function CarrierPartners() {
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
          <OperationsList
            icon={Handshake}
            rows={x.map((p) => ({
              id: p.id,
              title: p.name,
              subtitle: [
                humanize(p.type),
                `${p.policies} policies · ${money(p.premium_minor)}`,
                p.agreement_number ? `agreement ${p.agreement_number}` : null,
              ]
                .filter(Boolean)
                .join(" · "),
              status: p.status,
            }))}
          />
        )}
      </StatePanel>
    </Screen>
  );
}

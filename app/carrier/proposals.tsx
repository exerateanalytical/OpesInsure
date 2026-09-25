import React from "react";
import { FileSignature } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { CarrierWorkspaceApi, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

export default function CarrierProposals() {
  const { t } = useTranslation();
  const q = useLoad(() => CarrierWorkspaceApi.proposals(), []);
  return (
    <Screen>
      <AppHeader title={t("caQuotesProposals")} subtitle={t("caProposalsSubtitle")} back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("caLoadingProposals")}
        emptyTitle={t("caNoProposals")}
        emptyMessage={t("caNoProposalsBody")}
      >
        {(x) => (
          <OperationsList
            icon={FileSignature}
            rows={x.map((p) => ({
              id: p.id,
              title: `${p.reference} · ${p.customer_name}`,
              subtitle: `${p.product} · ${money(p.premium_minor)} · ${shortDate(p.submitted_at ?? p.created_at)}`,
              status: p.status,
            }))}
          />
        )}
      </StatePanel>
    </Screen>
  );
}

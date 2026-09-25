import React from "react";
import { ShieldAlert } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { PortalScreen } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerWorkspaceApi, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

export default function BrokerClaims() {
  const { t } = useTranslation();
  const q = useLoad(() => BrokerWorkspaceApi.claims(), []);
  return (
    <PortalScreen tabs={brokerTabs}>
      <AppHeader title={t("claims")} subtitle={t("brClaimsSubtitle")} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("claimsLoading")}
        emptyTitle={t("brNoClaims")}
        emptyMessage={t("brNoClaimsBody")}
      >
        {(x) => (
          <OperationsList
            icon={ShieldAlert}
            rows={x.map((c) => ({
              id: c.id,
              title: `${c.claim_number} · ${c.customer_name}`,
              subtitle: [
                c.policy_number,
                c.approved_amount_minor !== null
                  ? `approved ${money(c.approved_amount_minor)}`
                  : c.estimated_loss_minor !== null
                    ? `estimated ${money(c.estimated_loss_minor)}`
                    : null,
                `filed ${shortDate(c.submitted_at)}`,
              ]
                .filter(Boolean)
                .join(" · "),
              status: c.status,
            }))}
          />
        )}
      </StatePanel>
    </PortalScreen>
  );
}

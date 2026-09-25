import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { RefreshCw } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { AgentApi } from "@/api/client";
import { useTranslation } from "@/i18n";
export default function AgentRenewals() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentApi.renewals(), []);
  const x = q.data ?? [];
  return (
    <Screen>
      <AppHeader
        title={t("agRenewalPipeline")}
        subtitle={t("agRenewalSubtitle")}
        back
      />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <Card>
            {x.map((r) => (
              <FlowRow
                key={r.id}
                icon={RefreshCw}
                title={r.customer_name}
                subtitle={t("agDaysRemaining", { number: r.policy_number, days: r.days_remaining })}
                status={r.status}
              />
            ))}
          </Card>
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

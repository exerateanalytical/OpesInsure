import React from "react";
import { FileSignature } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerWorkspaceApi, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

export default function BrokerQuotes() {
  const { t } = useTranslation();
  const q = useLoad(() => BrokerWorkspaceApi.quotes(), []);
  return (
    <Screen>
      <AppHeader title={t("agQuotes")} subtitle={t("brQuotesSubtitle")} back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("quotesLoading")}
        emptyTitle={t("agNoQuotes")}
        emptyMessage={t("brNoQuotesBody")}
      >
        {(x) => (
          <OperationsList
            icon={FileSignature}
            rows={x.map((r) => ({
              id: r.id,
              title: `${r.customer_name} · ${r.line_code}`,
              subtitle: [
                r.best_premium_minor !== null ? `best ${money(r.best_premium_minor)}` : `${r.offers} offers`,
                shortDate(r.created_at),
              ].join(" · "),
              status: r.status,
            }))}
          />
        )}
      </StatePanel>
    </Screen>
  );
}

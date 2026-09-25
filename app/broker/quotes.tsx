import React from "react";
import { FileSignature } from "lucide-react-native";
import { router } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { BrokerWorkspaceApi, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { quoteOutcome } from "@/lib/quoteWorkflow";

export default function BrokerQuotes() {
  const { t, td } = useTranslation();
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
            onPress={(id) => {
              const r = x.find((y) => y.id === id);
              router.push({ pathname: "/broker/quotes/[id]", params: { id, title: r ? `${r.customer_name} · ${r.line_code}` : "" } });
            }}
            rows={x.map((r) => ({
              id: r.id,
              title: `${r.customer_name} · ${r.line_code}`,
              subtitle: [
                r.best_premium_minor !== null ? `best ${money(r.best_premium_minor)}` : `${r.offers} offers`,
                shortDate(r.created_at),
              ].join(" · "),
              status: td(`quoteStatus_${quoteOutcome(r) ?? r.status}`, r.status),
            }))}
          />
        )}
      </StatePanel>
    </Screen>
  );
}

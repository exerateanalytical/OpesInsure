import React from "react";
import { router } from "expo-router";
import { FileSignature, Plus } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Screen } from "@/components/ui";
import { OperationsList } from "@/components/OperationsList";
import { AgentWorkspaceApi, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { quoteOutcome } from "@/lib/quoteWorkflow";

export default function AgentQuotes() {
  const { t, td } = useTranslation();
  const q = useLoad(() => AgentWorkspaceApi.quotes(), []);
  return (
    <Screen>
      <AppHeader title={t("agQuotes")} subtitle={t("agQuotesSubtitle")} back />
      <Button label={t("agNewAssistedSale")} icon={Plus} onPress={() => router.push("/agent/sales/new")} />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("quotesLoading")}
        emptyTitle={t("agNoQuotes")}
        emptyMessage={t("agNoQuotesBody")}
      >
        {(x) => (
          <OperationsList
            icon={FileSignature}
            onPress={(id) => {
              const r = x.find((y) => y.id === id);
              router.push({ pathname: "/agent/quotes/[id]", params: { id, title: r ? `${r.customer_name} · ${r.line_code}` : "" } });
            }}
            rows={x.map((r) => ({
              id: r.id,
              title: `${r.customer_name} · ${r.line_code}`,
              subtitle: [
                r.best_premium_minor !== null ? money(r.best_premium_minor) : `${r.offers} offers`,
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

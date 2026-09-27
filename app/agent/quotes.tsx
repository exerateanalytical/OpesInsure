import React from "react";
import { router } from "expo-router";
import { FileSignature, Plus } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Screen } from "@/components/ui";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
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
          <FilteredList
            list="agent.quotes"
            rows={x}
            {...listSpec(x, t, {
              status: (r) => quoteOutcome(r) ?? r.status,
              statusLabel: (v) => td(`quoteStatus_${v}`, v),
              dims: [{ key: "line_code", title: t("fltProductLine"), get: (r) => (r.line_code ? { value: r.line_code, label: td(`line_${r.line_code}`, r.line_code) } : null) }],
              date: (r) => r.created_at,
              dateTitle: t("fltCreated"),
              amount: (r) => r.best_premium_minor,
              name: (r) => r.customer_name,
            })}
            haystack={(r) => [r.customer_name, r.line_code, td(`quoteStatus_${quoteOutcome(r) ?? r.status}`, r.status)]}
            placeholder={t("fltSearchQueue")}
            icon={FileSignature}
            onPress={(r) => router.push({ pathname: "/agent/quotes/[id]", params: { id: r.id, title: `${r.customer_name} · ${r.line_code}` } })}
            render={(r) => ({
              title: `${r.customer_name} · ${r.line_code}`,
              subtitle: [
                r.best_premium_minor !== null ? money(r.best_premium_minor) : `${r.offers} offers`,
                shortDate(r.created_at),
              ].join(" · "),
              status: td(`quoteStatus_${quoteOutcome(r) ?? r.status}`, r.status),
            })}
          />
        )}
      </StatePanel>
    </Screen>
  );
}

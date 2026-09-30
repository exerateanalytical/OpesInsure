import React from "react";
import { router } from "expo-router";
import { FileSignature, Plus } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentButton, AgentEmptyState, AgentShell, AgentSkeleton } from "@/components/agent";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { AgentWorkspaceApi, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { quoteOutcome } from "@/lib/quoteWorkflow";

/** Agent quotes (spec v2 list page): search + one filter icon, drill-down to the quote. */
export default function AgentQuotes() {
  const { t, td } = useTranslation();
  const q = useLoad(() => AgentWorkspaceApi.quotes(), []);
  const newSale = <AgentButton label={t("agNewAssistedSale")} icon={Plus} onPress={() => router.push("/agent/sales/new")} />;
  return (
    <AgentShell variant="drilldown" title={t("agQuotes")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      {q.loading && !q.data ? (
        <AgentSkeleton rows={5} />
      ) : q.error ? (
        <AgentEmptyState icon={FileSignature} title={t("loadErrorTitle")} body={t("loadErrorBody")} actionLabel={t("retry")} onAction={q.reload} />
      ) : !q.data?.length ? (
        <>
          {newSale}
          <AgentEmptyState icon={FileSignature} title={t("agNoQuotes")} body={t("agNoQuotesBody")} />
        </>
      ) : (
        <FilteredList
          variant="agent"
          list="agent.quotes"
          rows={q.data}
          action={newSale}
          {...listSpec(q.data, t, {
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
            title: r.customer_name,
            subtitle: [r.line_code ? td(`line_${r.line_code}`, r.line_code) : null, r.best_premium_minor === null ? t(r.offers === 1 ? "agOffersCountOne" : "agOffersCount", { count: r.offers }) : null, shortDate(r.created_at)]
              .filter(Boolean)
              .join(" · "),
            amount: r.best_premium_minor !== null ? money(r.best_premium_minor) : null,
            statusCode: quoteOutcome(r) ?? r.status,
            status: td(`quoteStatus_${quoteOutcome(r) ?? r.status}`, r.status),
          })}
        />
      )}
    </AgentShell>
  );
}

import React from "react";
import { useLocalSearchParams } from "expo-router";
import { AppHeader, Screen } from "@/components/ui";
import { QuoteWorkflowPanel } from "@/components/offers/QuoteWorkflowPanel";
import { AgentShell } from "@/components/agent";
import { useTranslation } from "@/i18n";

/** Agent / broker quote: number, lifecycle, sent-to-insurer, PDF, comparison and decline (GET quotes/{id}). */
export function PartnerQuoteScreen({ variant = "default" }: { variant?: "default" | "agent" } = {}) {
  const { t } = useTranslation();
  const { id, title } = useLocalSearchParams<{ id: string; title?: string }>();
  if (variant === "agent") {
    // Commercial Agent spec v2 drill-down frame; the workflow panel itself is shared with the broker.
    return (
      <AgentShell variant="drilldown" title={t("pqTitle")}>
        <QuoteWorkflowPanel quoteId={id} variant="agent" />
      </AgentShell>
    );
  }
  return (
    <Screen>
      <AppHeader title={t("pqTitle")} subtitle={title} back />
      <QuoteWorkflowPanel quoteId={id} />
    </Screen>
  );
}

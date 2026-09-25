import React from "react";
import { useLocalSearchParams } from "expo-router";
import { AppHeader, Screen } from "@/components/ui";
import { QuoteWorkflowPanel } from "@/components/offers/QuoteWorkflowPanel";
import { useTranslation } from "@/i18n";

/** Agent / broker quote: number, lifecycle, sent-to-insurer, PDF, comparison and decline (GET quotes/{id}). */
export function PartnerQuoteScreen() {
  const { t } = useTranslation();
  const { id, title } = useLocalSearchParams<{ id: string; title?: string }>();
  return (
    <Screen>
      <AppHeader title={t("pqTitle")} subtitle={title} back />
      <QuoteWorkflowPanel quoteId={id} />
    </Screen>
  );
}

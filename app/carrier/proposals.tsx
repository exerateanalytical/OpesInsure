import { router } from "expo-router";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import React from "react";
import { FileSignature } from "lucide-react-native";
import { useCursorList } from "@/hooks/useCursorList";
import { LoadMore } from "@/components/purchase/PurchaseUi";
import { CarrierPagedApi } from "@/api/workspace";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Screen } from "@/components/ui";
import { FilteredList } from "@/components/filters/FilteredList";
import { listSpec } from "@/components/filters/spec";
import { money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { proposalStatusInfo } from "@/lib/purchase";

export default function CarrierProposals() {
  return (
    <CarrierGate module="proposals">
      <CarrierProposalsBody />
    </CarrierGate>
  );
}

function CarrierProposalsBody() {
  const { t, language } = useTranslation();
  // Phase-1 fix S: cursor-paged (the list used to stop at the first 100 rows).
  const q = useCursorList(() => CarrierPagedApi.proposals());
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
          <FilteredList
            list="carrier.proposals"
            icon={FileSignature}
            onPress={({ id }) => router.push(`/carrier/proposals/${id}` as never)}
            rows={x}
            {...listSpec(x, t, { status: (p) => p.status, statusLabel: (v) => proposalStatusInfo(v, language).label, dims: [{ key: "product", title: t("fltProductLine"), get: (p) => ({ value: p.product }) }], date: (p) => p.submitted_at ?? p.created_at, dateTitle: t("fltReceived"), amount: (p) => p.premium_minor, name: (p) => p.customer_name })}
            haystack={(p) => [p.reference, p.customer_name, p.product, proposalStatusInfo(p.status, language).label]}
            placeholder={t("fltSearchQueue")}
            amount={(p) => p.premium_minor}
            render={(p) => ({
              title: `${p.reference} · ${p.customer_name}`,
              subtitle: `${p.product} · ${money(p.premium_minor)} · ${shortDate(p.submitted_at ?? p.created_at)}`,
              status: proposalStatusInfo(p.status, language).label,
            })}
          />
        )}
      </StatePanel>
      <LoadMore {...q.more} />
    </Screen>
  );
}

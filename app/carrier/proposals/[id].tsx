import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { FileCheck2, HandCoins } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { DetailScreen, DetailSection, UnavailableSection } from "@/components/detail";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import { OperationsList } from "@/components/OperationsList";
import { SectionTitle } from "@/components/ui";
import { ApiError, CarrierApi } from "@/api/client";
import { CarrierPagedApi } from "@/api/workspace";
import { CarrierWorkspaceApi, humanize, money, shortDate } from "@/api/partner";
import { proposalStatusInfo } from "@/lib/purchase";
import { useTranslation } from "@/i18n";

/**
 * Carrier Proposal Detail (CAR-003). Carrier-safe: read-only review of what
 * the carrier-scoped APIs return; no customer actions. Issuance and payment
 * context are joined from the carrier's own queues by proposal number.
 */
export default function CarrierProposalDetail() {
  return (
    <CarrierGate module="proposals">
      <Body />
    </CarrierGate>
  );
}

function Body() {
  const { t, language } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(async () => {
    // Phase-1 fix S: the proposal by id (it used to be looked up in the first page of the list).
    const [p, issuance, payments] = await Promise.all([
      CarrierPagedApi.proposal(String(id)).catch((e: unknown) => {
        if (e instanceof ApiError && e.status === 404) return null;
        throw e;
      }),
      CarrierApi.issuance().catch(() => []),
      CarrierWorkspaceApi.payments().catch(() => null),
    ]);
    if (!p) return null;
    return {
      p,
      issuance: issuance.filter((i) => i.reference === p.reference),
      payments: (payments?.items ?? []).filter((x) => x.proposal_number === p.reference),
    };
  }, [id]);
  return (
    <DetailScreen
      title={t("cdProposalTitle")}
      subtitle={(d) => d?.p.reference}
      query={q}
      loadingLabel={t("caLoadingProposals")}
      isMissing={(d) => d === null}
    >
      {(d) => {
        const { p } = d!;
        return (
          <>
            <DetailSection
              title={t("cdSummary")}
              rows={[
                [t("cdReference"), p.reference],
                [t("cdStatus"), proposalStatusInfo(p.status, language).label],
                [t("cdSubmitted"), p.submitted_at ? shortDate(p.submitted_at) : null],
                [t("cdCreated"), shortDate(p.created_at)],
              ]}
            />
            <DetailSection title={t("cdCustomer")} rows={[[t("cdName"), p.customer_name]]} />
            <DetailSection
              title={t("cdRiskProduct")}
              rows={[
                [t("cdProduct"), p.product],
                [t("cdLine"), p.line_code],
                [t("cdQuoteStatus"), humanize(p.quote_status)],
              ]}
            />
            <DetailSection title={t("cdPremium")} rows={[[t("cdTotalPremium"), money(p.premium_minor)]]} />
            {d!.payments.length ? (
              <>
                <SectionTitle title={t("cdPayment")} />
                <OperationsList
                  icon={HandCoins}
                  onPress={(pid) => router.push(`/carrier/payments/${pid}` as never)}
                  rows={d!.payments.map((x) => ({
                    id: x.id,
                    title: `${money(x.amount_minor)} · ${x.provider}`,
                    subtitle: `${humanize(x.reconciliation_status)} · ${shortDate(x.created_at)}`,
                    status: x.status,
                  }))}
                />
              </>
            ) : (
              <UnavailableSection title={t("cdPayment")} message={t("cdNoPaymentYet")} />
            )}
            {d!.issuance.length ? (
              <>
                <SectionTitle title={t("cdIssuance")} />
                <OperationsList
                  icon={FileCheck2}
                  onPress={(iid) => router.push(`/carrier/issuance/${iid}` as never)}
                  rows={d!.issuance.map((x) => ({
                    id: x.id,
                    title: x.reference,
                    subtitle: shortDate(x.submitted_at),
                    status: humanize(x.status),
                  }))}
                />
              </>
            ) : null}
            <UnavailableSection title={t("cdIntermediaryDisclosuresDocs")} />
          </>
        );
      }}
    </DetailScreen>
  );
}

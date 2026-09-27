import React from "react";
import { useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { DetailScreen, DetailSection, UnavailableSection } from "@/components/detail";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import { CarrierWorkspaceApi, humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

/**
 * Carrier Payment Detail (CAR-008). Status and reconciliation are exactly
 * what the server reports — the app never marks a payment as succeeded.
 */
export default function CarrierPaymentDetail() {
  return (
    <CarrierGate module="payments">
      <Body />
    </CarrierGate>
  );
}

function Body() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(async () => (await CarrierWorkspaceApi.payments()).items.find((x) => x.id === id) ?? null, [id]);
  return (
    <DetailScreen
      title={t("cdPaymentTitle")}
      subtitle={(p) => p?.reference}
      query={q}
      isMissing={(p) => p === null}
    >
      {(p) => (
        <>
          <DetailSection
            title={t("cdSummary")}
            rows={[
              [t("cdReference"), p!.reference],
              [t("cdAmount"), money(p!.amount_minor)],
              [t("cdStatus"), humanize(p!.status)],
              [t("cdMethodProvider"), p!.provider],
              [t("cdCreated"), shortDate(p!.created_at)],
            ]}
          />
          <DetailSection
            title={t("cdCustomerProposal")}
            rows={[
              [t("cdName"), p!.customer_name],
              [t("cdProposal"), p!.proposal_number],
            ]}
          />
          <DetailSection
            title={t("cdReconciliation")}
            rows={[
              [t("cdStatus"), humanize(p!.reconciliation_status)],
              [t("cdException"), p!.exception_code],
              [t("cdReconciledAt"), p!.reconciled_at ? shortDate(p!.reconciled_at) : null],
            ]}
          />
          <UnavailableSection title={t("cdPaymentAudit")} />
        </>
      )}
    </DetailScreen>
  );
}

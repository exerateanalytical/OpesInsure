import React from "react";
import { useLocalSearchParams } from "expo-router";
import { DetailScreen, DetailSection, UnavailableSection } from "@/components/detail";
import { useRecord } from "@/hooks/useRecord";
import { CarrierPagedApi } from "@/api/workspace";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import { humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

/** Carrier Policy Detail (CAR-006): every register row opens its record. */
export default function CarrierPolicyDetail() {
  return (
    <CarrierGate module="policies">
      <Body />
    </CarrierGate>
  );
}

function Body() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useRecord(CarrierPagedApi.policy, id);
  return (
    <DetailScreen
      title={t("cdPolicyTitle")}
      subtitle={(p) => p?.policy_number ?? undefined}
      query={q}
      loadingLabel={t("policiesLoading")}
      isMissing={(p) => p === null}
    >
      {(p) => (
        <>
          <DetailSection
            title={t("cdSummary")}
            rows={[
              [t("cdPolicyNumber"), p!.policy_number ?? t("cdPendingNumber")],
              [t("cdStatus"), humanize(p!.status)],
              [t("cdInsurer"), p!.carrier_name],
              [t("cdLine"), p!.line_code],
              [t("cdIssued"), p!.issued_at ? shortDate(p!.issued_at) : null],
            ]}
          />
          <DetailSection title={t("cdCustomer")} rows={[[t("cdName"), p!.customer_name]]} />
          <DetailSection
            title={t("cdCover")}
            rows={[
              [t("cdCoverStart"), shortDate(p!.coverage_starts_at)],
              [t("cdCoverEnd"), shortDate(p!.coverage_ends_at)],
              [t("cdTotalPremium"), money(p!.premium_minor)],
            ]}
          />
          <UnavailableSection title={t("cdPolicyServicing")} />
        </>
      )}
    </DetailScreen>
  );
}

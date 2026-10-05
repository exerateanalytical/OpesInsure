import React from "react";
import { useLocalSearchParams } from "expo-router";
import { DetailScreen, DetailSection, UnavailableSection } from "@/components/detail";
import { useRecord } from "@/hooks/useRecord";
import { CarrierPagedApi } from "@/api/workspace";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import { humanize, money } from "@/api/partner";
import { useTranslation } from "@/i18n";

/** Distribution Partner Detail (CAR-009). Carrier-scoped server-side. */
export default function CarrierPartnerDetail() {
  return (
    <CarrierGate module="partners">
      <Body />
    </CarrierGate>
  );
}

function Body() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useRecord(CarrierPagedApi.partner, id);
  return (
    <DetailScreen
      title={t("cdPartnerTitle")}
      subtitle={(p) => p?.name}
      query={q}
      loadingLabel={t("caLoadingPartners")}
      isMissing={(p) => p === null}
    >
      {(p) => (
        <>
          <DetailSection
            title={t("cdIdentity")}
            rows={[
              [t("cdName"), p!.name],
              [t("cdType"), humanize(p!.type)],
              [t("cdStatus"), humanize(p!.status)],
              [t("cdLicence"), p!.licence_number],
            ]}
          />
          <DetailSection
            title={t("cdAgreement")}
            rows={[
              [t("cdAgreementNumber"), p!.agreement_number ?? t("cdNoAgreement")],
              [t("cdStatus"), p!.agreement_status ? humanize(p!.agreement_status) : null],
            ]}
          />
          <DetailSection
            title={t("cdProduction")}
            rows={[
              [t("cdPoliciesCount"), String(p!.policies)],
              [t("cdTotalPremium"), money(p!.premium_minor)],
            ]}
          />
          <UnavailableSection title={t("cdPartnerMore")} />
        </>
      )}
    </DetailScreen>
  );
}

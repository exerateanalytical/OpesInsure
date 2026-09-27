import React from "react";
import { useLocalSearchParams } from "expo-router";
import { FileSpreadsheet } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { DetailScreen, DetailSection, UnavailableSection } from "@/components/detail";
import { CarrierGate } from "@/components/carrier/CarrierGate";
import { OperationsList } from "@/components/OperationsList";
import { SectionTitle } from "@/components/ui";
import { CarrierFinanceApi } from "@/api/extra";
import { humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

/** Bordereau Detail (CAR-011): summary and line items from the carrier-scoped endpoint. */
export default function CarrierBordereauDetail() {
  return (
    <CarrierGate module="bordereaux">
      <Body />
    </CarrierGate>
  );
}

function Body() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => CarrierFinanceApi.bordereau(String(id)), [id]);
  return (
    <DetailScreen title={t("cdBordereauTitle")} subtitle={(b) => b.bordereau_number} query={q}>
      {(b) => {
        const net = b.gross_premium_minor - b.commission_minor;
        return (
          <>
            <DetailSection
              title={t("cdSummary")}
              rows={[
                [t("cdReference"), b.bordereau_number],
                [t("cdType"), humanize(b.type)],
                [t("cdStatus"), humanize(b.status)],
                [t("cdPeriod"), `${shortDate(b.period_start)} – ${shortDate(b.period_end)}`],
                [t("cdItemsCount"), String(b.item_count)],
                [t("cdSubmitted"), b.submitted_at ? shortDate(b.submitted_at) : null],
                [t("cdCarrierReference"), b.carrier_reference ?? null],
                [t("cdRejectionReason"), b.rejection_reason ?? null],
              ]}
            />
            <DetailSection
              title={t("cdTotals")}
              rows={[
                [t("cdGrossPremium"), money(b.gross_premium_minor)],
                [t("cdCommission"), money(b.commission_minor)],
                [t("cdNet"), money(net)],
              ]}
            />
            <SectionTitle title={t("cdLineItems", { count: b.items?.length ?? 0 })} />
            {b.items?.length ? (
              <OperationsList
                icon={FileSpreadsheet}
                rows={b.items.map((i) => ({
                  id: i.id,
                  title: `${humanize(i.transaction_type)} · ${money(i.premium_minor)}`,
                  subtitle: `${t("cdCommission")} ${money(i.commission_minor)}${i.effective_at ? ` · ${shortDate(i.effective_at)}` : ""}`,
                  status: "",
                }))}
              />
            ) : (
              <UnavailableSection title={t("cdLineItems", { count: 0 })} message={t("caBatchEmpty")} />
            )}
            <UnavailableSection title={t("cdBordereauActions")} />
          </>
        );
      }}
    </DetailScreen>
  );
}

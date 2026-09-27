import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { CircleDollarSign, ContactRound, FileText } from "lucide-react-native";
import { DetailScreen, DetailSection, useListRecord } from "@/components/detail";
import { FlowRow } from "@/components/FlowPrimitives";
import { BrokerApi } from "@/api/client";
import { humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { useLoad } from "@/hooks/useLoad";
import { loadBrokerLedger } from "@/components/partner/brokerLedger";
import { SaleCommissionCard } from "@/components/partner/SaleCommission";

/** BRK-006 production record: traced to its policy, customer and commission. */
export default function BrokerProductionDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useListRecord(BrokerApi.production, id);
  const ledger = useLoad(() => loadBrokerLedger().catch(() => null), []);
  return (
    <DetailScreen title={t("bkProductionSubtitle")} subtitle={(p) => p?.policy_number} query={q} isMissing={(p) => p === null}>
      {(p) =>
        p ? (
          <>
            <DetailSection
              title={t("brProductionRegister")}
              rows={[
                [t("pcStatus"), humanize(p.status)],
                [t("bkPolicyNumber"), p.policy_number],
                [t("agClient"), p.customer_name],
                [t("fltInsurer"), p.carrier_name],
                [t("bkPremium"), money(p.premium_minor)],
                [t("bkIssued"), shortDate(p.issued_at)],
                [t("bkSource"), t("bkSourcePolicyRegister")],
              ]}
            />
            <SaleCommissionCard
              rows={ledger.data}
              sale={{ policyId: p.id, premiumMinor: p.premium_minor }}
              onOpen={(accrualId) => router.push({ pathname: "/broker/commissions/[id]", params: { id: accrualId, kind: "accrual" } })}
            />
            <DetailSection title={t("bkRelated")}>
              <FlowRow icon={FileText} title={t("bkPolicy")} subtitle={p.policy_number} onPress={() => router.push({ pathname: "/broker/policies/[id]", params: { id: p.id } })} />
              <FlowRow icon={ContactRound} title={t("agClient")} subtitle={p.customer_name} onPress={() => router.push({ pathname: "/broker/clients", params: { q: p.customer_name } })} />
              <FlowRow icon={CircleDollarSign} title={t("agCommissions")} subtitle={t("bkCommissionOnPolicy")} onPress={() => router.push({ pathname: "/broker/commissions", params: { q: p.policy_number } })} />
            </DetailSection>
          </>
        ) : null
      }
    </DetailScreen>
  );
}

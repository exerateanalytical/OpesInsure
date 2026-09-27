import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { ContactRound, FileText } from "lucide-react-native";
import { DetailScreen, DetailSection, UnavailableSection, useListRecord } from "@/components/detail";
import { FlowRow } from "@/components/FlowPrimitives";
import { BrokerApi } from "@/api/client";
import { humanize, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

/** BRK-005 renewal work item (id = policy id); requote / decision / issuance await backend actions. */
export default function BrokerRenewalDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useListRecord(BrokerApi.renewals, id);
  return (
    <DetailScreen title={t("bkRenewalSubtitle")} subtitle={(r) => r?.customer_name} query={q} isMissing={(r) => r === null}>
      {(r) =>
        r ? (
          <>
            <DetailSection
              title={t("brRenewals")}
              rows={[
                [t("pcStatus"), humanize(r.status)],
                [t("agClient"), r.customer_name],
                [t("bkPolicyNumber"), r.policy_number],
                [t("bkCoverEnd"), shortDate(r.expires_at)],
                [t("bkDaysRemaining"), r.days_remaining],
              ]}
            />
            <DetailSection title={t("bkRelated")}>
              <FlowRow icon={FileText} title={t("bkPolicy")} subtitle={r.policy_number} onPress={() => router.push({ pathname: "/broker/policies/[id]", params: { id: r.id } })} />
              <FlowRow icon={ContactRound} title={t("agClient")} subtitle={r.customer_name} onPress={() => router.push({ pathname: "/broker/clients/[id]", params: { id: r.customer_id } })} />
            </DetailSection>
            <UnavailableSection title={t("bkNextActions")} message={t("bkRenewalActionsPending")} />
          </>
        ) : null
      }
    </DetailScreen>
  );
}

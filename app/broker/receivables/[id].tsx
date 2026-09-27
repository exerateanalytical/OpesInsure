import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { CircleDollarSign, FileText } from "lucide-react-native";
import { DetailScreen, DetailSection, UnavailableSection, useListRecord } from "@/components/detail";
import { FlowRow } from "@/components/FlowPrimitives";
import { BrokerApi } from "@/api/client";
import { humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";

type Receivable = Awaited<ReturnType<typeof BrokerApi.receivables>>[number] & { policy_id?: string | null; policy_number?: string | null; label?: string | null };

const ageing = (due?: string | null) => (due ? Math.max(0, Math.floor((Date.now() - Date.parse(due)) / 86_400_000)) : null);

/** BRK-008 receivable: balance, ageing and its source accrual / policy. */
export default function BrokerReceivableDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useListRecord(BrokerApi.receivables as () => Promise<Receivable[]>, id);
  return (
    <DetailScreen title={t("brReceivables")} subtitle={(r) => r?.customer_name} query={q} isMissing={(r) => r === null}>
      {(r) =>
        r ? (
          <>
            <DetailSection
              title={t("brLedgerAuthoritative")}
              rows={[
                [t("pcStatus"), humanize(r.status)],
                [t("bkSource"), r.label],
                [t("agClient"), r.customer_name],
                [t("bkPolicyNumber"), r.policy_number],
                [t("bkOutstanding"), money(r.amount_minor)],
                [t("bkDue"), shortDate(r.due_at)],
                [t("bkAgeingDays"), ageing(r.due_at)],
              ]}
            />
            <DetailSection title={t("bkRelated")}>
              {r.policy_id ? (
                <FlowRow icon={FileText} title={t("bkPolicy")} subtitle={r.policy_number ?? ""} onPress={() => router.push({ pathname: "/broker/policies/[id]", params: { id: String(r.policy_id) } })} />
              ) : null}
              <FlowRow icon={CircleDollarSign} title={t("brAccruals")} subtitle={t("brAccrualsStatements")} onPress={() => router.push({ pathname: "/broker/commissions/[id]", params: { id: r.id, kind: "accrual" } })} />
            </DetailSection>
            <UnavailableSection title={t("bkReconciliation")} message={t("bkReconciliationPending")} />
          </>
        ) : null
      }
    </DetailScreen>
  );
}

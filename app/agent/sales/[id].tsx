import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { router, useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { CircleDollarSign, FileSignature, FileText } from "lucide-react-native";
import {
  AppHeader,
  Button,
  Card,
  Money,
  Screen,
  SectionTitle,
  StatusChip,
} from "@/components/ui";
import { DetailRow } from "@/components/design";
import { Step } from "@/components/FlowPrimitives";
import { AgentApi, AgentSale } from "@/api/client";
import { humanize, money } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/** Optional lifecycle fields; rendered only when the server sends them (never inferred on device). */
type SaleLifecycle = AgentSale & {
  proposal_id?: string | null;
  policy_id?: string | null;
  payment_failure_reason?: string | null;
  payment_verified_at?: string | null;
  receipt_url?: string | null;
  commission_status?: string | null;
};

const FAILED = new Set(["FAILED", "EXPIRED", "CANCELLED", "DECLINED"]);

export default function AgentSaleDetail() {
  const { t, td, date } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => AgentApi.sale(id) as Promise<SaleLifecycle>, [id]);
  const x = q.data;
  const setX = q.setData;
  const paid = x?.payment_status === "PAID";
  const issued = x?.status === "ISSUED";
  const failed = !!x && FAILED.has(x.payment_status);
  // PAYMENT_OK_ISSUANCE_FAILED / pending: payment verified by the server but no policy yet.
  const issuanceGap = paid && !issued;
  return (
    <Screen>
      <AppHeader title={t("agAssistedSaleTitle")} subtitle={x?.customer_name} back />
      <StatePanel {...q} onRetry={q.reload}>
        {() => (
          <>
          <Card feature>
            <StatusChip label={td(`saleStatus_${x?.status}`, humanize(x?.status ?? "LOADING"))} tone={issued ? "success" : issuanceGap || failed ? "warning" : "info"} />
            <Text>{x?.product}</Text>
            {x ? <Money amount={x.premium_minor / 100} size="large" /> : null}
            <DetailRow label={t("slClientPhone")} value={x?.payment_phone_e164} />
            <DetailRow label={t("slPayment")} value={x ? td(`paymentStatus_${x.payment_status}`, humanize(x.payment_status)) : null} />
            <DetailRow label={t("slVerifiedAt")} value={x?.payment_verified_at ? date(x.payment_verified_at, true) : null} />
          </Card>
          <SectionTitle title={t("slProgress")} />
          <Card>
            <Step label={t("slQuoted")} complete={!!x} />
            <Step label={t("slPaymentRequested")} complete={!!x && x.payment_status !== "NOT_REQUESTED"} />
            <Step label={t("slPaymentVerified")} complete={paid} />
            <Step label={t("slIssued")} complete={issued} />
          </Card>
          {issuanceGap ? (
            <Card>
              <Text style={{ ...type.cardTitle, color: colors.navy950 }}>{t("slIssuancePending")}</Text>
              <Text style={{ ...type.body, color: colors.neutral700 }}>{t("slIssuancePendingBody")}</Text>
            </Card>
          ) : null}
          {failed ? (
            <Card>
              <Text style={{ ...type.cardTitle, color: colors.dangerText }}>{t("slPaymentFailed")}</Text>
              <Text style={{ ...type.body, color: colors.neutral700 }}>{x?.payment_failure_reason ?? t("slPaymentFailedBody")}</Text>
            </Card>
          ) : null}
          {x?.proposal_id ? (
            <Button variant="secondary" icon={FileSignature} label={t("slOpenProposal")} onPress={() => router.push("/agent/proposals")} />
          ) : null}
          {x?.policy_id ? (
            <Button variant="secondary" icon={FileText} label={t("slOpenPolicy")} onPress={() => router.push({ pathname: "/agent/policies/[id]", params: { id: String(x.policy_id ?? "") } })} />
          ) : issued ? (
            <Button variant="secondary" icon={FileText} label={t("slOpenPolicies")} onPress={() => router.push("/agent/policies")} />
          ) : null}
          <SectionTitle title={t("agCommissions")} />
          <Card>
            <DetailRow
              label={issued ? t("slCommissionAccrual") : t("slCommissionEstimated")}
              value={x ? `${money(x.commission_minor)}${x.commission_status ? ` · ${humanize(x.commission_status)}` : ""}` : null}
            />
            {issued ? (
              <Button variant="tertiary" icon={CircleDollarSign} label={t("pcLedger")} onPress={() => router.push("/agent/wallet")} />
            ) : null}
          </Card>
          {!paid ? (
            <Button
              label={failed ? t("slRetryPayment") : t("agSendPaymentRequest")}
              disabled={!x || x.payment_status === "PENDING_CLIENT"}
              onPress={async () => setX(await AgentApi.requestPayment(id))}
            />
          ) : null}
          <Text>
            {t("agPaymentConfirmedByBackend")}
          </Text>
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

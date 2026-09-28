import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { CircleDollarSign, FileSignature, FileText, Send } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentButton, AgentCard, AgentEmptyState, AgentNavRow, AgentSection, AgentShell, AgentSkeleton } from "@/components/agent";
import { KV, Timeline } from "@/components/partner/AgentEarningsUi";
import { AgentRawChip } from "@/components/partner/AgentListUi";
import { AgentApi, AgentSale } from "@/api/client";
import { humanize, money } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";

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

/** Assisted sale detail (spec v2 drill-down): status, lifecycle stepper, commission. */
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
  const requested = !!x && x.payment_status !== "NOT_REQUESTED";
  const flags = [!!x, requested, paid, issued];
  const current = flags.indexOf(false);
  const steps: Parameters<typeof Timeline>[0]["steps"] = [t("slQuoted"), t("slPaymentRequested"), t("slPaymentVerified"), t("slIssued")].map((label, i) => ({
    label,
    date: i === 2 && x?.payment_verified_at ? date(x.payment_verified_at, true) : null,
    state: flags[i] ? "done" : i === current ? (failed && i === 2 ? "failed" : "current") : "todo",
    note: failed && i === 2 ? x?.payment_failure_reason ?? t("slPaymentFailedBody") : null,
  }));
  const footer =
    x && !paid ? (
      <AgentButton
        icon={Send}
        label={failed ? t("slRetryPayment") : t("agSendPaymentRequest")}
        disabled={x.payment_status === "PENDING_CLIENT"}
        onPress={async () => setX(await AgentApi.requestPayment(id))}
      />
    ) : undefined;
  return (
    <AgentShell variant="drilldown" title={t("agAssistedSaleTitle")} footer={footer} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      {q.loading && !x ? (
        <AgentSkeleton rows={5} height={56} />
      ) : q.error && !x ? (
        <AgentEmptyState icon={FileSignature} title={t("loadErrorTitle")} body={t("loadErrorBody")} actionLabel={t("retry")} onAction={q.reload} />
      ) : x ? (
        <>
          <AgentCard style={s.hero}>
            <Text style={s.amount} numberOfLines={1} adjustsFontSizeToFit>{money(x.premium_minor)}</Text>
            <AgentRawChip raw={x.status} label={td(`saleStatus_${x.status}`, humanize(x.status))} tone={issued ? "success" : issuanceGap || failed ? "warning" : "info"} />
            <Text style={s.name}>{x.customer_name}</Text>
            <Text style={s.meta}>{x.product}</Text>
          </AgentCard>
          <AgentCard>
            <KV first label={t("slClientPhone")} value={x.payment_phone_e164} />
            <KV label={t("slPayment")} value={td(`paymentStatus_${x.payment_status}`, humanize(x.payment_status))} />
            {x.payment_verified_at ? <KV label={t("slVerifiedAt")} value={date(x.payment_verified_at, true)} /> : null}
          </AgentCard>
          <AgentSection title={t("slProgress")}>
            <AgentCard>
              <Timeline steps={steps} />
            </AgentCard>
          </AgentSection>
          {issuanceGap ? (
            <AgentCard style={s.gap}>
              <Text style={s.cardTitle}>{t("slIssuancePending")}</Text>
              <Text style={s.meta}>{t("slIssuancePendingBody")}</Text>
            </AgentCard>
          ) : null}
          {failed ? (
            <AgentCard tone="danger" style={s.gap}>
              <Text style={[s.cardTitle, { color: c.danger }]}>{t("slPaymentFailed")}</Text>
              <Text style={s.meta}>{x.payment_failure_reason ?? t("slPaymentFailedBody")}</Text>
            </AgentCard>
          ) : null}
          {x.proposal_id || x.policy_id || issued ? (
            <AgentCard padded={false}>
              {x.proposal_id ? <AgentNavRow divider={false} icon={FileSignature} title={t("slOpenProposal")} onPress={() => router.push("/agent/proposals")} /> : null}
              {x.policy_id ? (
                <AgentNavRow divider={!!x.proposal_id} icon={FileText} title={t("slOpenPolicy")} onPress={() => router.push({ pathname: "/agent/policies/[id]", params: { id: String(x.policy_id ?? "") } })} />
              ) : issued ? (
                <AgentNavRow divider={!!x.proposal_id} icon={FileText} title={t("slOpenPolicies")} onPress={() => router.push("/agent/policies")} />
              ) : null}
            </AgentCard>
          ) : null}
          <AgentSection title={t("agCommissions")}>
            <AgentCard padded={false}>
              <View style={s.commission}>
                <Text style={s.meta}>{issued ? t("slCommissionAccrual") : t("slCommissionEstimated")}</Text>
                <Text style={s.commissionAmount}>{money(x.commission_minor)}</Text>
                {x.commission_status ? <AgentRawChip raw={x.commission_status} label={humanize(x.commission_status)} /> : null}
              </View>
              {issued ? <AgentNavRow icon={CircleDollarSign} title={t("pcLedger")} onPress={() => router.push("/agent/wallet")} /> : null}
            </AgentCard>
          </AgentSection>
          <Text style={s.meta}>{t("agPaymentConfirmedByBackend")}</Text>
        </>
      ) : null}
    </AgentShell>
  );
}

const s = StyleSheet.create({
  hero: { gap: 8, alignItems: "flex-start" },
  amount: { ...T.heroAmount, color: c.heading },
  name: { ...T.cardTitle, color: c.text },
  meta: { ...T.secondary, color: c.secondary },
  cardTitle: { ...T.cardTitle, color: c.heading },
  gap: { gap: 6 },
  commission: { padding: L.cardPadding, gap: 6, alignItems: "flex-start" },
  commissionAmount: { ...T.sectionTitle, color: c.heading },
});

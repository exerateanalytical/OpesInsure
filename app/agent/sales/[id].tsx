import React, { useEffect, useRef, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { CircleDollarSign, FileSignature, FileText, RefreshCw, Send } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentButton, AgentCard, AgentEmptyState, AgentNavRow, AgentSection, AgentShell, AgentSkeleton } from "@/components/agent";
import { KV, Timeline } from "@/components/partner/AgentEarningsUi";
import { AgentRawChip } from "@/components/partner/AgentListUi";
import { OptionGroup } from "@/components/forms/OptionGroup";
import { NetworkTiles } from "@/components/policies/RenewalUi";
import { AgentApi } from "@/api/client";
import { humanize, money } from "@/api/partner";
import { errorMessage } from "@/lib/purchase";
import { Network, networkForPhone, SALE_PAYMENT_FAILED, saleButton, salePolls, saleProgress } from "@/lib/assistedSale";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";

const POLL_MS = 6000;

/**
 * Assisted sale detail: the real premium and offers, then ONE server-driven action (AssistedSaleService):
 * send the application to the client → the client accepts the terms in their app → the real mobile-money request
 * (operator prompt, the client approves with their own PIN) → verified payment → issuance. Every status shown is the
 * server's; the screen polls while the operator or the insurer is working, and repeated taps are harmless.
 */
export default function AgentSaleDetail() {
  const { t, td, date, language } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => AgentApi.sale(id), [id]);
  const x = q.data;
  const setX = q.setData;
  const [offerId, setOfferId] = useState<string | null>(null);
  const [network, setNetwork] = useState<Network | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const inFlight = useRef(false);

  const button = saleButton(x);
  const issued = x?.issuance_status === "ISSUED" || x?.status === "ISSUED";
  const paid = x?.payment_status === "PAID";
  const failed = !!x && SALE_PAYMENT_FAILED.includes(x.payment_status);
  const issuanceGap = paid && !issued;
  const chosenOffer = offerId ?? x?.selected_offer_id ?? null;
  const chosenNetwork: Network = network ?? (x?.payment_provider === "orange_money" || x?.payment_provider === "mtn_momo" ? x.payment_provider : networkForPhone(x?.payment_phone_e164) ?? "mtn_momo");

  // Quiet refresh while the operator (payment prompt) or the insurer (issuance) is working.
  const polling = salePolls(x);
  useEffect(() => {
    if (!polling) return;
    const timer = setInterval(() => {
      AgentApi.sale(id).then(setX).catch(() => undefined);
    }, POLL_MS);
    return () => clearInterval(timer);
  }, [polling, id, setX]);

  const act = async () => {
    if (inFlight.current || !x || !button) return;
    if (button.kind === "refresh") return void q.reload();
    inFlight.current = true;
    setBusy(true);
    setActionError(null);
    try {
      const body = x.next_action === "SEND_TO_CLIENT" ? (chosenOffer ? { offer_id: chosenOffer } : {}) : button.needsNetwork ? { provider: chosenNetwork } : {};
      setX(await AgentApi.requestPayment(id, body));
    } catch (e) {
      setActionError(errorMessage(e, t("slActionFailed"), language));
    } finally {
      inFlight.current = false;
      setBusy(false);
    }
  };

  const flags = saleProgress(x);
  const current = flags.indexOf(false);
  const steps: Parameters<typeof Timeline>[0]["steps"] = [t("slQuoted"), t("slSentToClient"), t("slClientAccepted"), t("slPaymentRequested"), t("slPaymentVerified"), t("slIssued")].map((label, i) => ({
    label,
    date: i === 4 && x?.payment_verified_at ? date(x.payment_verified_at, true) : null,
    state: flags[i] ? "done" : i === current ? (failed && i === 4 ? "failed" : "current") : "todo",
    note: failed && i === 4 ? x?.payment_failure_reason ?? t("slPaymentFailedBody") : null,
  }));

  const footer = button ? (
    <AgentButton icon={button.kind === "refresh" ? RefreshCw : Send} variant={button.kind === "refresh" ? "secondary" : "primary"} label={td(button.labelKey, t("retry"))} loading={busy || (button.kind === "refresh" && q.loading)} disabled={busy} onPress={() => void act()} />
  ) : undefined;
  const offers = x?.offers ?? [];
  const commissionLabel = x?.commission_basis === "ACCRUED" ? t("slCommissionAccrual") : x?.commission_basis === "RULE_ESTIMATE" ? t("slCommissionRule") : t("slCommissionNotConfigured");

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
            <Text style={s.meta}>{[td(`qtProd_${String(x.product).toLowerCase()}`, humanize(x.product)), x.carrier_name].filter(Boolean).join(" · ")}</Text>
          </AgentCard>
          {x.next_action && x.next_action !== "NONE" ? <Text style={s.meta}>{td(`slNext_${x.next_action}`, "")}</Text> : null}
          {x.next_action === "SEND_TO_CLIENT" && offers.length > 1 ? (
            <AgentCard>
              <OptionGroup
                label={t("slChooseOffer")}
                value={chosenOffer ?? undefined}
                options={offers.map((o) => ({ value: o.id, label: [o.carrier_name, money(o.total_minor)].filter(Boolean).join(" · ") }))}
                onChange={setOfferId}
              />
            </AgentCard>
          ) : null}
          {button?.needsNetwork ? (
            <AgentCard style={s.gap}>
              <Text style={s.meta}>{t("slClientNetwork")}</Text>
              <NetworkTiles value={chosenNetwork} onChange={setNetwork} disabled={busy} />
            </AgentCard>
          ) : null}
          <AgentCard>
            <KV first label={t("slClientPhone")} value={x.payment_phone_e164} />
            {x.proposal_number ? <KV label={t("slApplication")} value={x.proposal_number} /> : null}
            <KV label={t("slPayment")} value={td(`salePayment_${x.payment_status}`, humanize(x.payment_status))} />
            {x.payment_verified_at ? <KV label={t("slVerifiedAt")} value={date(x.payment_verified_at, true)} /> : null}
            {x.policy_number ? <KV label={t("slIssued")} value={x.policy_number} /> : null}
          </AgentCard>
          {actionError ? (
            <Text accessibilityRole="alert" style={s.error}>
              {actionError}
            </Text>
          ) : null}
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
              <Text style={s.meta}>{x.payment_failure_reason ? humanize(x.payment_failure_reason) : t("slPaymentFailedBody")}</Text>
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
                <Text style={s.meta}>{commissionLabel}</Text>
                {x.commission_minor != null ? <Text style={s.commissionAmount}>{money(x.commission_minor)}</Text> : null}
                {x.commission_status ? <AgentRawChip raw={x.commission_status} label={humanize(x.commission_status)} /> : null}
              </View>
              {issued ? <AgentNavRow icon={CircleDollarSign} title={t("pcLedger")} onPress={() => router.push("/agent/wallet")} /> : null}
            </AgentCard>
          </AgentSection>
          <Text style={s.meta}>{t("agNeverPin")}</Text>
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
  error: { ...T.body, color: c.danger },
  gap: { gap: 6 },
  commission: { padding: L.cardPadding, gap: 6, alignItems: "flex-start" },
  commissionAmount: { ...T.sectionTitle, color: c.heading },
});

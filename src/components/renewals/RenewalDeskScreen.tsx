import React, { useRef, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ContactRound, FileText, RefreshCw, Send, UserX } from "lucide-react-native";
import { AgentButton, AgentCard, AgentEmptyState, AgentNavRow, AgentSection, AgentShell, AgentSkeleton } from "@/components/agent";
import { KV, Timeline } from "@/components/partner/AgentEarningsUi";
import { AgentRawChip } from "@/components/partner/AgentListUi";
import { TextField } from "@/components/ui";
import { renewalDeskApi, type RenewalDesk, type SalePortal } from "@/api/client";
import { humanize, money } from "@/api/partner";
import { useLoad } from "@/hooks/useLoad";
import { errorMessage } from "@/lib/purchase";
import { declineReasonValid, deskActions, deskTone } from "@/lib/renewalDesk";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentLayout as L, agentType as T } from "@/theme/agent";

/**
 * Renewal desk of one expiring policy (id = policy id), shared by the broker and agent portals
 * (GET/POST mobile/{portal}/renewals/{policy}…): re-quote on the policy's current details (RenewalService), then the
 * renewal quote is the seller's assisted sale (send the offer → the client accepts the terms → payment request →
 * the insurer issues the new policy and the case closes by itself), or record the client's decision not to renew.
 * Each action asks for confirmation first; every status shown is the server's.
 */
export function RenewalDeskScreen({ portal }: { portal: SalePortal }) {
  const { t, td, date, language } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => renewalDeskApi(portal).show(id), [portal, id]);
  const r = q.data;
  const [confirm, setConfirm] = useState<"requote" | "decline" | null>(null);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const inFlight = useRef(false);
  const actions = deskActions(r);

  const run = async (call: () => Promise<RenewalDesk>) => {
    if (inFlight.current) return;
    inFlight.current = true;
    setBusy(true);
    setError(null);
    try {
      q.setData(await call());
      setConfirm(null);
      setReason("");
    } catch (e) {
      setError(errorMessage(e, t("rdActionFailed"), language));
    } finally {
      inFlight.current = false;
      setBusy(false);
    }
  };
  const openOffer = (saleId: string) => router.push({ pathname: `/${portal}/sales/[id]` as never, params: { id: saleId } });

  const footer =
    confirm === "requote" ? (
      <View style={s.footerRow}>
        <AgentButton variant="secondary" label={t("cancel")} disabled={busy} onPress={() => setConfirm(null)} style={s.flex} />
        <AgentButton icon={RefreshCw} label={t("rdConfirm")} loading={busy} onPress={() => void run(() => renewalDeskApi(portal).requote(id))} style={s.flex} />
      </View>
    ) : confirm === "decline" ? (
      <View style={s.footerRow}>
        <AgentButton variant="secondary" label={t("cancel")} disabled={busy} onPress={() => setConfirm(null)} style={s.flex} />
        <AgentButton variant="danger" icon={UserX} label={t("rdDeclineConfirm")} loading={busy} disabled={!declineReasonValid(reason)} onPress={() => void run(() => renewalDeskApi(portal).decline(id, reason.trim()))} style={s.flex} />
      </View>
    ) : actions.openOffer && r?.sale_id ? (
      <AgentButton icon={Send} label={t("rdOpenOffer")} onPress={() => openOffer(String(r.sale_id))} />
    ) : actions.requote ? (
      <AgentButton icon={RefreshCw} label={t("rdRequote")} onPress={() => setConfirm("requote")} />
    ) : undefined;

  return (
    <AgentShell portal={portal} variant="drilldown" title={t("rdTitle")} hideNav footer={footer} refreshing={q.loading && !!r} onRefresh={q.reload}>
      {q.loading && !r ? (
        <AgentSkeleton rows={4} height={56} />
      ) : q.error && !r ? (
        <AgentEmptyState icon={RefreshCw} title={t("loadErrorTitle")} body={t("loadErrorBody")} actionLabel={t("retry")} onAction={q.reload} />
      ) : r ? (
        <>
          <AgentCard style={s.hero}>
            <Text style={s.name}>{r.customer_name}</Text>
            <AgentRawChip raw={r.status} label={td(`renewalStatus_${r.status}`, humanize(r.status))} tone={deskTone(r.status)} />
            <Text style={s.meta}>
              {[r.policy_number, r.expires_at ? date(r.expires_at) : null, r.days_remaining != null ? (r.days_remaining < 0 ? t("pdExpiredAgo", { days: -r.days_remaining }) : t("pdDaysLeft", { days: r.days_remaining })) : null]
                .filter(Boolean)
                .join(" · ")}
            </Text>
          </AgentCard>
          <AgentCard>
            <KV first label={t("rdCurrentPremium")} value={r.premium_minor ? money(r.premium_minor) : null} />
            {r.renewal_premium_minor != null ? <KV strong label={t("rdRenewalPrice")} value={money(r.renewal_premium_minor)} /> : null}
            {r.renewal_quote_number ? <KV label={t("qwNumber")} value={r.renewal_quote_number} /> : null}
            {r.successor_policy_number ? <KV strong label={t("rdRenewedAs")} value={r.successor_policy_number} /> : null}
          </AgentCard>

          {confirm === "requote" ? (
            <AgentCard style={s.gap}>
              <Text style={s.cardTitle}>{t("rdRequote")}</Text>
              <Text style={s.meta}>{t("rdRequoteConfirm")}</Text>
            </AgentCard>
          ) : confirm === "decline" ? (
            <AgentCard style={s.gap}>
              <Text style={s.cardTitle}>{t("rdDecline")}</Text>
              <TextField label={t("rdDeclineReason")} value={reason} onChangeText={setReason} multiline maxLength={500} />
            </AgentCard>
          ) : null}
          {error ? (
            <Text accessibilityRole="alert" style={s.error}>
              {error}
            </Text>
          ) : null}

          {!confirm && (actions.openOffer || actions.requote || actions.decline) ? (
            <AgentSection title={t("bkNextActions")}>
              <AgentCard padded={false}>
                {actions.openOffer && r.sale_id ? <AgentNavRow divider={false} icon={Send} title={t("rdOpenOffer")} subtitle={t("rdOpenOfferSub")} onPress={() => openOffer(String(r.sale_id))} /> : null}
                {actions.requote ? <AgentNavRow divider={actions.openOffer} icon={RefreshCw} title={t("rdRequote")} subtitle={t("rdRequoteSub")} onPress={() => setConfirm("requote")} /> : null}
                {actions.decline ? (
                  <AgentNavRow divider={actions.openOffer || actions.requote} icon={UserX} iconTone="danger" title={t("rdDecline")} subtitle={t("rdDeclineSub")} onPress={() => setConfirm("decline")} />
                ) : null}
              </AgentCard>
            </AgentSection>
          ) : !confirm ? (
            <Text style={s.meta}>{t("rdNoActions")}</Text>
          ) : null}
          {actions.openOffer ? <Text style={s.meta}>{t("rdIssuanceNote")}</Text> : null}

          <AgentSection title={t("bkRelated")}>
            <AgentCard padded={false}>
              <AgentNavRow divider={false} icon={FileText} title={t("bkPolicy")} subtitle={r.policy_number} onPress={() => router.push({ pathname: `/${portal}/policies/[id]` as never, params: { id: r.policy_id } })} />
              {r.successor_policy_id ? (
                <AgentNavRow icon={FileText} title={t("rdRenewedAs")} subtitle={r.successor_policy_number} onPress={() => router.push({ pathname: `/${portal}/policies/[id]` as never, params: { id: String(r.successor_policy_id) } })} />
              ) : null}
              {r.customer_id ? (
                <AgentNavRow icon={ContactRound} title={t("agClient")} subtitle={r.customer_name} onPress={() => router.push({ pathname: `/${portal}/clients/[id]` as never, params: { id: String(r.customer_id) } })} />
              ) : null}
            </AgentCard>
          </AgentSection>

          {r.events?.length ? (
            <AgentSection title={t("rdHistory")}>
              <AgentCard>
                <Timeline steps={r.events.map((e) => ({ label: td(`rdEvent_${e.action}`, humanize(e.action)), date: date(e.occurred_at, true), state: e.to_status === "LAPSED" || e.action === "ISSUANCE_FAILED" ? "failed" : "done" }))} />
              </AgentCard>
            </AgentSection>
          ) : null}
        </>
      ) : null}
    </AgentShell>
  );
}

const s = StyleSheet.create({
  hero: { gap: 8, alignItems: "flex-start" },
  name: { ...T.cardTitle, color: c.heading },
  meta: { ...T.secondary, color: c.secondary },
  cardTitle: { ...T.cardTitle, color: c.heading },
  error: { ...T.body, color: c.danger },
  gap: { gap: L.subsectionGap },
  flex: { flex: 1 },
  footerRow: { flexDirection: "row", gap: 12 },
});

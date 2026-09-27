import React, { useEffect, useState } from "react";
import * as RN from "react-native";
import { Pressable, Share, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { type LucideIcon, ArrowRight, Building2, Calendar, CarFront, CheckCircle2, CircleCheck, Coins, Copy, CreditCard, Download, FileText, Headset, RefreshCcw, Share2, Shield, ShieldCheck, User, Wallet } from "lucide-react-native";
import { Button, Card, Screen, StatusChip, ripple } from "@/components/ui";
import { allowedAction } from "@/lib/capabilities";
import { Banner, BrandHeader, IconTile, SectionHeading, TintedIcon } from "@/components/design";
import { InstitutionMark } from "@/components/InstitutionMark";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ProviderNotConfigured } from "@/components/purchase/ProviderNotConfigured";
import { PaymentsApi, WalletApi, WalletPolicy } from "@/api/client";
import { Institution, InstitutionsApi } from "@/api/extra";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useSession } from "@/store/session";
import { networkName, isProviderNotConfigured, paymentStatusInfo } from "@/lib/purchase";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/** Clipboard was extracted from RN core; use it when the host still ships it, otherwise fall back to the share sheet. */
function copyText(text: string): boolean {
  const clipboard = (RN as unknown as { Clipboard?: { setString?: (s: string) => void } }).Clipboard;
  if (clipboard?.setString) {
    try {
      clipboard.setString(text);
      return true;
    } catch {
      /* fall through */
    }
  }
  void Share.share({ message: text }).catch(() => undefined);
  return false;
}

export default function PaymentDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const f = useFormatters();
  const { t } = useTranslation();
  const customerName = useSession((s) => s.bootstrap?.user.full_name ?? null);
  const { data: p, setData, loading, error, reload } = useLoad(() => PaymentsApi.show(id), [id]);
  const [retrying, setRetrying] = useState(false);
  const [retryError, setRetryError] = useState<unknown>(null);
  const [policy, setPolicy] = useState<WalletPolicy | null>(null);
  const [insurer, setInsurer] = useState<Institution | null>(null);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!p) return;
    let live = true;
    // Payment rows carry policy_id only on newer builds; the receipt resolves it from the proposal.
    const policyId = p.policy_id
      ? Promise.resolve(p.policy_id)
      : p.status === "SUCCEEDED"
        ? PaymentsApi.receipt(p.id).then((r) => (r as unknown as { policy_id?: string | null }).policy_id ?? null).catch(() => null)
        : Promise.resolve(null);
    void policyId.then((pid) => {
      if (!pid || !live) return;
      return WalletApi.policy(pid)
      .then((x) => {
        if (!live) return;
        setPolicy(x);
        if (x.carrier_id) InstitutionsApi.show(x.carrier_id).then((i) => live && setInsurer(i)).catch(() => undefined);
      })
      .catch(() => undefined);
    });
    return () => {
      live = false;
    };
  }, [p]);

  const retry = async () => {
    if (retrying) return;
    setRetrying(true);
    setRetryError(null);
    try {
      setData(await PaymentsApi.retry(id));
    } catch (e) {
      setRetryError(e);
    } finally {
      setRetrying(false);
    }
  };

  const info = paymentStatusInfo(p?.status, f.language);
  const succeeded = p?.status === "SUCCEEDED";
  const pending = !!p && ["PENDING_CUSTOMER", "PROCESSING", "CREATED"].includes(p.status);
  const bannerTint = info.tone === "success" ? "green" : info.tone === "danger" ? "red" : info.tone === "warning" ? "gold" : "blue";
  const reference = p?.provider_reference || p?.id || "";
  const fee = (p as unknown as { fee_minor?: number | null } | null)?.fee_minor ?? null;
  const productName = policy?.product_name ?? null;
  const providerName = policy?.carrier_name ?? null;

  const copy = () => {
    if (!reference) return;
    setCopied(copyText(reference));
    setTimeout(() => setCopied(false), 2000);
  };
  const share = () =>
    p &&
    void Share.share({
      message: t("pmShareMessage", { reference, amount: f.xaf(p.amount_minor), date: f.dateTime(p.updated_at ?? p.created_at), status: info.label }),
    }).catch(() => undefined);

  return (
    <Screen>
      <BrandHeader title={t("pmTransactionTitle")} right={null} />
      {loading && !p ? <LoadingState label={t("pmLoading")} /> : null}
      {error && !p ? <ErrorCard error={error} fallback={t("pmLoadFailed")} onRetry={() => void reload()} /> : null}
      {p ? (
        <>
          <Banner
            icon={succeeded ? CheckCircle2 : pending ? RefreshCcw : Wallet}
            tint={bannerTint}
            title={succeeded ? t("pmSuccessTitle") : info.label}
            body={succeeded ? t("pmSuccessBody") : pending ? t("pmPendingBody") : info.tone === "danger" ? t("pmFailedBody") : t("pmStatus")}
          />

          <Card>
            <View style={st.idRow}>
              <View style={st.idMain}>
                <Text style={st.idLabel}>{t("pmTransactionId")}</Text>
                <Text selectable style={st.idValue}>{reference}</Text>
              </View>
              <Pressable accessibilityRole="button" accessibilityLabel={t("pmCopy")} onPress={copy} android_ripple={ripple()} style={({ pressed }) => [st.copyBtn, pressed && st.pressed]}>
                <Copy size={18} color={colors.blue600} />
                <Text style={st.copyText}>{copied ? t("pmCopied") : t("pmCopy")}</Text>
              </Pressable>
            </View>
            <View style={st.rows}>
              {providerName || policy ? (
                <TxRow
                  icon={Building2}
                 
                  label={t("pmProvider")}
                  valueNode={
                    <View style={st.providerCell}>
                      <InstitutionMark logoUrl={insurer?.logo_url ?? null} initials={(providerName ?? "").slice(0, 2).toUpperCase()} size={24} />
                      <Text style={st.value}>{providerName ?? "—"}</Text>
                    </View>
                  }
                />
              ) : null}
              {productName ? <TxRow icon={Shield} label={t("pmProduct")} value={productName} /> : null}
              {policy?.policy_number ? <TxRow icon={FileText} label={t("pdPolicyNumber")} value={policy.policy_number} /> : null}
              {customerName ? <TxRow icon={User} label={t("pmCustomer")} value={customerName} /> : null}
              {p.created_at ? <TxRow icon={Calendar} label={t("pmDateTime")} value={f.dateTime(p.created_at)} /> : null}
              <TxRow icon={Coins} label={t("pmAmountPaid")} value={f.xaf(p.amount_minor)} strong />
              {fee !== null ? (
                <TxRow icon={Coins} label={t("pmFee")} valueNode={fee ? <Text style={st.value}>{f.xaf(fee)}</Text> : <StatusChip label={t("pmNoFee")} tone="success" />} />
              ) : null}
              <TxRow icon={CreditCard} label={t("pmMethod")} value={`${networkName(p.provider)}${p.payer_phone_e164 ? ` · ${p.payer_phone_e164}` : ""}`} />
              {p.provider_reference && p.provider_reference !== reference ? <TxRow icon={FileText} label={t("pmOperatorRef")} value={p.provider_reference} /> : null}
              {succeeded && p.updated_at ? <TxRow icon={CircleCheck} label={t("pmConfirmed")} value={f.dateTime(p.updated_at)} /> : null}
              <TxRow icon={CircleCheck} label={t("pmStatus")} valueNode={<StatusChip label={info.label} tone={info.tone} />} />
            </View>
          </Card>

          {retryError ? isProviderNotConfigured(retryError) ? <ProviderNotConfigured error={retryError} /> : <ErrorCard error={retryError} fallback={t("pmRetryFailed")} /> : null}
          {allowedAction(p, "retry", p.status === "FAILED") ? <Button label={t("pmRetry")} loading={retrying} onPress={() => void retry()} /> : null}
          {pending ? <Button label={t("pmRefresh")} icon={RefreshCcw} variant="secondary" loading={loading} onPress={() => void reload()} /> : null}

          {policy ? (
            <View style={st.section}>
              <SectionHeading title={t("pmRelatedPolicy")} />
              <Card>
                <View style={st.policyRow}>
                  <TintedIcon icon={policy.risk_asset || /motor|auto/i.test(policy.product_name ?? "") ? CarFront : ShieldCheck} tint="blue" size={56} />
                  <View style={st.flex}>
                    <Text style={st.policyTitle}>{policy.product_name ?? policy.policy_number}</Text>
                    {providerName ? <Text style={st.policyMeta}>{providerName}</Text> : null}
                    <Text style={st.policyMeta}>{policy.policy_number}</Text>
                    <Pressable accessibilityRole="button" accessibilityLabel={t("viewPolicy")} onPress={() => router.push({ pathname: "/policy/[id]", params: { id: policy.id } })} android_ripple={ripple()} style={({ pressed }) => [st.viewBtn, pressed && st.pressed]}>
                      <Text style={st.viewBtnText}>{t("viewPolicy")}</Text>
                      <ArrowRight size={16} color={colors.blue600} />
                    </Pressable>
                  </View>
                </View>
              </Card>
            </View>
          ) : null}

          {succeeded ? (
            <>
              <Banner icon={FileText} tint="blue" title={t("pmReceiptTitle")} body={t("pmReceiptBody")} onPress={() => router.push({ pathname: "/payments/[id]/receipt", params: { id } })} />
              <View style={st.tiles}>
                <IconTile icon={Download} label={t("pmDownloadReceipt")} tint="blue" onPress={() => router.push({ pathname: "/payments/[id]/receipt", params: { id } })} />
                <IconTile icon={Share2} label={t("pmShare")} tint="neutral" onPress={share} />
                <IconTile icon={Headset} label={t("contactSupport")} tint="neutral" onPress={() => router.push("/support/new")} />
              </View>
              <Button label={t("pmViewReceipt")} variant="secondary" onPress={() => router.push({ pathname: "/payments/[id]/receipt", params: { id } })} />
              {allowedAction(p, "refund", true) ? <Button label={t("pmRefund")} variant="tertiary" onPress={() => router.push({ pathname: "/payments/[id]/refund", params: { id } })} /> : null}
            </>
          ) : (
            <View style={st.tiles}>
              <IconTile icon={Share2} label={t("pmShare")} tint="neutral" onPress={share} />
              <IconTile icon={Headset} label={t("contactSupport")} tint="neutral" onPress={() => router.push("/support/new")} />
            </View>
          )}
          {p.proposal_id ? <Button label={t("coOpenApplication")} variant="tertiary" onPress={() => router.push({ pathname: "/proposals/[id]", params: { id: p.proposal_id } })} /> : null}
        </>
      ) : null}
    </Screen>
  );
}


/** Transaction line: tinted icon, small label above a full-width value (long IDs never squeeze the label). */
function TxRow({ icon: Icon, label, value, valueNode, strong }: { icon: LucideIcon; label: string; value?: string | null; valueNode?: React.ReactNode; strong?: boolean }) {
  return (
    <View style={st.txRow}>
      <TintedIcon icon={Icon} tint="blue" size={40} />
      <View style={st.txCopy}>
        <Text style={st.txLabel}>{label}</Text>
        {valueNode ?? <Text style={[st.txValue, strong && st.txStrong]}>{value ?? "—"}</Text>}
      </View>
    </View>
  );
}

const st = StyleSheet.create({
  txRow: { flexDirection: "row", alignItems: "center", gap: space.x3, paddingVertical: space.x2, borderBottomWidth: 1, borderBottomColor: colors.neutral100 },
  txCopy: { flex: 1, gap: 2, alignItems: "flex-start" },
  txLabel: { ...type.meta, color: colors.neutral600 },
  txValue: { ...type.label, color: colors.navy950 },
  txStrong: { fontFamily: "Inter_700Bold", fontSize: 18, lineHeight: 24 },
  idMain: { flexGrow: 1, flexShrink: 1, flexBasis: 170 },
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  section: { gap: space.x3 },
  idRow: { flexDirection: "row", flexWrap: "wrap", alignItems: "center", gap: space.x3 },
  idLabel: { ...type.body, color: colors.neutral600 },
  idValue: { fontFamily: "Inter_700Bold", fontSize: 18, lineHeight: 24, color: colors.navy950, marginTop: 2 },
  copyBtn: { flexDirection: "row", alignItems: "center", gap: 6, backgroundColor: colors.blue50, borderRadius: radius.control, paddingHorizontal: 14, minHeight: 44, overflow: "hidden" },
  copyText: { ...type.label, color: colors.blue600 },
  rows: { gap: 0, borderTopWidth: 1, borderTopColor: colors.neutral200, paddingTop: space.x2 },
  value: { ...type.label, color: colors.navy950, flexShrink: 1 },
  providerCell: { flexDirection: "row", alignItems: "center", gap: 6 },
  policyRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  policyTitle: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.navy950 },
  policyMeta: { ...type.meta, color: colors.neutral600, marginTop: 2 },
  viewBtn: { alignSelf: "flex-start", marginTop: space.x2, flexDirection: "row", alignItems: "center", gap: 4, backgroundColor: colors.blue50, borderRadius: radius.control, paddingHorizontal: 12, minHeight: 40, overflow: "hidden" },
  viewBtnText: { ...type.label, color: colors.blue600 },
  tiles: { flexDirection: "row", gap: space.x2 },
});

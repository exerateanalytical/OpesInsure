import React, { useEffect, useState } from "react";
import * as RN from "react-native";
import { Pressable, Share, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, Building2, Calendar, Car, CheckCircle2, CircleCheck, Coins, Copy, CreditCard, Download, FileText, Headset, RefreshCcw, Share2, Shield, ShieldCheck, User, Wallet } from "lucide-react-native";
import { Button, Card, Screen, StatusChip, ripple } from "@/components/ui";
import { Banner, BrandHeader, DetailRow, IconTile, SectionHeading, TintedIcon } from "@/components/design";
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
              <View style={st.flex}>
                <Text style={st.idLabel}>{t("pmTransactionId")}</Text>
                <Text selectable style={st.idValue} numberOfLines={2}>{reference}</Text>
              </View>
              <Pressable accessibilityRole="button" accessibilityLabel={t("pmCopy")} onPress={copy} android_ripple={ripple()} style={({ pressed }) => [st.copyBtn, pressed && st.pressed]}>
                <Copy size={18} color={colors.blue600} />
                <Text style={st.copyText}>{copied ? t("pmCopied") : t("pmCopy")}</Text>
              </Pressable>
            </View>
            <View style={st.rows}>
              {providerName || policy ? (
                <DetailRow
                  icon={Building2}
                  tint="blue"
                  label={t("pmProvider")}
                  valueNode={
                    <View style={st.providerCell}>
                      <InstitutionMark logoUrl={insurer?.logo_url ?? null} initials={(providerName ?? "").slice(0, 2).toUpperCase()} size={24} />
                      <Text style={st.value} numberOfLines={2}>{providerName ?? "—"}</Text>
                    </View>
                  }
                />
              ) : null}
              {productName ? <DetailRow icon={Shield} tint="blue" label={t("pmProduct")} value={productName} /> : null}
              {policy?.policy_number ? <DetailRow icon={FileText} tint="blue" label={t("pdPolicyNumber")} value={policy.policy_number} /> : null}
              {customerName ? <DetailRow icon={User} tint="blue" label={t("pmCustomer")} value={customerName} /> : null}
              {p.created_at ? <DetailRow icon={Calendar} tint="blue" label={t("pmDateTime")} value={f.dateTime(p.created_at)} /> : null}
              <DetailRow icon={Coins} tint="blue" label={t("pmAmountPaid")} value={f.xaf(p.amount_minor)} strong />
              {fee !== null ? (
                <DetailRow icon={Coins} tint="blue" label={t("pmFee")} valueNode={fee ? <Text style={st.value}>{f.xaf(fee)}</Text> : <StatusChip label={t("pmNoFee")} tone="success" />} />
              ) : null}
              <DetailRow icon={CreditCard} tint="blue" label={t("pmMethod")} value={`${networkName(p.provider)}${p.payer_phone_e164 ? ` · ${p.payer_phone_e164}` : ""}`} />
              {p.provider_reference && p.provider_reference !== reference ? <DetailRow icon={FileText} tint="blue" label={t("pmOperatorRef")} value={p.provider_reference} /> : null}
              {succeeded && p.updated_at ? <DetailRow icon={CircleCheck} tint="blue" label={t("pmConfirmed")} value={f.dateTime(p.updated_at)} /> : null}
              <DetailRow icon={CircleCheck} tint="blue" label={t("pmStatus")} valueNode={<StatusChip label={info.label} tone={info.tone} />} />
            </View>
          </Card>

          {retryError ? isProviderNotConfigured(retryError) ? <ProviderNotConfigured error={retryError} /> : <ErrorCard error={retryError} fallback={t("pmRetryFailed")} /> : null}
          {p.status === "FAILED" ? <Button label={t("pmRetry")} loading={retrying} onPress={() => void retry()} /> : null}
          {pending ? <Button label={t("pmRefresh")} icon={RefreshCcw} variant="secondary" loading={loading} onPress={() => void reload()} /> : null}

          {policy ? (
            <View style={st.section}>
              <SectionHeading title={t("pmRelatedPolicy")} />
              <Card>
                <View style={st.policyRow}>
                  <TintedIcon icon={policy.risk_asset || /motor|auto/i.test(policy.product_name ?? "") ? Car : ShieldCheck} tint="blue" size={56} />
                  <View style={st.flex}>
                    <Text style={st.policyTitle} numberOfLines={2}>{policy.product_name ?? policy.policy_number}</Text>
                    {providerName ? <Text style={st.policyMeta} numberOfLines={1}>{providerName}</Text> : null}
                    <Text style={st.policyMeta} numberOfLines={1}>{policy.policy_number}</Text>
                  </View>
                  <Pressable accessibilityRole="button" accessibilityLabel={t("viewPolicy")} onPress={() => router.push({ pathname: "/policy/[id]", params: { id: policy.id } })} android_ripple={ripple()} style={({ pressed }) => [st.viewBtn, pressed && st.pressed]}>
                    <Text style={st.viewBtnText}>{t("viewPolicy")}</Text>
                    <ArrowRight size={16} color={colors.blue600} />
                  </Pressable>
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
              <Button label={t("pmRefund")} variant="tertiary" onPress={() => router.push({ pathname: "/payments/[id]/refund", params: { id } })} />
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


const st = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  section: { gap: space.x3 },
  idRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  idLabel: { ...type.body, color: colors.neutral600 },
  idValue: { fontFamily: "Inter_700Bold", fontSize: 20, lineHeight: 26, color: colors.navy950, marginTop: 2 },
  copyBtn: { flexDirection: "row", alignItems: "center", gap: 6, backgroundColor: colors.blue50, borderRadius: radius.control, paddingHorizontal: 14, minHeight: 44, overflow: "hidden" },
  copyText: { ...type.label, color: colors.blue600 },
  rows: { gap: space.x2, borderTopWidth: 1, borderTopColor: colors.neutral200, paddingTop: space.x2 },
  value: { ...type.body, color: colors.navy950, textAlign: "right", flexShrink: 1 },
  providerCell: { flexDirection: "row", alignItems: "center", gap: 6, justifyContent: "flex-end" },
  policyRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  policyTitle: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.navy950 },
  policyMeta: { ...type.meta, color: colors.neutral600, marginTop: 2 },
  viewBtn: { flexDirection: "row", alignItems: "center", gap: 4, backgroundColor: colors.blue50, borderRadius: radius.control, paddingHorizontal: 12, minHeight: 40, overflow: "hidden" },
  viewBtnText: { ...type.label, color: colors.blue600 },
  tiles: { flexDirection: "row", gap: space.x2 },
});

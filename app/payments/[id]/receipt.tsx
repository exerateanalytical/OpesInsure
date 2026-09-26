import React, { useState } from "react";
import { Pressable, Share, StyleSheet, Text, View } from "react-native";
import { openDocumentUrl } from "@/components/documents/openDocument";
import { router, useLocalSearchParams } from "expo-router";
import { AlertTriangle, Building2, CalendarDays, CheckCircle2, CreditCard, Download, FileText, Headset, Receipt as ReceiptIcon, Share2, ShieldCheck, User } from "lucide-react-native";
import { Card, Screen, StatusChip, ripple } from "@/components/ui";
import { Banner, BrandHeader, DetailRow } from "@/components/design";
import { InstitutionMark } from "@/components/InstitutionMark";
import { LoadingState } from "@/components/StatePanel";
import { ErrorCard, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { useInsurerLogo } from "@/components/offers/useInsurerLogo";
import { PaymentsApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { humanize, networkName, receiptDetails, receiptView } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Payment receipt (design payment_successful_receipt_ui): success banner,
 * amount + status, the receipt's own fields from GET /mobile/payments/{id}/receipt,
 * then Download PDF (in-app viewer, signed URL), Share and Report a problem.
 */
export default function Receipt() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const f = useFormatters();
  const { t, td } = useTranslation();
  const { data, loading, error, reload } = useLoad(() => PaymentsApi.receipt(id), [id]);
  const [openError, setOpenError] = useState<string | null>(null);
  const raw = data as unknown as Record<string, unknown> | null;
  const r = data ? receiptView(raw) : null;
  const d = receiptDetails(raw, f.language);
  const logo = useInsurerLogo(d.carrierId, d.carrierName, d.carrierLogo);
  const paid = !r?.status || r.status === "SUCCEEDED";

  const openPdf = async () => {
    setOpenError(null);
    if (!r?.downloadUrl) return;
    try {
      openDocumentUrl(r.downloadUrl, t("rcTitle"), r.number && r.number !== "—" ? `receipt-${r.number}` : undefined);
    } catch {
      setOpenError(t("rcPdfFailed"));
    }
  };
  const share = () =>
    r &&
    void Share.share({
      message: t("rcShareMessage", { number: r.number, amount: f.xaf(r.amountMinor), date: f.dateTime(r.issuedAt) }),
      ...(r.downloadUrl ? { url: r.downloadUrl } : {}),
    }).catch(() => undefined);

  return (
    <Screen>
      <BrandHeader title={t("rcTitle")} back right={null} />
      {loading && !data ? <LoadingState label={t("rcLoading")} /> : null}
      {error && !data ? <ErrorCard error={error} fallback={t("rcLoadFailed")} onRetry={() => void reload()} /> : null}
      {r ? (
        <>
          {paid ? <Banner icon={CheckCircle2} tint="green" title={t("pmSuccessTitle")} body={t("pmSuccessBody")} /> : null}

          <Card>
            <View style={st.amountRow}>
              <View style={st.flex}>
                <Text style={st.amountLabel}>{t("pmAmountPaid")}</Text>
                <Text style={st.amount} accessibilityLabel={f.xaf(r.amountMinor)}>{f.xaf(r.amountMinor)}</Text>
              </View>
              <View style={st.amountRight}>
                <StatusChip label={paid ? t("rcPaid") : td(`status_${r.status}`, humanize(r.status))} tone={paid ? "success" : "warning"} />
                {r.issuedAt ? <Text style={ps.meta}>{f.dateTime(r.issuedAt)}</Text> : null}
              </View>
            </View>
          </Card>

          <Card>
            <View style={st.rows}>
              {d.carrierName ? (
                <DetailRow
                  icon={Building2}
                  tint="blue"
                  label={t("pmProvider")}
                  valueNode={
                    <View style={st.providerCell}>
                      <InstitutionMark logoUrl={logo} initials={d.carrierName.slice(0, 2).toUpperCase()} size={28} />
                      <Text style={st.value}>{d.carrierName}</Text>
                    </View>
                  }
                />
              ) : null}
              {d.policyNumber ? <DetailRow icon={ShieldCheck} tint="blue" label={t("pdPolicyNumber")} value={d.policyNumber} /> : null}
              {d.productName ? <DetailRow icon={FileText} tint="blue" label={t("rcPurpose")} value={d.productName} /> : null}
              {d.reference ? <DetailRow icon={FileText} tint="blue" label={t("pmTransactionId")} value={d.reference} /> : null}
              <DetailRow icon={ReceiptIcon} tint="blue" label={t("rcNumber")} value={r.number} />
              {r.provider ? <DetailRow icon={CreditCard} tint="blue" label={t("pmMethod")} value={`${networkName(r.provider)}${r.payer ? ` · ${r.payer}` : ""}`} /> : null}
              {d.payerName ? <DetailRow icon={User} tint="blue" label={t("rcPaidFrom")} value={d.payerName} /> : r.payer && !r.provider ? <DetailRow icon={User} tint="blue" label={t("rcPaidFrom")} value={r.payer} /> : null}
              <DetailRow icon={CalendarDays} tint="blue" label={t("rcIssued")} value={r.issuedAt ? f.dateTime(r.issuedAt) : "—"} />
            </View>
            <Text style={ps.meta}>{t("rcNotCover")}</Text>
          </Card>

          {!r.downloadUrl ? <Text style={ps.meta}>{t("rcNoPdf")}</Text> : null}
          {openError ? <Text accessibilityRole="alert" style={ps.error}>{openError}</Text> : null}
          <View style={st.actions}>
            <Pressable accessibilityRole="button" accessibilityLabel={t("rcOpenPdf")} disabled={!r.downloadUrl} onPress={() => void openPdf()} android_ripple={ripple(true)} style={({ pressed }) => [st.action, st.primary, !r.downloadUrl && st.disabled, pressed && st.pressed]}>
              <Download size={20} color={colors.white} />
              <Text style={[st.actionText, st.primaryText]}>{t("rcDownloadPdf")}</Text>
            </Pressable>
            <Pressable accessibilityRole="button" accessibilityLabel={t("rcShare")} onPress={share} android_ripple={ripple()} style={({ pressed }) => [st.action, st.secondary, pressed && st.pressed]}>
              <Share2 size={20} color={colors.blue600} />
              <Text style={[st.actionText, st.secondaryText]}>{t("rcShareShort")}</Text>
            </Pressable>
            <Pressable accessibilityRole="button" accessibilityLabel={t("rcReportProblem")} onPress={() => router.push("/support/new")} android_ripple={ripple()} style={({ pressed }) => [st.action, st.warn, pressed && st.pressed]}>
              <AlertTriangle size={20} color={colors.gold600} />
              <Text style={[st.actionText, st.warnText]}>{t("rcReportProblem")}</Text>
            </Pressable>
          </View>
          <Banner icon={Headset} tint="blue" title={t("ppNeedHelp")} body={t("rcNeedHelpBody")} onPress={() => router.push("/support/new")} />
        </>
      ) : null}
    </Screen>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  disabled: { opacity: 0.5 },
  amountRow: { flexDirection: "row", alignItems: "center", gap: space.x3, flexWrap: "wrap" },
  amountLabel: { ...type.body, color: colors.neutral600 },
  amount: { fontFamily: "Inter_700Bold", fontSize: 28, lineHeight: 34, color: colors.navy950, fontVariant: ["tabular-nums"] },
  amountRight: { alignItems: "flex-end", gap: space.x1 },
  rows: { gap: space.x2 },
  providerCell: { flexDirection: "row", alignItems: "center", gap: 6, justifyContent: "flex-end", flexShrink: 1 },
  value: { ...type.body, color: colors.navy950, textAlign: "right", flexShrink: 1 },
  actions: { flexDirection: "row", gap: space.x2 },
  action: { flex: 1, minHeight: 56, borderRadius: radius.card, alignItems: "center", justifyContent: "center", gap: 4, paddingHorizontal: space.x2, paddingVertical: space.x2, overflow: "hidden" },
  actionText: { ...type.label, fontSize: 13, lineHeight: 17, textAlign: "center" },
  primary: { backgroundColor: colors.blue600 },
  primaryText: { color: colors.white },
  secondary: { backgroundColor: colors.blue50 },
  secondaryText: { color: colors.blue600 },
  warn: { backgroundColor: colors.gold50 },
  warnText: { color: colors.gold600 },
});

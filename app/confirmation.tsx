import React, { useCallback, useEffect, useState } from "react";
import { ArrowRight, Calendar, Check, CheckCircle2, Clock3, Download, FileText, Headset, Receipt, Share2, ShieldCheck, Sparkles } from "lucide-react-native";
import { Image, Pressable, Share, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { openDocumentUrl } from "@/components/documents/openDocument";
import { Button, Card, Screen, StatusChip, ripple } from "@/components/ui";
import { ActionTile, Banner, BrandHeader, CtaBar, HeroCard, HeroMeta, TintedIcon } from "@/components/design";
import { BrandArt } from "@/components/design/BrandArt";
import { ErrorCard, InfoRow, Stepper, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { InsuranceApi, Payment, PaymentsApi, PolicyApi, PurchaseStatus, TokenVault } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { useFormatters } from "@/hooks/useFormatters";
import { openableUrl } from "@/lib/purchase";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
import { PLATFORM_LOGO } from "@/components/BrandMark";


export default function Confirmation() {
  const params = useLocalSearchParams<{ proposalId?: string }>();
  const proposal = useInsurance((s) => s.proposal);
  const storePayment = useInsurance((s) => s.payment);
  // Receipt facts come from the paid payment itself: the store copy when this device paid, else read by id.
  const [fetchedPayment, setFetchedPayment] = useState<Payment | null>(null);
  const f = useFormatters();
  const { t, td } = useTranslation();
  const [result, setResult] = useState<PurchaseStatus | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [checking, setChecking] = useState(false);
  const [certBusy, setCertBusy] = useState(false);
  const [certMessage, setCertMessage] = useState<string | null>(null);
  const resultPaymentId = result?.payment?.id ?? null;
  const payment = storePayment && (!resultPaymentId || storePayment.id === resultPaymentId) ? storePayment : fetchedPayment ?? storePayment;
  const proposalId = params.proposalId || proposal?.id || storePayment?.proposal_id;
  useEffect(() => {
    if (!resultPaymentId || storePayment?.id === resultPaymentId || fetchedPayment?.id === resultPaymentId) return;
    let live = true;
    PaymentsApi.show(resultPaymentId)
      .then((p) => live && setFetchedPayment(p))
      .catch(() => undefined);
    return () => {
      live = false;
    };
  }, [resultPaymentId, storePayment?.id, fetchedPayment?.id]);

  const check = useCallback(async () => {
    if (!proposalId) {
      setError(new Error(t("cfMissingRef")));
      return;
    }
    setChecking(true);
    try {
      const next = await InsuranceApi.purchaseStatus(proposalId);
      setResult(next);
      setError(null);
      if (next.status === "POLICY_ISSUED" && next.policy) await TokenVault.clearPendingPayment();
    } catch (e) {
      setError(e);
    } finally {
      setChecking(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [proposalId]);

  const issued = result?.status === "POLICY_ISSUED" && !!result.policy;
  useEffect(() => {
    void check();
  }, [check]);
  // Paid but the insurer could not issue: stop polling, never ask to pay again.
  const issuanceFailed =
    (error as { code?: string } | null)?.code === "PAYMENT_OK_ISSUANCE_FAILED" ||
    /ISSUANCE_FAILED/.test(result?.status ?? "");
  // The payment itself failed (not issuance): stop polling and offer to pay again.
  const paymentFailed = !issued && !issuanceFailed && result?.status === "PAYMENT_FAILED";
  useEffect(() => {
    if (issued || issuanceFailed || paymentFailed) return;
    const timer = setInterval(() => void check(), 6000);
    return () => clearInterval(timer);
  }, [check, issued, issuanceFailed, paymentFailed]);

  const policy = result?.policy;
  const starts = policy?.coverage_starts_at ?? result?.coverage_starts_at;
  const ends = policy?.coverage_ends_at ?? result?.coverage_ends_at;

  const openPolicy = () => policy && router.replace({ pathname: "/policy/[id]", params: { id: policy.id } });

  /** Same verified-certificate path as the policy detail: never a locally built PDF. */
  const downloadCertificate = async () => {
    if (!policy) return;
    setCertBusy(true);
    setCertMessage(null);
    try {
      const cert = await PolicyApi.certificate(policy.id);
      const url = openableUrl(cert.download_url);
      if (url) openDocumentUrl(url, t("pdCertificate"), cert.serial_number ? `certificate-${cert.serial_number}` : undefined);
      else setCertMessage(t("pdCertPending", { serial: cert.serial_number ?? "" }).replace("  ", " "));
    } catch (e) {
      const status = (e as { status?: number }).status;
      setCertMessage(status === 404 ? t("pdCertNone") : t("pdCertFailed"));
    } finally {
      setCertBusy(false);
    }
  };

  const share = () =>
    policy &&
    void Share.share({
      message: t("cfShareMessage", { number: policy.policy_number, product: result?.product_name ?? "", carrier: result?.carrier_name ?? "" }),
    }).catch(() => undefined);

  const meta: HeroMeta[] = policy
    ? [
        { icon: FileText, label: t("pdPolicyNumber"), value: policy.policy_number },
        { icon: Calendar, label: t("cfValidFrom"), value: f.date(starts) },
        { icon: Calendar, label: t("cfValidTo"), value: f.date(ends) },
      ]
    : [];
  const paymentRef = payment?.provider_reference ?? result?.payment?.id ?? payment?.id ?? null;
  const paymentAmount = payment?.amount_minor ?? proposal?.terms_snapshot?.total_minor ?? null;

  const footer = (
    <CtaBar>
      {issued && policy ? (
        <Pressable accessibilityRole="button" accessibilityLabel={t("cfGoMyPolicy")} onPress={openPolicy} android_ripple={ripple(true)} style={({ pressed }) => [st.cta, pressed && st.pressed]}>
          <Text style={st.ctaText}>{t("cfGoMyPolicy")}</Text>
          <ArrowRight size={20} color={colors.white} />
        </Pressable>
      ) : paymentFailed && proposalId ? (
        <Button label={t("cfPayAgain")} onPress={() => router.replace({ pathname: "/checkout", params: { proposalId } })} />
      ) : (
        <Button label={t("cfRefresh")} loading={checking} onPress={() => void check()} />
      )}
      <Button label={t("cfGoPolicies")} variant="tertiary" onPress={() => router.replace("/(customer)/(tabs)/policies")} />
    </CtaBar>
  );

  return (
    <Screen footer={footer}>
      <BrandHeader back={false} right={null} />
      <View style={st.hero}>
        <View style={[st.heroIcon, issued ? st.heroIconOk : st.heroIconWait]}>
          {issued ? <Check size={40} color={colors.white} strokeWidth={3} /> : issuanceFailed ? <Clock3 size={36} color={colors.gold600} /> : <Clock3 size={36} color={colors.blue600} />}
        </View>
        <View style={st.flex}>
          <Text accessibilityRole="header" style={st.title} maxFontSizeMultiplier={1.6}>{issued ? t("cfCoveredTitle") : issuanceFailed ? t("cfIssuanceFailedTitle") : t("cfInProgress")}</Text>
          {issued ? <BrandArt name="gold_swoosh" width={150} style={st.swoosh} /> : null}
          <Text style={st.subtitle}>{issued ? t("cfCoveredSubtitle") : issuanceFailed ? t("errPaymentOkIssuanceFailed") : t("cfInProgressBody")}</Text>
        </View>
      </View>
      <Text style={st.lead}>{issued ? `${t("cfCoveredBody")} ${t("cfIssuedBody")}` : t("cfIssuedNote")}</Text>
      <Stepper steps={[t("cfStepPaid"), t("cfStepIssuing"), t("cfStepIssued")]} failed={issuanceFailed} current={issued ? 2 : 1} done={issued} />

      {issued && policy ? (
        <>
          <HeroCard
            icon={ShieldCheck}
            title={result?.product_name ?? policy.policy_number}
            provider={result?.carrier_name ?? null}
            chip={<StatusChip label={td(`policyStatus_${policy.status}`, policy.status)} tone="success" />}
            lines={[result?.product_name ? `${t("cfProduct")} · ${result.product_name}` : null]}
            meta={meta}
          >
            {policy.certificate_number ? <InfoRow label={t("pdCertificate")} value={policy.certificate_number} /> : null}
          </HeroCard>

          <View style={st.certCard}>
            <View style={st.certArt} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
              <Image source={PLATFORM_LOGO} style={st.certMark} resizeMode="contain" accessibilityIgnoresInvertColors />
              <Text style={st.certArtTitle}>{t("cfCertLabel").toUpperCase()}</Text>
              <View style={st.certLine} />
              <View style={[st.certLine, { width: "55%" }]} />
              <View style={st.certSeal}>
                <CheckCircle2 size={18} color={colors.white} />
              </View>
            </View>
            <View style={st.flex}>
              <Text style={st.certTitle}>{t("cfCertTitle")}</Text>
              <Text style={st.certBody}>{t("cfCertBody")}</Text>
              {/* Only a claim the certificate supports: it carries a verification QR (public /verify). */}
              {policy.certificate_number ? (
                <View style={st.signedChip}>
                  <ShieldCheck size={16} color={colors.success} />
                  <Text style={st.signedText}>{t("cfCertVerifiable")}</Text>
                </View>
              ) : null}
            </View>
          </View>

          <View style={st.tiles}>
            <ActionTile icon={FileText} label={t("viewPolicy")} onPress={openPolicy} />
            <ActionTile icon={Download} label={t("cfDownloadCert")} loading={certBusy} onPress={() => void downloadCertificate()} />
            <ActionTile icon={Share2} label={t("cfShare")} onPress={share} />
          </View>
          {certMessage ? <Text style={ps.meta}>{certMessage}</Text> : null}

          <Card onPress={payment?.id ? () => router.push({ pathname: "/payments/[id]", params: { id: payment.id } }) : undefined} accessibilityLabel={t("cfReceiptTitle")}>
            <View style={st.receiptHead}>
              <Text style={[st.cardTitle, st.flex]}>{t("cfReceiptTitle")}</Text>
              <StatusChip label={t("cfPaid")} tone="success" />
            </View>
            <View style={st.receiptRow}>
              <View style={st.receiptIcon}>
                <Receipt size={22} color={colors.blue600} />
              </View>
              <View style={st.flex}>
                <Text style={st.receiptLabel}>{t("cfPaymentRef")}</Text>
                <Text style={st.receiptValue} selectable>{paymentRef ?? "—"}</Text>
              </View>
              <View style={st.receiptDivider} />
              <View style={st.flex}>
                <Text style={st.receiptLabel}>{t("cfAmountPaid")}</Text>
                <Text style={st.receiptAmount}>{paymentAmount === null ? "—" : f.xaf(paymentAmount)}</Text>
              </View>
            </View>
            {payment?.created_at ? <Text style={st.receiptMeta}>{f.dateTime(payment.created_at)}</Text> : null}
          </Card>

          <Banner icon={Headset} tint="blue" title={t("cfHelpTitle")} body={t("cfHelpBody")} onPress={() => router.push("/support/new")} />
          <BrandArt name="wave_ribbons_lux" width={320} opacity={0.9} style={st.bottomArt} />
        </>
      ) : (
        <Card>
          <StatusChip label={result?.status ? td(`status_${result.status}`, result.status) : t("cfVerifying")} tone={result?.status === "PAYMENT_FAILED" ? "danger" : "warning"} />
          {result?.product_name ? <Text style={ps.body}>{result.product_name}{result.carrier_name ? ` · ${result.carrier_name}` : ""}</Text> : null}
          <View style={[st.bottomArt, { alignSelf: "center" }]}>
            <TintedIcon icon={Sparkles} tint="gold" size={56} />
          </View>
          <Text style={ps.meta}>{paymentFailed ? t("cfPaymentFailedBody") : t("cfIssuedNote")}</Text>
          {issuanceFailed ? <Button label={t("contactSupport")} variant="secondary" onPress={() => router.push("/support/new")} /> : null}
        </Card>
      )}
      {error ? <ErrorCard error={error} fallback={t("cfUnavailable")} /> : null}
    </Screen>
  );
}

const st = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  hero: { flexDirection: "row", alignItems: "center", gap: space.x4 },
  swoosh: { marginTop: 2, marginBottom: 2 },
  bottomArt: { alignSelf: "center" },
  heroIcon: { width: 84, height: 84, borderRadius: 42, alignItems: "center", justifyContent: "center", borderWidth: 8 },
  heroIconOk: { backgroundColor: colors.success, borderColor: colors.successSoft },
  heroIconWait: { backgroundColor: colors.white, borderColor: colors.blue50 },
  title: { fontFamily: "Inter_700Bold", fontSize: 30, lineHeight: 36, color: colors.navy950, letterSpacing: -0.5 },
  subtitle: { ...type.body, color: colors.neutral600, marginTop: 4 },
  lead: { ...type.bodyLarge, color: colors.neutral700 },
  cardTitle: { ...type.cardTitle, color: colors.navy950 },
  certCard: { flexDirection: "row", gap: space.x4, backgroundColor: colors.blue50, borderRadius: radius.feature, padding: space.x4, alignItems: "center" },
  certArt: { width: 128, height: 128, borderRadius: radius.control, backgroundColor: colors.white, borderWidth: 1, borderColor: colors.gold100, alignItems: "center", justifyContent: "center", gap: 6, padding: space.x2, overflow: "hidden" },
  certMark: { width: 28, height: 28, borderRadius: 6 },
  certArtTitle: { fontFamily: "Inter_700Bold", fontSize: 10, lineHeight: 13, letterSpacing: 0.8, color: colors.navy950, textAlign: "center" },
  certLine: { width: "70%", height: 3, borderRadius: 2, backgroundColor: colors.neutral200 },
  certSeal: { position: "absolute", right: 8, bottom: 8, width: 28, height: 28, borderRadius: 14, backgroundColor: colors.gold500, alignItems: "center", justifyContent: "center" },
  certTitle: { ...type.cardTitle, color: colors.navy950 },
  certBody: { ...type.body, color: colors.neutral700, marginTop: 4 },
  signedChip: { flexDirection: "row", alignItems: "center", gap: 6, alignSelf: "flex-start", backgroundColor: colors.successSoft, borderRadius: radius.pill, paddingHorizontal: 12, paddingVertical: 6, marginTop: space.x3 },
  signedText: { ...type.caption, color: colors.successText },
  tiles: { flexDirection: "row", gap: space.x2 },
  receiptHead: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  receiptRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  receiptIcon: { width: 48, height: 48, borderRadius: 24, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center" },
  receiptLabel: { ...type.meta, color: colors.neutral600 },
  receiptValue: { ...type.label, color: colors.navy950, marginTop: 2 },
  receiptAmount: { fontFamily: "Inter_700Bold", fontSize: 20, lineHeight: 26, color: colors.navy950, marginTop: 2 },
  receiptDivider: { width: 1, alignSelf: "stretch", backgroundColor: colors.neutral200 },
  receiptMeta: { ...type.meta, color: colors.neutral600 },
  cta: { minHeight: 54, borderRadius: radius.pill, backgroundColor: colors.blue600, flexDirection: "row", alignItems: "center", justifyContent: "center", gap: space.x2, overflow: "hidden" },
  ctaText: { fontFamily: "Inter_600SemiBold", fontSize: 17, lineHeight: 22, color: colors.white },
});

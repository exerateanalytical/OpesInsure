import React, { useCallback, useEffect, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { CheckCircle2, Clock3 } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { ErrorCard, InfoRow, Stepper, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { InsuranceApi, PurchaseStatus, TokenVault } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

export default function Confirmation() {
  const params = useLocalSearchParams<{ proposalId?: string }>();
  const proposal = useInsurance((s) => s.proposal);
  const payment = useInsurance((s) => s.payment);
  const f = useFormatters();
  const { t, td } = useTranslation();
  const [result, setResult] = useState<PurchaseStatus | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [checking, setChecking] = useState(false);
  const proposalId = params.proposalId || proposal?.id || payment?.proposal_id;

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
  useEffect(() => {
    if (issued || issuanceFailed) return;
    const timer = setInterval(() => void check(), 6000);
    return () => clearInterval(timer);
  }, [check, issued, issuanceFailed]);

  const policy = result?.policy;
  const starts = policy?.coverage_starts_at ?? result?.coverage_starts_at;
  const ends = policy?.coverage_ends_at ?? result?.coverage_ends_at;

  return (
    <Screen>
      <View style={st.center}>
        {issued ? <CheckCircle2 size={64} color={colors.success} /> : <Clock3 size={56} color={colors.blue600} />}
        <Text style={st.title}>{issued ? t("cfIssued") : issuanceFailed ? t("cfIssuanceFailedTitle") : t("cfInProgress")}</Text>
        <Text style={st.body}>{issued ? t("cfIssuedBody") : issuanceFailed ? t("errPaymentOkIssuanceFailed") : t("cfInProgressBody")}</Text>
      </View>
      <Stepper steps={[t("cfStepPaid"), t("cfStepIssuing"), t("cfStepIssued")]} failed={issuanceFailed} current={issued ? 2 : 1} done={issued} />
      {issued && policy ? (
        <Card>
          <StatusChip label={td(`policyStatus_${policy.status}`, policy.status)} tone="success" />
          <Text style={st.policy}>{policy.policy_number}</Text>
          {result?.carrier_name ? <InfoRow label={t("cfInsurer")} value={result.carrier_name} /> : null}
          {result?.product_name ? <InfoRow label={t("cfProduct")} value={result.product_name} /> : null}
          <InfoRow label={t("sumCoverStarts")} value={f.date(starts)} />
          <InfoRow label={t("sumCoverEnds")} value={f.date(ends)} />
          {policy.certificate_number ? <InfoRow label={t("pdCertificate")} value={policy.certificate_number} /> : null}
          <Button label={t("cfOpenPolicy")} onPress={() => router.replace({ pathname: "/policy/[id]", params: { id: policy.id } })} />
        </Card>
      ) : (
        <Card>
          <StatusChip label={result?.status ? td(`status_${result.status}`, result.status) : t("cfVerifying")} tone={result?.status === "PAYMENT_FAILED" ? "danger" : "warning"} />
          {result?.product_name ? <Text style={ps.body}>{result.product_name}{result.carrier_name ? ` · ${result.carrier_name}` : ""}</Text> : null}
          <Text style={ps.meta}>{t("cfIssuedNote")}</Text>
          {issuanceFailed ? <Button label={t("contactSupport")} variant="secondary" onPress={() => router.push("/support/new")} /> : null}
        </Card>
      )}
      {error ? <ErrorCard error={error} fallback={t("cfUnavailable")} /> : null}
      {!issued ? <Button label={t("cfRefresh")} loading={checking} variant="secondary" onPress={() => void check()} /> : null}
      <Button label={t("cfGoPolicies")} variant={issued ? "secondary" : "primary"} onPress={() => router.replace("/(customer)/(tabs)/policies")} />
    </Screen>
  );
}

const st = StyleSheet.create({
  center: { alignItems: "center", gap: space.x3, marginTop: space.x8 },
  title: { ...type.pageTitle, color: colors.navy950, textAlign: "center" },
  body: { ...type.body, color: colors.neutral600, textAlign: "center" },
  policy: { ...type.cardTitle, color: colors.navy950, fontVariant: ["tabular-nums"] },
});

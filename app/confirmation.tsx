import React, { useCallback, useEffect, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { CheckCircle2, Clock3 } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { ErrorCard, InfoRow, Stepper, purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { InsuranceApi, PurchaseStatus, TokenVault } from "@/api/client";
import { useInsurance } from "@/store/insurance";
import { humanize } from "@/lib/purchase";
import { useFormatters } from "@/hooks/useFormatters";
import { colors, space, type } from "@/theme/tokens";

export default function Confirmation() {
  const params = useLocalSearchParams<{ proposalId?: string }>();
  const proposal = useInsurance((s) => s.proposal);
  const payment = useInsurance((s) => s.payment);
  const f = useFormatters();
  const [result, setResult] = useState<PurchaseStatus | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [checking, setChecking] = useState(false);
  const proposalId = params.proposalId || proposal?.id || payment?.proposal_id;

  const check = useCallback(async () => {
    if (!proposalId) {
      setError(new Error("Purchase reference missing. Open My applications to continue."));
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
  }, [proposalId]);

  const issued = result?.status === "POLICY_ISSUED" && !!result.policy;
  useEffect(() => {
    void check();
  }, [check]);
  useEffect(() => {
    if (issued) return;
    const timer = setInterval(() => void check(), 6000);
    return () => clearInterval(timer);
  }, [check, issued]);

  const policy = result?.policy;
  const starts = policy?.coverage_starts_at ?? result?.coverage_starts_at;
  const ends = policy?.coverage_ends_at ?? result?.coverage_ends_at;

  return (
    <Screen>
      <View style={st.center}>
        {issued ? <CheckCircle2 size={64} color={colors.success} /> : <Clock3 size={56} color={colors.blue600} />}
        <Text style={st.title}>{issued ? "Your policy is issued." : "Payment received. Issuance in progress."}</Text>
        <Text style={st.body}>{issued ? "Coverage details below are confirmed by the insurer." : "Do not treat the payment receipt as proof of insurance. This screen updates automatically."}</Text>
      </View>
      <Stepper steps={["Paid", "Issuing", "Policy issued"]} current={issued ? 2 : 1} done={issued} />
      {issued && policy ? (
        <Card>
          <StatusChip label={humanize(policy.status)} tone="success" />
          <Text style={st.policy}>{policy.policy_number}</Text>
          {result?.carrier_name ? <InfoRow label="Insurer" value={result.carrier_name} /> : null}
          {result?.product_name ? <InfoRow label="Product" value={result.product_name} /> : null}
          <InfoRow label="Cover starts" value={f.date(starts)} />
          <InfoRow label="Cover ends" value={f.date(ends)} />
          {policy.certificate_number ? <InfoRow label="Certificate" value={policy.certificate_number} /> : null}
          <Button label="Open policy" onPress={() => router.replace({ pathname: "/policy/[id]", params: { id: policy.id } })} />
        </Card>
      ) : (
        <Card>
          <StatusChip label={humanize(result?.status ?? "VERIFYING")} tone={result?.status === "PAYMENT_FAILED" ? "danger" : "warning"} />
          {result?.product_name ? <Text style={ps.body}>{result.product_name}{result.carrier_name ? ` · ${result.carrier_name}` : ""}</Text> : null}
          <Text style={ps.meta}>“Issued” appears only after the insurer confirms a policy on the server.</Text>
        </Card>
      )}
      {error ? <ErrorCard error={error} fallback="Issuance status is unavailable." /> : null}
      {!issued ? <Button label="Refresh issuance status" loading={checking} variant="secondary" onPress={() => void check()} /> : null}
      <Button label="Go to my policies" variant={issued ? "secondary" : "primary"} onPress={() => router.replace("/(customer)/(tabs)/policies")} />
    </Screen>
  );
}

const st = StyleSheet.create({
  center: { alignItems: "center", gap: space.x3, marginTop: space.x8 },
  title: { ...type.pageTitle, color: colors.navy950, textAlign: "center" },
  body: { ...type.body, color: colors.neutral600, textAlign: "center" },
  policy: { ...type.cardTitle, color: colors.navy950, fontVariant: ["tabular-nums"] },
});

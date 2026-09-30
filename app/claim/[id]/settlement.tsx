import React, { useState } from "react";
import { Alert, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Check, Circle, Headphones, Receipt } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, CtaBar, HeroCard } from "@/components/design";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { SettlementHero } from "@/components/claims/SettlementHero";
import { claimPolicy, policyLine, policyTitle, productIcon, providerName } from "@/components/claims/claimProduct";
import { useInsurerLogo } from "@/components/claims/insurerLogo";
import { ClaimsApi, ClaimsCompletionApi } from "@/api/client";
import { ClaimRecordsApi } from "@/api/extra";
import { handleStepUpRequired } from "@/security/step-up";
import { useLoad } from "@/hooks/useLoad";
import { usePolicies } from "@/hooks/usePolicies";
import { useFormatters } from "@/hooks/useFormatters";
import { isNotFound } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { claimDecisionDate, claimStatusKey, claimTone } from "@/lib/claimStatus";
import { canDecideSettlement, settlementSteps } from "@/lib/settlement";
import { colors, space, type } from "@/theme/tokens";

/**
 * Claim settlement (opesinsure_claim_settlement_dashboard): claim summary,
 * navy settlement-amount hero with approved / excess / net, accept or reject
 * the offer (POST settlement/decision, step-up protected), settlement
 * tracking from the offer and payment status, terms, payment
 * tracking and help. The backend exposes no payout method or advice PDF to
 * customers yet, so those blocks are not shown.
 */
export default function Settlement() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { date } = useFormatters();
  const { data: x, setData: setX, loading, error, reload } = useLoad(() => ClaimsCompletionApi.settlement(id), [id]);
  const claim = useLoad(() => ClaimsApi.show(id), [id]);
  const timeline = useLoad(() => ClaimRecordsApi.timeline(id), [id]);
  const { policies } = usePolicies();
  const logoFor = useInsurerLogo();
  const [actionError, setActionError] = useState<unknown>(null);
  const decide = (decision: "ACCEPT" | "REJECT") =>
    Alert.alert(
      decision === "ACCEPT" ? t("settleAcceptQ") : t("settleRejectQ"),
      decision === "ACCEPT" ? t("settleAcceptBody") : t("settleRejectBody"),
      [
        { text: t("cancel"), style: "cancel" },
        {
          text: decision === "ACCEPT" ? t("settleAccept") : t("settleReject"),
          style: decision === "REJECT" ? "destructive" : "default",
          onPress: async () => {
            setActionError(null);
            try {
              setX(await ClaimsCompletionApi.decideSettlement(id, decision));
            } catch (e) {
              if (!handleStepUpRequired(e, "CLAIM_SETTLEMENT_DECISION", `/claim/${id}/settlement`)) setActionError(e);
            }
          },
        },
      ],
    );

  const c = claim.data;
  const policy = c ? policies.find((p) => p.id === c.policy_id) ?? claimPolicy(c) : null;
  const title = policyTitle(policy, t("claimPolicyLabel"));
  const header = (
    <>
      <BrandHeader title={t("settleTitle")} subtitle={canDecideSettlement(x) ? t("settleAwaitingAnswer") : t("settleSubtitle")} />
      {c ? (
        <HeroCard
          icon={productIcon(title, policyLine(policy))}
          title={title}
          lines={[c.claim_number, policy?.policy_number ? t("claimPolicyNo", { number: policy.policy_number }) : null, `${t("claimIncidentDate")}: ${date(c.incident_at)}`]}
          provider={providerName(policy)}
          providerLogo={logoFor(c, policy)}
          chip={<StatusChip label={td(claimStatusKey(c.status), c.status)} tone={claimTone(c.status)} />}
        />
      ) : null}
    </>
  );

  if (!x)
    return (
      <Screen>
        {header}
        {loading ? (
          <LoadingState label={t("settleLoading")} />
        ) : error && !isNotFound(error) ? (
          <ErrorState error={error} onRetry={() => void reload()} />
        ) : (
          <EmptyState title={t("settleNone")} message={t("settleNoneBody")} action={t("refresh")} onPress={() => void reload()} />
        )}
      </Screen>
    );
  const steps = settlementSteps(x);
  const approvedAt = claimDecisionDate(timeline.data ?? []);
  return (
    <Screen
      footer={
        canDecideSettlement(x) ? (
          <CtaBar>
            <Button label={t("settleAcceptCta")} onPress={() => decide("ACCEPT")} />
            <Button label={t("settleRejectCta")} variant="secondary" onPress={() => decide("REJECT")} />
          </CtaBar>
        ) : undefined
      }
    >
      {header}
      <SettlementHero settlement={x} />
      <Card>
        <View style={s.headRow}>
          <Text accessibilityRole="header" style={[s.cardTitle, s.flex]}>{t("settleTracking")}</Text>
          <StatusChip label={td(`settlementStatus_${x.status}`, x.status)} tone={x.status === "DISPUTED" ? "danger" : steps[1].done ? "success" : "warning"} />
        </View>
        <Text style={s.meta}>{t(x.status === "DISPUTED" ? "settleDisputedBody" : "settleTrackingBody")}</Text>
        {steps.map((step, i) => (
          <View key={step.key} style={s.step}>
            <View style={s.rail}>
              <View style={[s.dot, step.done && s.dotDone]}>{step.done ? <Check size={14} color={colors.white} strokeWidth={3} /> : <Circle size={8} color={colors.neutral400} fill={colors.neutral400} />}</View>
              {i < steps.length - 1 ? <View style={[s.line, step.done && s.lineDone]} /> : null}
            </View>
            <View style={s.flex}>
              <Text style={[s.stepTitle, !step.done && s.muted]}>{t(step.label)}</Text>
              <Text style={s.meta}>{t(step.body)}</Text>
            </View>
            {step.key === "approved" && approvedAt ? <Text style={s.meta}>{date(approvedAt)}</Text> : null}
          </View>
        ))}
        <Text style={s.meta}>{t("settlePayRef", { ref: x.payment_reference ?? t("settlePayRefPending") })}</Text>
      </Card>
      {x.terms ? (
        <Card>
          <Text accessibilityRole="header" style={s.cardTitle}>{t("decisionTerms")}</Text>
          <Text style={s.body}>{x.terms}</Text>
        </Card>
      ) : null}
      {actionError ? <ErrorCard error={actionError} fallback={t("errGeneric")} onRetry={() => void reload()} retryLabel={t("refresh")} /> : null}
      <Banner icon={Receipt} tint="blue" title={t("settleTrackPayment")} body={t("settlePaySubtitle")} onPress={() => router.push(`/claim/${id}/settlement-payment`)} />
      <Banner
        icon={Headphones}
        tint="neutral"
        title={t("settleNeedHelp")}
        body={t("settleNeedHelpBody")}
        onPress={() => router.push({ pathname: "/support/new", params: { claimId: id, reference: c?.claim_number ?? "", category: "CLAIM" } })}
      />
    </Screen>
  );
}

const s = StyleSheet.create({
  flex: { flex: 1 },
  headRow: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  cardTitle: { ...type.cardTitle, color: colors.navy900 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
  step: { flexDirection: "row", gap: space.x3, minHeight: 56 },
  rail: { alignItems: "center", width: 24 },
  dot: { width: 24, height: 24, borderRadius: 12, backgroundColor: colors.neutral100, borderWidth: 1, borderColor: colors.neutral300, alignItems: "center", justifyContent: "center" },
  dotDone: { backgroundColor: colors.success, borderColor: colors.success },
  line: { flex: 1, width: 2, backgroundColor: colors.neutral200, marginVertical: 2 },
  lineDone: { backgroundColor: colors.blue600 },
  stepTitle: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.navy950 },
  muted: { color: colors.neutral600 },
});

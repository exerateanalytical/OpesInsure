import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, Calendar, CircleCheck, CircleX, Coins, FileText, Landmark, MessageSquareWarning, ShieldCheck, Target } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, CtaBar, MetaGrid, type HeroMeta } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { InstitutionMark } from "@/components/InstitutionMark";
import { claimPolicy, providerName } from "@/components/claims/claimProduct";
import { useInsurerLogo } from "@/components/claims/insurerLogo";
import { useLoad } from "@/hooks/useLoad";
import { usePolicies } from "@/hooks/usePolicies";
import { useFormatters } from "@/hooks/useFormatters";
import { ClaimsApi, ClaimsCompletionApi } from "@/api/client";
import { ClaimRecordsApi } from "@/api/extra";
import { useTranslation } from "@/i18n";
import { claimActionAllowed, claimDecisionDate, claimStatusKey, claimTone, normalizeClaimStatus } from "@/lib/claimStatus";
import { colors, radius, space, type } from "@/theme/tokens";

type ClaimAmounts = { estimated_loss_minor?: number | null; approved_amount_minor?: number | null };

/**
 * Claim decision (opesinsure_claim_decision_screen), reachable once the claim
 * is APPROVED / PARTIALLY_APPROVED / DECLINED (or later). Outcome from the
 * claim status, decision date from the status-change events, claimed and
 * approved amounts from the claim record, excess and net payable from the
 * settlement offer when one exists. Next step → settlement; appeal card when
 * claimActionAllowed("appeal"). The backend exposes no decision letter or
 * written reason to customers yet, so those are not shown.
 */
export default function ClaimDecision() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td, date } = useTranslation();
  const { xaf } = useFormatters();
  const claim = useLoad(() => ClaimsApi.show(id), [id]);
  const timeline = useLoad(() => ClaimRecordsApi.timeline(id), [id]);
  // 404 until the insurer issues an offer: treated as "no settlement yet".
  const settlement = useLoad(() => ClaimsCompletionApi.settlement(id).catch(() => null), [id]);
  const { policies } = usePolicies();
  const logoFor = useInsurerLogo();
  const c = claim.data;
  const canSettle = c ? claimActionAllowed("settlement", c.status) : false;

  return (
    <Screen
      footer={
        canSettle ? (
          <CtaBar>
            <Button label={t("decisionContinueSettlement")} icon={ArrowRight} onPress={() => router.push({ pathname: "/claim/[id]/settlement", params: { id } })} />
          </CtaBar>
        ) : undefined
      }
    >
      <BrandHeader title={t("claimDecisionTitle")} subtitle={t("claimDecisionSubtitle")} />
      <StatePanel {...claim} onRetry={claim.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(cl) => {
          const status = normalizeClaimStatus(cl.status);
          const decided = claimActionAllowed("decision", cl.status);
          const declined = status === "DECLINED";
          const partial = status === "PARTIALLY_APPROVED";
          const policy = policies.find((p) => p.id === cl.policy_id) ?? claimPolicy(cl);
          const provider = providerName(policy);
          const amounts = cl as typeof cl & ClaimAmounts;
          const decidedAt = claimDecisionDate(timeline.data ?? []);
          const offer = settlement.data;
          const meta: HeroMeta[] = [
            { icon: FileText, label: t("claimNumberLabel"), value: cl.claim_number },
            { icon: Calendar, label: t("decisionDate"), value: decidedAt ? date(decidedAt) : "—" },
            ...(policy?.policy_number ? [{ icon: ShieldCheck, label: t("claimPolicyNumberLabel"), value: policy.policy_number }] : []),
            ...(provider ? [{ icon: Landmark, label: t("insurer"), value: provider }] : []),
          ];
          if (!decided)
            return (
              <Card>
                <StatusChip label={td(claimStatusKey(cl.status), cl.status)} tone={claimTone(cl.status)} />
                <Text style={s.body}>{t("decisionNotYet")}</Text>
              </Card>
            );
          return (
            <>
              <View style={[s.outcome, declined ? s.outcomeRed : s.outcomeGreen]} accessibilityRole="summary">
                {declined ? <CircleX size={52} color={colors.danger} /> : <CircleCheck size={52} color={colors.success} />}
                <View style={s.flex}>
                  <Text style={[s.outcomeTitle, declined ? s.red : s.green]}>
                    {declined ? t("decisionDeclined") : partial ? t("decisionPartial") : t("decisionApproved")}
                  </Text>
                  <Text style={s.body}>{declined ? t("decisionDeclinedBody") : t("decisionApprovedBody")}</Text>
                </View>
              </View>

              <Card>
                <MetaGrid items={meta} columns={2} />
                {cl.description ? (
                  <View style={s.summary}>
                    <Text style={s.label}>{t("decisionIncidentSummary")}</Text>
                    <Text style={s.body}>{cl.description}</Text>
                  </View>
                ) : null}
                {provider ? (
                  <View style={s.insurer}>
                    <InstitutionMark logoUrl={logoFor(cl, policy)} initials={provider.slice(0, 2).toUpperCase()} size={28} />
                    <Text style={s.body}>{provider}</Text>
                  </View>
                ) : null}
              </Card>

              {!declined ? (
                <Card>
                  <View style={s.headRow}>
                    <Coins size={26} color={colors.navy900} />
                    <Text accessibilityRole="header" style={s.cardTitle}>{t("decisionPaymentSummary")}</Text>
                  </View>
                  <View style={s.table}>
                    {amounts.estimated_loss_minor != null ? <Row label={t("decisionClaimed")} value={xaf(amounts.estimated_loss_minor)} /> : null}
                    {amounts.approved_amount_minor != null ? (
                      <Row label={t("decisionApprovedAmount")} value={xaf(amounts.approved_amount_minor)} />
                    ) : offer ? (
                      <Row label={t("decisionApprovedAmount")} value={xaf(offer.offered_minor)} />
                    ) : null}
                    {offer ? <Row label={t("decisionExcess")} value={`- ${xaf(offer.deductible_minor)}`} /> : null}
                    {offer ? (
                      <View style={s.net}>
                        <Text style={s.netLabel}>{t("decisionNetPayable")}</Text>
                        <Text style={s.netValue}>{xaf(offer.net_minor)}</Text>
                      </View>
                    ) : (
                      <Text style={s.meta}>{t("decisionOfferPending")}</Text>
                    )}
                  </View>
                </Card>
              ) : null}

              {offer?.terms ? (
                <Card>
                  <Text accessibilityRole="header" style={s.cardTitle}>{t("decisionTerms")}</Text>
                  <Text style={s.body}>{offer.terms}</Text>
                </Card>
              ) : null}

              {canSettle ? (
                <Banner icon={Target} tint="blue" title={t("claimNextStepLabel")} body={t("decisionNextSettlement")} onPress={() => router.push({ pathname: "/claim/[id]/settlement", params: { id } })} />
              ) : null}
              {claimActionAllowed("appeal", cl.status) ? (
                <Banner icon={MessageSquareWarning} tint="neutral" title={t("decisionAppealTitle")} body={t("decisionAppealBody")} onPress={() => router.push({ pathname: "/claim/[id]/appeal", params: { id } })} />
              ) : null}
            </>
          );
        }}
      </StatePanel>
    </Screen>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <View style={s.row}>
      <Text style={[s.body, s.flex]}>{label}</Text>
      <Text style={s.value}>{value}</Text>
    </View>
  );
}

const s = StyleSheet.create({
  flex: { flex: 1 },
  outcome: { flexDirection: "row", alignItems: "center", gap: space.x4, padding: space.x4, borderRadius: radius.feature, borderWidth: 1 },
  outcomeGreen: { backgroundColor: colors.successSoft, borderColor: colors.successSoft },
  outcomeRed: { backgroundColor: colors.dangerSoft, borderColor: colors.dangerSoft },
  outcomeTitle: { ...type.cardTitle, fontSize: 22, lineHeight: 28 },
  green: { color: colors.successText },
  red: { color: colors.dangerText },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
  label: { ...type.meta, color: colors.neutral600 },
  summary: { gap: 2, paddingTop: space.x3, borderTopWidth: 1, borderTopColor: colors.neutral100 },
  insurer: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  headRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  cardTitle: { ...type.cardTitle, color: colors.navy900 },
  table: { backgroundColor: colors.blue50, borderRadius: radius.card, padding: space.x4, gap: space.x2 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  value: { ...type.label, color: colors.navy950 },
  net: { flexDirection: "row", alignItems: "center", paddingTop: space.x3, borderTopWidth: 1, borderTopColor: colors.neutral200 },
  netLabel: { ...type.cardTitle, color: colors.blue700, flex: 1 },
  netValue: { ...type.cardTitle, color: colors.blue700 },
});

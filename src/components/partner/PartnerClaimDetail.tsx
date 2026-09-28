import React from "react";
import { Linking, StyleSheet, Text } from "react-native";
import { Href, router } from "expo-router";
import { FileText, MessageCircle, ShieldAlert } from "lucide-react-native";
import { AgentButton, AgentCard, AgentEmptyState, AgentSection, AgentShell, AgentSkeleton } from "@/components/agent";
import { KV } from "@/components/partner/AgentEarningsUi";
import { AgentRawChip } from "@/components/partner/AgentListUi";
import { agentColors as ac, agentLayout as aL, agentType as aT } from "@/theme/agent";
import { useLoad } from "@/hooks/useLoad";
import { AppHeader, Button, Card, Screen, SectionTitle, StatusChip } from "@/components/ui";
import { DetailRow } from "@/components/design";
import { EmptyState, StatePanel } from "@/components/StatePanel";
import { PartnerClaim, humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/**
 * Book claim detail for intermediaries (AGT-001): tracking and assistance
 * only. There is deliberately no approve / decline / settle action here —
 * adjudication stays with the insurer's maker-checker flow.
 */
export function PartnerClaimDetail({
  id,
  base,
  loadClaims,
  supportPhone,
  variant = "default",
}: {
  id: string;
  base: string;
  loadClaims: () => Promise<PartnerClaim[]>;
  supportPhone?: string | null;
  /** "agent" = Commercial Agent spec v2 drill-down; the broker keeps "default". */
  variant?: "default" | "agent";
}) {
  const { t, td } = useTranslation();
  const q = useLoad(async () => (await loadClaims()).find((c) => c.id === id) ?? null, [id]);
  const c = q.data;
  if (variant === "agent") {
    return (
      <AgentShell variant="drilldown" title={c?.claim_number ?? t("claims")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
        {q.loading && !q.data ? (
          <AgentSkeleton rows={5} height={56} />
        ) : q.error ? (
          <AgentEmptyState icon={ShieldAlert} title={t("loadErrorTitle")} body={t("loadErrorBody")} actionLabel={t("retry")} onAction={q.reload} />
        ) : !c ? (
          <AgentEmptyState icon={ShieldAlert} title={t("pdNotFound")} body={t("pdNotFoundBody")} />
        ) : (
          <>
            <AgentCard style={as.hero}>
              <Text style={as.caption}>{(c.approved_amount_minor != null ? t("pdApproved") : t("pdEstimated")).toUpperCase()}</Text>
              <Text style={as.amount} numberOfLines={1} adjustsFontSizeToFit>
                {c.approved_amount_minor != null ? money(c.approved_amount_minor) : c.estimated_loss_minor != null ? money(c.estimated_loss_minor) : "—"}
              </Text>
              <AgentRawChip raw={c.status} label={td(`claimStatus_${c.status}`, humanize(c.status))} />
              <Text style={as.name}>{c.customer_name}</Text>
            </AgentCard>
            <AgentCard>
              <KV first label={t("policies")} value={c.policy_number} />
              <KV label={t("pcInsurer")} value={c.carrier_name} />
              <KV label={t("pdPriority")} value={humanize(c.priority)} />
              <KV label={t("pdLossDate")} value={shortDate(c.loss_occurred_at)} />
              <KV label={t("pdFiled")} value={shortDate(c.submitted_at)} />
              <KV label={t("pdEstimated")} value={c.estimated_loss_minor != null ? money(c.estimated_loss_minor) : null} />
              <KV label={t("pdApproved")} value={c.approved_amount_minor != null ? money(c.approved_amount_minor) : null} strong />
            </AgentCard>
            <AgentSection title={t("pdAssistance")}>
              <AgentCard style={as.assist}>
                <Text style={as.body}>{t("pdClaimAssistBody")}</Text>
                <AgentButton variant="secondary" icon={FileText} label={t("pdOpenPolicy")} onPress={() => router.push(`${base}/policies/${c.policy_id}` as Href)} />
                {supportPhone ? (
                  <AgentButton variant="secondary" icon={MessageCircle} label={t("pdContactInsurer")} onPress={() => void Linking.openURL(`tel:${supportPhone}`)} />
                ) : null}
              </AgentCard>
            </AgentSection>
          </>
        )}
      </AgentShell>
    );
  }
  return (
    <Screen>
      <AppHeader title={c?.claim_number ?? t("claims")} subtitle={c?.customer_name} back />
      <StatePanel {...q} onRetry={q.reload} loadingLabel={t("claimsLoading")}>
        {(x) =>
          !x ? (
            <EmptyState title={t("pdNotFound")} message={t("pdNotFoundBody")} />
          ) : (
            <>
              <Card feature>
                <StatusChip label={td(`claimStatus_${x.status}`, humanize(x.status))} tone="info" />
                <DetailRow label={t("policies")} value={x.policy_number} />
                <DetailRow label={t("pcInsurer")} value={x.carrier_name} />
                <DetailRow label={t("pdPriority")} value={humanize(x.priority)} />
                <DetailRow label={t("pdLossDate")} value={shortDate(x.loss_occurred_at)} />
                <DetailRow label={t("pdFiled")} value={shortDate(x.submitted_at)} />
                <DetailRow label={t("pdEstimated")} value={x.estimated_loss_minor != null ? money(x.estimated_loss_minor) : null} />
                <DetailRow label={t("pdApproved")} value={x.approved_amount_minor != null ? money(x.approved_amount_minor) : null} strong />
              </Card>
              <SectionTitle title={t("pdAssistance")} />
              <Card>
                <Text style={{ ...type.body, color: colors.neutral700 }}>{t("pdClaimAssistBody")}</Text>
                <Button variant="secondary" icon={FileText} label={t("pdOpenPolicy")} onPress={() => router.push(`${base}/policies/${x.policy_id}` as Href)} />
                {supportPhone ? (
                  <Button variant="tertiary" icon={MessageCircle} label={t("pdContactInsurer")} onPress={() => void Linking.openURL(`tel:${supportPhone}`)} />
                ) : null}
              </Card>
            </>
          )
        }
      </StatePanel>
    </Screen>
  );
}

const as = StyleSheet.create({
  hero: { gap: 8, alignItems: "flex-start" },
  caption: { ...aT.caption, color: ac.secondary, letterSpacing: 0.6 },
  amount: { ...aT.heroAmount, color: ac.heading },
  name: { ...aT.body, color: ac.text },
  assist: { gap: aL.rowGap + 4 },
  body: { ...aT.body, color: ac.secondary },
});

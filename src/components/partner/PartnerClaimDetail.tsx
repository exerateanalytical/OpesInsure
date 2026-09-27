import React from "react";
import { Linking, Text } from "react-native";
import { Href, router } from "expo-router";
import { FileText, MessageCircle } from "lucide-react-native";
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
}: {
  id: string;
  base: string;
  loadClaims: () => Promise<PartnerClaim[]>;
  supportPhone?: string | null;
}) {
  const { t, td } = useTranslation();
  const q = useLoad(async () => (await loadClaims()).find((c) => c.id === id) ?? null, [id]);
  const c = q.data;
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

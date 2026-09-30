import { CarrierGate } from "@/components/carrier/CarrierGate";
import React, { useState } from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { CircleCheck, CircleX, MessageSquareMore } from "lucide-react-native";
import {
  AppHeader,
  Card,
  Money,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { CarrierApi, CarrierReferral } from "@/api/client";
import { useTranslation } from "@/i18n";
import { DetailActions, UnavailableSection, type DetailAction } from "@/components/detail";
import { referralDecisions, type ReferralDecision } from "@/lib/carrierDecisions";

export default function ReferralDetail() {
  return (
    <CarrierGate module="referrals">
      <ReferralDetailBody />
    </CarrierGate>
  );
}

const DECISION_UI: Record<ReferralDecision, { label: "caApproveWithinAuthority" | "caRequestMoreInfo" | "caDeclineWithReason"; icon: DetailAction["icon"]; variant: DetailAction["variant"] }> = {
  APPROVE: { label: "caApproveWithinAuthority", icon: CircleCheck, variant: "primary" },
  MORE_INFORMATION: { label: "caRequestMoreInfo", icon: MessageSquareMore, variant: "secondary" },
  DECLINE: { label: "caDeclineWithReason", icon: CircleX, variant: "danger" },
};

function ReferralDetailBody() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const q = useLoad(() => CarrierApi.referral(id), [id]);
  const x: CarrierReferral | undefined = q.data;
  const [note, setNote] = useState("");
  // The server lists the decisions this user may take (allowed_actions); older
  // servers fall back to the open underwriting statuses. The server re-checks.
  const decisions = x ? referralDecisions(x) : [];
  return (
    <Screen>
      <AppHeader title={t("caReferralReview")} subtitle={x?.quote_id} back />
      {!x ? (
        <StatePanel {...q} onRetry={q.reload} loadingLabel={t("caLoadingReferral")}>
          {() => null}
        </StatePanel>
      ) : null}
      {x ? (
        <Card feature>
          <StatusChip label={td(`refStatus_${x.status}`, x.status)} tone={decisions.length ? "warning" : "neutral"} />
          <Text>
            {x.customer_name} · {x.product}
          </Text>
          <Money amount={x.premium_minor / 100} />
          <Text>{x.reason}</Text>
          {x.decision_note ? <Text>{t("cdLastDecisionNote", { note: x.decision_note })}</Text> : null}
          {decisions.length ? (
            <TextField label={t("caUnderwritingNote")} multiline value={note} onChangeText={setNote} />
          ) : null}
        </Card>
      ) : null}
      {x && decisions.length ? (
        <DetailActions
          actions={decisions.map((d) => ({
            key: d,
            label: t(DECISION_UI[d].label),
            icon: DECISION_UI[d].icon,
            variant: DECISION_UI[d].variant,
            allowed: true,
            disabled: note.trim().length < 5,
            confirm: `${t("refDecisionQ")} ${t("refDecision", { decision: td(`refDecision_${d}`, d) })}`,
            run: async () => {
              q.setData(await CarrierApi.decideReferral(id, d, note.trim()));
              setNote("");
            },
            successMessage: t("refRecorded"),
          }))}
        />
      ) : null}
      {x ? (
        /* CAR-002: assignment, escalation above authority, second approval and
           conditions need backend endpoints; authority stays server-side
           (AUTHORITY_EXCEEDED is surfaced on decide). */
        <UnavailableSection title={t("cdReferralWorkflow")} message={t("cdReferralWorkflowBody")} />
      ) : null}
    </Screen>
  );
}

import React, { useRef, useState } from "react";
import { Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import * as Crypto from "expo-crypto";
import { useLoad } from "@/hooks/useLoad";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { DetailRow } from "@/components/design";
import { EmptyState, StatePanel } from "@/components/StatePanel";
import { AgentWorkspaceApi } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/** AGT-001: agent-assisted FNOL on a book policy. The insurer adjudicates; the agent only files. */
export default function AgentAssistClaim() {
  const { t } = useTranslation();
  const { policyId } = useLocalSearchParams<{ policyId: string }>();
  const q = useLoad(async () => (await AgentWorkspaceApi.policies()).find((p) => p.id === policyId) ?? null, [policyId]);
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [description, setDescription] = useState("");
  const [location, setLocation] = useState("");
  const [amount, setAmount] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // One key per form instance so a retried submit cannot file twice.
  const key = useRef(Crypto.randomUUID());
  const lossMs = Date.parse(`${date}T12:00:00`);
  const valid = description.trim().length >= 10 && !Number.isNaN(lossMs) && lossMs <= Date.now();
  return (
    <Screen>
      <AppHeader title={t("pdAssistClaim")} subtitle={t("pdAssistClaimSubtitle")} back />
      <StatePanel {...q} onRetry={q.reload}>
        {(p) =>
          !p || !p.party_id ? (
            <EmptyState title={t("pdNotFound")} message={t("pdNotFoundBody")} />
          ) : (
            <Card>
              <DetailRow label={t("policies")} value={p.policy_number} />
              <DetailRow label={t("pcCustomer")} value={p.customer_name} />
              <TextField label={t("pdLossDate")} placeholder="2026-09-27" value={date} onChangeText={setDate} />
              <TextField label={t("pdWhatHappened")} multiline value={description} onChangeText={setDescription} />
              <TextField label={t("pdLossLocation")} value={location} onChangeText={setLocation} />
              <TextField label={t("mdAmountFcfa")} keyboardType="number-pad" value={amount} onChangeText={setAmount} />
              {error ? <Text style={{ ...type.body, color: colors.dangerText }} accessibilityLiveRegion="polite">{error}</Text> : null}
              <Button
                label={t("pdFileClaimForCustomer")}
                hint={t("pdFileClaimHint")}
                disabled={!valid}
                loading={busy}
                onPress={async () => {
                  setBusy(true);
                  setError(null);
                  try {
                    const res = await AgentWorkspaceApi.reportClaim({
                      policy_id: p.id,
                      claimant_party_id: p.party_id!,
                      loss_occurred_at: new Date(lossMs).toISOString(),
                      loss_details: { description: description.trim() },
                      loss_location: location.trim() || undefined,
                      estimated_loss_minor: Number(amount) > 0 ? Math.round(Number(amount) * 100) : undefined,
                      idempotency_key: key.current,
                    });
                    router.replace({ pathname: "/agent/claims/[id]", params: { id: res.id } });
                  } catch (e) {
                    setError(e instanceof Error ? e.message : t("pdFileClaimFailed"));
                  } finally {
                    setBusy(false);
                  }
                }}
              />
            </Card>
          )
        }
      </StatePanel>
    </Screen>
  );
}

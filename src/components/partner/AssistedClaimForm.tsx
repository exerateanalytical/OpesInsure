import React, { useRef, useState } from "react";
import { Text } from "react-native";
import * as Crypto from "expo-crypto";
import { Button, Card, Chip, ChipRow, TextField } from "@/components/ui";
import { DetailRow } from "@/components/design";
import { EmptyState } from "@/components/StatePanel";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/** A book policy the intermediary may file for, with the policyholder's party id (claimant). */
export type ClaimablePolicy = { id: string; policy_number: string | null; customer_name: string; party_id: string };
export type AssistedClaimPayload = {
  policy_id: string;
  claimant_party_id: string;
  loss_occurred_at: string;
  loss_details: { description: string };
  loss_location?: string;
  estimated_loss_minor?: number;
  idempotency_key: string;
};

/**
 * Intermediary-assisted FNOL form (AGT-001 agent, BRK claims broker), shared by
 * both portals: the intermediary files, the insurer adjudicates. One idempotency
 * key per form instance so a retried submit cannot file twice. With several
 * candidate policies the user picks one first.
 */
export function AssistedClaimForm({
  policies,
  initialPolicyId,
  submit,
  onFiled,
}: {
  policies: ClaimablePolicy[];
  initialPolicyId?: string;
  submit: (payload: AssistedClaimPayload) => Promise<{ id: string }>;
  onFiled: (claimId: string) => void;
}) {
  const { t } = useTranslation();
  const [policyId, setPolicyId] = useState(policies.find((p) => p.id === initialPolicyId)?.id ?? (policies.length === 1 ? policies[0]!.id : ""));
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [description, setDescription] = useState("");
  const [location, setLocation] = useState("");
  const [amount, setAmount] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const key = useRef(Crypto.randomUUID());
  const p = policies.find((x) => x.id === policyId);
  const lossMs = Date.parse(`${date}T12:00:00`);
  const valid = !!p && description.trim().length >= 10 && !Number.isNaN(lossMs) && lossMs <= Date.now();
  if (!policies.length) return <EmptyState title={t("pdNotFound")} message={t("pdNotFoundBody")} />;
  return (
    <Card>
      {policies.length > 1 ? (
        <>
          <Text style={{ ...type.meta, color: colors.neutral600 }}>{t("brPickPolicy")}</Text>
          <ChipRow exclusive>
            {policies.map((x) => (
              <Chip key={x.id} role="tab" label={`${x.policy_number ?? "—"} · ${x.customer_name}`} selected={x.id === policyId} onPress={() => setPolicyId(x.id)} />
            ))}
          </ChipRow>
        </>
      ) : null}
      <DetailRow label={t("policies")} value={p?.policy_number ?? null} />
      <DetailRow label={t("pcCustomer")} value={p?.customer_name ?? null} />
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
          if (!p) return;
          setBusy(true);
          setError(null);
          try {
            const res = await submit({
              policy_id: p.id,
              claimant_party_id: p.party_id,
              loss_occurred_at: new Date(lossMs).toISOString(),
              loss_details: { description: description.trim() },
              loss_location: location.trim() || undefined,
              estimated_loss_minor: Number(amount) > 0 ? Math.round(Number(amount) * 100) : undefined,
              idempotency_key: key.current,
            });
            onFiled(res.id);
          } catch (e) {
            setError(e instanceof Error ? e.message : t("pdFileClaimFailed"));
          } finally {
            setBusy(false);
          }
        }}
      />
    </Card>
  );
}

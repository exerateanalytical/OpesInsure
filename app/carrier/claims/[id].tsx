import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, SectionTitle, StatusChip, TextField } from "@/components/ui";
import { ChoiceChips, errorMessage, Notice } from "@/components/portal/Workspace";
import { CarrierClaimDetail, CarrierWorkspaceApi, humanize, money, shortDate } from "@/api/partner";
import { colors, space, type } from "@/theme/tokens";

type Decision = "APPROVE" | "PARTIAL" | "DECLINE";

export default function CarrierClaimScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => CarrierWorkspaceApi.claim(String(id)), [id]);
  return (
    <Screen>
      <AppHeader title="Claim" subtitle="Decisions need a second insurer approver" back />
      <StatePanel {...q} onRetry={q.reload} loadingLabel="Loading claim…">
        {(c) => <ClaimBody claim={c} onChange={(n) => q.setData(n)} />}
      </StatePanel>
    </Screen>
  );
}

function ClaimBody({ claim: c, onChange }: { claim: CarrierClaimDetail; onChange: (c: CarrierClaimDetail) => void }) {
  const [note, setNote] = useState("");
  const [decision, setDecision] = useState<Decision | null>(null);
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [rationale, setRationale] = useState("");
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ text: string; tone: "ok" | "error" } | null>(null);
  const can = (a: CarrierClaimDetail["actions"][number]) => c.actions.includes(a);
  const run = async (key: string, fn: () => Promise<CarrierClaimDetail>, ok: string) => {
    setBusy(key);
    setMsg(null);
    try {
      onChange(await fn());
      setMsg({ text: ok, tone: "ok" });
    } catch (e) {
      setMsg({ text: errorMessage(e), tone: "error" });
    } finally {
      setBusy(null);
    }
  };
  const amountMinor = Math.round(Number(amount.replace(/\s/g, "")) * 100);
  const decisionReady =
    decision !== null &&
    reason.trim().length > 1 &&
    rationale.trim().length >= 5 &&
    (decision === "DECLINE" || amountMinor > 0);

  return (
    <>
      <Card>
        <View style={s.row}>
          <Text style={s.title}>{c.claim_number}</Text>
          <StatusChip label={humanize(c.status)} tone="info" />
        </View>
        <Text style={s.meta}>
          {c.customer_name} · policy {c.policy_number ?? "—"}
        </Text>
        <Text style={s.meta}>
          Loss {shortDate(c.loss_occurred_at)}
          {c.loss_location ? ` · ${c.loss_location}` : ""}
        </Text>
        {c.description ? <Text style={s.body}>{c.description}</Text> : null}
        {c.estimated_loss_minor !== null ? <Text style={s.meta}>Estimated {money(c.estimated_loss_minor)}</Text> : null}
        {c.approved_amount_minor !== null ? <Text style={s.meta}>Approved {money(c.approved_amount_minor)}</Text> : null}
      </Card>

      {can("acknowledge") ? (
        <Button
          label="Acknowledge claim"
          loading={busy === "ack"}
          onPress={() => run("ack", () => CarrierWorkspaceApi.acknowledgeClaim(c.id), "Claim acknowledged.")}
        />
      ) : null}

      {can("request_information") ? (
        <>
          <SectionTitle title="Request information" />
          <Card>
            <TextField label="What do you need from the claimant?" value={note} onChangeText={setNote} multiline />
            <Button
              label="Send request"
              variant="secondary"
              loading={busy === "info"}
              disabled={note.trim().length < 5}
              onPress={() =>
                run("info", () => CarrierWorkspaceApi.requestClaimInformation(c.id, note.trim()), "Information requested.")
              }
            />
          </Card>
        </>
      ) : null}

      {can("propose_decision") ? (
        <>
          <SectionTitle title="Propose a decision" />
          <Card>
            <ChoiceChips<Decision>
              label="Decision"
              value={decision}
              onChange={setDecision}
              options={[
                { value: "APPROVE", label: "Approve" },
                { value: "PARTIAL", label: "Partial" },
                { value: "DECLINE", label: "Decline" },
              ]}
            />
            {decision && decision !== "DECLINE" ? (
              <TextField label="Amount to pay (FCFA)" keyboardType="number-pad" value={amount} onChangeText={setAmount} />
            ) : null}
            <TextField label="Reason code" value={reason} onChangeText={setReason} autoCapitalize="characters" />
            <TextField label="Rationale" value={rationale} onChangeText={setRationale} multiline />
            <Text style={s.meta}>A second insurer user must approve before the decision takes effect.</Text>
            <Button
              label="Submit for approval"
              loading={busy === "propose"}
              disabled={!decisionReady}
              onPress={() =>
                run(
                  "propose",
                  () =>
                    CarrierWorkspaceApi.proposeClaimDecision(c.id, {
                      decision: decision as Decision,
                      approved_amount_minor: decision === "DECLINE" ? 0 : amountMinor,
                      reason_code: reason.trim().toUpperCase().replace(/\s+/g, "_"),
                      rationale: rationale.trim(),
                    }),
                  "Decision submitted for approval.",
                )
              }
            />
          </Card>
        </>
      ) : null}

      {c.pending_decision ? (
        <>
          <SectionTitle title="Decision awaiting approval" />
          <Card>
            <Text style={s.title}>
              {humanize(c.pending_decision.decision)}
              {c.pending_decision.decision !== "DECLINE" ? ` · ${money(c.pending_decision.approved_amount_minor)}` : ""}
            </Text>
            <Text style={s.meta}>{c.pending_decision.reason_code}</Text>
            <Text style={s.body}>{c.pending_decision.rationale}</Text>
            {can("approve_decision") ? (
              <Button
                label="Approve decision"
                loading={busy === "approve"}
                onPress={() =>
                  run(
                    "approve",
                    () => CarrierWorkspaceApi.approveClaimDecision(c.id, c.pending_decision!.id),
                    "Decision approved.",
                  )
                }
              />
            ) : (
              <Text style={s.meta}>
                {c.pending_decision.proposed_by_me
                  ? "You proposed this decision; another approver must confirm it."
                  : "An insurer administrator must approve this decision."}
              </Text>
            )}
          </Card>
        </>
      ) : null}

      <Notice text={msg?.text ?? null} tone={msg?.tone ?? "ok"} />

      {c.timeline.length > 0 ? (
        <>
          <SectionTitle title="History" />
          <Card>
            {c.timeline.map((e, i) => (
              <Text key={`${e.occurred_at}-${i}`} style={s.meta}>
                {shortDate(e.occurred_at)} · {humanize(e.to_status)}
              </Text>
            ))}
          </Card>
        </>
      ) : null}
    </>
  );
}

const s = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: space.x2 },
  title: { ...type.cardTitle, color: colors.navy950, flex: 1 },
  meta: { ...type.meta, color: colors.neutral600 },
  body: { ...type.body, color: colors.neutral700 },
});

import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Screen, SectionTitle, StatusChip, TextField } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { ChoiceChips, errorMessage, Notice } from "@/components/portal/Workspace";
import { useLoad } from "@/hooks/useLoad";
import { WorkspaceClaimsApi, type WorkspaceClaimDetail, type WorkspaceExpertAssignment } from "@/api/workspace";
import { money, shortDate } from "@/api/partner";
import { parseAmountMinor } from "@/lib/purchase";
import { adjusterEventPath, adjusterEventReady, canDo, decisionReady } from "@/lib/workspaceClaims";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

type Decision = "APPROVE" | "PARTIAL" | "DECLINE";

/** Stored decision codes (APPROVE / PARTIAL / DECLINE, older rows APPROVED / PARTIALLY_APPROVED / REJECTED). */
const decisionKey = (d: string): Decision => (d.startsWith("PARTIAL") ? "PARTIAL" : d.startsWith("APPROVE") ? "APPROVE" : "DECLINE");

/**
 * Phase-1 fix S (2026-09-30): workspace claim detail. Every action shown comes from the server (`actions`,
 * `transitions`, `expert_assignments[].available_events`), which already applied the caller's permissions and data
 * scope; the server re-checks each request.
 */
export default function WorkspaceClaim() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => WorkspaceClaimsApi.claim(String(id)), [id]);
  return (
    <Screen>
      <AppHeader title={t("wscDetailTitle")} subtitle={q.data?.claim_number} back />
      <StatePanel {...q} onRetry={q.reload}>
        {(c) => <ClaimBody claim={c} onChange={(n) => q.setData(n)} reload={() => void q.reload()} />}
      </StatePanel>
    </Screen>
  );
}

function ClaimBody({ claim: c, onChange, reload }: { claim: WorkspaceClaimDetail; onChange: (c: WorkspaceClaimDetail) => void; reload: () => void }) {
  const { t } = useTranslation();
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ text: string; tone: "ok" | "error" } | null>(null);
  const [note, setNote] = useState("");
  const [decision, setDecision] = useState<Decision | null>(null);
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [rationale, setRationale] = useState("");

  const run = async (key: string, fn: () => Promise<WorkspaceClaimDetail | unknown>, ok: string, refetch = false) => {
    setBusy(key);
    setMsg(null);
    try {
      const next = await fn();
      if (refetch) reload();
      else onChange(next as WorkspaceClaimDetail);
      setMsg({ text: ok, tone: "ok" });
    } catch (e) {
      setMsg({ text: errorMessage(e), tone: "error" });
    } finally {
      setBusy(null);
    }
  };
  const amountMinor = parseAmountMinor(amount);
  const nothing = !c.actions.length && !c.expert_assignments.some((a) => a.available_events.length);

  return (
    <>
      <Card>
        <View style={s.row}>
          <Text style={s.title}>{c.claim_number}</Text>
          <StatusChip label={c.status_label} tone="info" />
        </View>
        <Pair label={t("wscClaimant")} value={c.claimant_name} />
        <Pair label={t("wscPolicy")} value={c.policy_number} />
        <Pair label={t("wscLossDate")} value={shortDate(c.loss_occurred_at)} />
        <Pair label={t("wscLocation")} value={c.loss_location} />
        <Pair label={t("wscSubmitted")} value={shortDate(c.submitted_at)} />
        <Pair label={t("wscPriority")} value={c.priority} />
        <Pair label={t("wscEstimated")} value={c.estimated_loss_minor !== null ? money(c.estimated_loss_minor) : null} />
        <Pair label={t("wscReserve")} value={c.current_reserve_minor !== null ? money(c.current_reserve_minor) : null} />
        <Pair label={t("wscApproved")} value={c.approved_amount_minor !== null ? money(c.approved_amount_minor) : null} />
        <Text style={s.meta}>{c.assigned_to_me ? t("wscAssignedToMe") : c.assignee_name ? t("wscHandler", { name: c.assignee_name }) : t("wscUnassigned")}</Text>
        {c.description ? (
          <>
            <Text style={s.label}>{t("wscDescription")}</Text>
            <Text style={s.body}>{c.description}</Text>
          </>
        ) : null}
      </Card>

      <Notice text={msg?.text ?? null} tone={msg?.tone ?? "ok"} />
      {nothing ? <Text style={s.meta}>{t("wscNoActions")}</Text> : null}

      {canDo(c.actions, "assign_to_me") ? (
        <Button label={t("wscAssignToMe")} variant="secondary" loading={busy === "assign"} onPress={() => run("assign", () => WorkspaceClaimsApi.assignToMe(c.id), t("wscAssignedDone"))} />
      ) : null}

      {canDo(c.actions, "transition") && c.transitions.length ? (
        <>
          <SectionTitle title={t("wscMoveTitle")} />
          <Card>
            <TextField label={t("wscMoveNote")} value={note} onChangeText={setNote} multiline />
            {c.transitions.map((tr) => (
              <Button
                key={tr.to_status}
                label={tr.label}
                variant="secondary"
                loading={busy === `move-${tr.to_status}`}
                disabled={busy !== null}
                onPress={() => run(`move-${tr.to_status}`, () => WorkspaceClaimsApi.transition(c.id, tr.to_status, note), t("wscMovedDone")).then(() => setNote(""))}
              />
            ))}
          </Card>
        </>
      ) : null}

      {c.pending_decision ? (
        <>
          <SectionTitle title={t("wscPendingDecision")} />
          <Card>
            <Text style={s.title}>{t(`wscDecision_${decisionKey(c.pending_decision.decision)}` as "wscDecision_APPROVE")}</Text>
            <Text style={s.meta}>{`${money(c.pending_decision.approved_amount_minor)} · ${c.pending_decision.reason_code}`}</Text>
            <Text style={s.body}>{c.pending_decision.rationale}</Text>
            {c.pending_decision.proposed_by_me ? <Text style={s.meta}>{t("wscPendingMine")}</Text> : null}
            {canDo(c.actions, "approve_decision") ? (
              <Button label={t("wscApproveDecision")} loading={busy === "approve"} onPress={() => run("approve", () => WorkspaceClaimsApi.approveDecision(c.id, c.pending_decision!.id), t("wscDecisionApproved"))} />
            ) : null}
          </Card>
        </>
      ) : null}

      {canDo(c.actions, "propose_decision") ? (
        <>
          <SectionTitle title={t("wscDecisionTitle")} />
          <Card>
            <ChoiceChips<Decision>
              label={t("wscDecisionTitle")}
              value={decision}
              onChange={setDecision}
              options={[
                { value: "APPROVE", label: t("wscDecision_APPROVE") },
                { value: "PARTIAL", label: t("wscDecision_PARTIAL") },
                { value: "DECLINE", label: t("wscDecision_DECLINE") },
              ]}
            />
            {decision && decision !== "DECLINE" ? <TextField label={t("wscAmount")} value={amount} onChangeText={setAmount} keyboardType="numeric" /> : null}
            <TextField label={t("wscReasonCode")} value={reason} onChangeText={setReason} autoCapitalize="characters" />
            <TextField label={t("wscRationale")} value={rationale} onChangeText={setRationale} multiline />
            <Button
              label={t("wscSendDecision")}
              loading={busy === "decision"}
              disabled={!decisionReady(decision, reason, rationale, amountMinor)}
              onPress={() =>
                run(
                  "decision",
                  () =>
                    WorkspaceClaimsApi.proposeDecision(c.id, {
                      decision: decision!,
                      ...(decision === "DECLINE" ? {} : { approved_amount_minor: amountMinor ?? 0 }),
                      reason_code: reason.trim().toUpperCase(),
                      rationale: rationale.trim(),
                    }),
                  t("wscDecisionSent"),
                )
              }
            />
          </Card>
        </>
      ) : null}

      {c.expert_assignments.length ? (
        <>
          <SectionTitle title={t("wscAssignments")} />
          {c.expert_assignments.map((a) => (
            <AssignmentCard key={a.id} assignment={a} busy={busy} onRun={(key, path, body) => run(key, () => WorkspaceClaimsApi.adjusterMove(path, body), t("wscDone"), true)} />
          ))}
        </>
      ) : null}

      {c.timeline.length ? (
        <>
          <SectionTitle title={t("wscTimeline")} />
          <Card>
            {c.timeline.map((e, i) => (
              <View key={`${e.occurred_at}-${i}`} style={s.event}>
                <Text style={s.label}>{e.label}</Text>
                <Text style={s.meta}>{shortDate(e.occurred_at)}</Text>
              </View>
            ))}
          </Card>
        </>
      ) : null}
    </>
  );
}

function AssignmentCard({ assignment: a, busy, onRun }: { assignment: WorkspaceExpertAssignment; busy: string | null; onRun: (key: string, path: string, body: Record<string, unknown>) => void }) {
  const { t } = useTranslation();
  const [text, setText] = useState("");
  const [when, setWhen] = useState("");
  const [place, setPlace] = useState("");
  const [assessed, setAssessed] = useState("");
  const amountMinor = parseAmountMinor(assessed);
  const whenIso = when.trim() ? when.trim().replace(" ", "T") : "";
  const bodyFor = (event: string): Record<string, unknown> => {
    switch (event) {
      case "decline":
        return { reason: text.trim() };
      case "schedule_inspection":
        return { scheduled_for: whenIso, ...(place.trim() ? { location: place.trim() } : {}) };
      case "record_inspection":
        return text.trim() ? { notes: text.trim() } : {};
      case "submit_report":
        return { summary: text.trim(), assessed_loss_minor: amountMinor ?? 0 };
      default:
        return {};
    }
  };
  const needsText = a.available_events.some((e) => ["decline", "record_inspection", "submit_report"].includes(e));
  const textLabel = a.available_events.includes("submit_report") ? t("wscReportSummary") : a.available_events.includes("record_inspection") ? t("wscInspectionNotes") : t("wscDeclineReason");
  return (
    <Card>
      <View style={s.row}>
        <Text style={s.title}>{shortDate(a.assigned_at)}</Text>
        <StatusChip label={a.status_label} />
      </View>
      {a.instructions ? <Pair label={t("wscInstructions")} value={a.instructions} /> : null}
      {a.inspection_scheduled_for ? <Pair label={t("wscInspection")} value={[shortDate(a.inspection_scheduled_for), a.inspection_location].filter(Boolean).join(" · ")} /> : null}
      {a.available_events.includes("schedule_inspection") ? (
        <>
          <TextField label={t("wscInspectionWhen")} value={when} onChangeText={setWhen} />
          <TextField label={t("wscInspectionPlace")} value={place} onChangeText={setPlace} />
        </>
      ) : null}
      {needsText ? <TextField label={textLabel} value={text} onChangeText={setText} multiline /> : null}
      {a.available_events.includes("submit_report") ? <TextField label={t("wscAssessedLoss")} value={assessed} onChangeText={setAssessed} keyboardType="numeric" /> : null}
      {a.available_events.map((event) => {
        const path = adjusterEventPath(a.id, event);
        if (!path) return null;
        return (
          <Button
            key={event}
            label={t(`wscEvent_${event}` as "wscEvent_accept")}
            variant={event === "decline" ? "secondary" : "primary"}
            loading={busy === `${a.id}-${event}`}
            disabled={busy !== null || !adjusterEventReady(event, { text, when: whenIso, amountMinor })}
            onPress={() => onRun(`${a.id}-${event}`, path, bodyFor(event))}
          />
        );
      })}
    </Card>
  );
}

function Pair({ label, value }: { label: string; value: string | null | undefined }) {
  if (value === null || value === undefined || value === "") return null;
  return (
    <View style={s.pair}>
      <Text style={s.label}>{label}</Text>
      <Text style={s.body}>{value}</Text>
    </View>
  );
}

const s = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: space.x2 },
  title: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  label: { ...type.caption, color: colors.neutral600 },
  body: { ...type.body, color: colors.navy950 },
  pair: { gap: 2 },
  event: { flexDirection: "row", justifyContent: "space-between", gap: space.x2, paddingVertical: space.x1 },
});

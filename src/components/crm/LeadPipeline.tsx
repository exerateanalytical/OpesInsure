import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { Button, Card, SectionTitle, TextField } from "@/components/ui";
import { ChoiceChips, errorMessage, Notice } from "@/components/portal/Workspace";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { LEAD_ACTIVITY_TYPES, LEAD_STAGES, LeadActivityType, leadMoveError, leadMoves } from "@/lib/crm";
import type { LeadActivity } from "@/api/crm";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

/** Pipeline rail: every stage, the current one highlighted. */
export function LeadStageRail({ status }: { status: string }) {
  const { td } = useTranslation();
  return (
    <View style={s.rail} accessibilityRole="progressbar" accessibilityLabel={td(`leadStatus_${status}`, status)}>
      {LEAD_STAGES.map((st) => (
        <Text key={st} style={[s.stage, st === status && (st === "LOST" ? s.stageLost : s.stageOn)]}>
          {td(`leadStatus_${st}`, st)}
        </Text>
      ))}
    </View>
  );
}

/** Stage change limited to the server's next_statuses; LOST requires a reason. */
export function LeadStageMover({
  status,
  nextStatuses,
  lostReason,
  onMove,
}: {
  status: string;
  nextStatuses: string[] | undefined;
  lostReason?: string | null;
  onMove: (to: string, lostReason?: string) => Promise<void>;
}) {
  const { t, td } = useTranslation();
  const moves = leadMoves(status, nextStatuses);
  const [to, setTo] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ text: string; tone: "ok" | "error" } | null>(null);
  const invalid = leadMoveError(to, reason);
  return (
    <Card>
      <LeadStageRail status={status} />
      {status === "LOST" && lostReason ? <Text style={s.meta}>{t("agLostReasonShown", { reason: lostReason })}</Text> : null}
      {moves.length ? (
        <>
          <ChoiceChips<string>
            label={t("agLeadMoveTo")}
            value={to}
            onChange={setTo}
            options={moves.map((m) => ({ value: m, label: td(`leadStatus_${m}`, m) }))}
          />
          {to === "LOST" ? (
            <TextField label={t("agLostReason")} value={reason} onChangeText={setReason} error={invalid ? t("agLostReasonRequired") : undefined} />
          ) : null}
          <Button
            label={t("agLeadMoveTo")}
            variant="secondary"
            disabled={!to || !!invalid}
            loading={busy}
            onPress={async () => {
              if (!to) return;
              setBusy(true);
              setMsg(null);
              try {
                await onMove(to, to === "LOST" ? reason.trim() : undefined);
                setTo(null);
                setReason("");
                setMsg({ text: t("settingsSavedShort"), tone: "ok" });
              } catch (e) {
                setMsg({ text: errorMessage(e), tone: "error" });
              } finally {
                setBusy(false);
              }
            }}
          />
        </>
      ) : (
        <Text style={s.meta}>{t("agLeadNoMoves")}</Text>
      )}
      <Notice text={msg?.text ?? null} tone={msg?.tone ?? "ok"} />
    </Card>
  );
}

const FOLLOW_UPS = { none: 0, tomorrow: 1, three: 3, week: 7 } as const;
type FollowUp = keyof typeof FOLLOW_UPS;
const followUpIso = (k: FollowUp) => {
  if (!FOLLOW_UPS[k]) return null;
  const d = new Date(Date.now() + FOLLOW_UPS[k] * 86_400_000);
  d.setHours(9, 0, 0, 0);
  return d.toISOString();
};

/** Activity / follow-up diary of a lead (same diary for agent and broker endpoints). */
export function LeadActivityLog({
  leadId,
  load,
  add,
}: {
  leadId: string;
  load: (id: string) => Promise<LeadActivity[]>;
  add: (id: string, payload: { entry_type: LeadActivityType; body: string; follow_up_at?: string | null }) => Promise<LeadActivity>;
}) {
  const { t, td, date } = useTranslation();
  const q = useLoad(() => load(leadId), [leadId]);
  const [kind, setKind] = useState<LeadActivityType>("NOTE");
  const [body, setBody] = useState("");
  const [followUp, setFollowUp] = useState<FollowUp>("none");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ text: string; tone: "ok" | "error" } | null>(null);
  return (
    <>
      <SectionTitle title={t("agActivities")} />
      <Card>
        <ChoiceChips<LeadActivityType>
          label={t("agActivityType")}
          value={kind}
          onChange={setKind}
          options={LEAD_ACTIVITY_TYPES.map((k) => ({ value: k, label: td(`activity_${k}`, k) }))}
        />
        <TextField label={t("agActivityBody")} value={body} onChangeText={setBody} multiline />
        <ChoiceChips<FollowUp>
          label={t("agFollowUpAt")}
          value={followUp}
          onChange={setFollowUp}
          options={[
            { value: "none", label: t("fuNone") },
            { value: "tomorrow", label: t("fuTomorrow") },
            { value: "three", label: t("fu3Days") },
            { value: "week", label: t("fuWeek") },
          ]}
        />
        <Button
          label={t("agAddActivity")}
          variant="secondary"
          disabled={!body.trim()}
          loading={busy}
          onPress={async () => {
            setBusy(true);
            setMsg(null);
            try {
              const row = await add(leadId, { entry_type: kind, body: body.trim(), follow_up_at: followUpIso(followUp) });
              q.setData([row, ...(q.data ?? [])]);
              setBody("");
              setFollowUp("none");
            } catch (e) {
              setMsg({ text: errorMessage(e), tone: "error" });
            } finally {
              setBusy(false);
            }
          }}
        />
        <Notice text={msg?.text ?? null} tone={msg?.tone ?? "ok"} />
      </Card>
      <StatePanel {...q} onRetry={q.reload} emptyTitle={t("agActivities")} emptyMessage={t("agNoActivities")}>
        {(rows) => (
          <Card>
            {rows.map((a) => (
              <View key={a.id} style={s.entry}>
                <Text style={s.entryHead}>
                  {td(`activity_${a.entry_type}`, a.entry_type)} · {date(a.created_at, true)}
                </Text>
                <Text style={s.body}>{a.body}</Text>
                {a.follow_up_at ? <Text style={s.meta}>{t("agFollowUpDue", { date: date(a.follow_up_at, true) })}</Text> : null}
              </View>
            ))}
          </Card>
        )}
      </StatePanel>
    </>
  );
}

const s = StyleSheet.create({
  rail: { flexDirection: "row", flexWrap: "wrap", gap: space.x1 },
  stage: { ...type.meta, color: colors.neutral500, paddingHorizontal: space.x2, paddingVertical: 2, borderRadius: 999, borderWidth: 1, borderColor: colors.neutral200 },
  stageOn: { color: colors.white, backgroundColor: colors.navy900, borderColor: colors.navy900 },
  stageLost: { color: colors.white, backgroundColor: colors.dangerText, borderColor: colors.dangerText },
  meta: { ...type.meta, color: colors.neutral600 },
  body: { ...type.body, color: colors.neutral700 },
  entry: { gap: 2, paddingVertical: space.x2, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.neutral200 },
  entryHead: { ...type.meta, color: colors.navy950 },
});

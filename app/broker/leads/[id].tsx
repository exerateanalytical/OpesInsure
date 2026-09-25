import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, SectionTitle, StatusChip, TextField } from "@/components/ui";
import { ChoiceChips, errorMessage, Notice } from "@/components/portal/Workspace";
import { LeadActivityLog, LeadStageMover } from "@/components/crm/LeadPipeline";
import { DirectoryLead, LeadDirectoryApi } from "@/api/crm";
import { BrokerWorkspaceApi, shortDate } from "@/api/partner";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

const loadActivities = async (id: string) => (await LeadDirectoryApi.show(id)).activities ?? [];

/** Broker lead: pipeline moves, assignment to a team member, activity diary. */
export default function BrokerLeadDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => LeadDirectoryApi.show(String(id)), [id]);
  return (
    <Screen>
      <AppHeader title={t("agLead")} subtitle={t("brLeadsSubtitle")} back />
      <StatePanel {...q} onRetry={q.reload} loadingLabel={t("agLoadingLead")}>
        {(lead) => <Body lead={lead} onChange={(l) => q.setData({ ...lead, ...l })} />}
      </StatePanel>
    </Screen>
  );
}

function Body({ lead, onChange }: { lead: DirectoryLead; onChange: (l: DirectoryLead) => void }) {
  const { t, td } = useTranslation();
  const staff = useLoad(() => BrokerWorkspaceApi.staff(), []);
  const [assignee, setAssignee] = useState<string | null>(lead.assigned_user_id);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ text: string; tone: "ok" | "error" } | null>(null);
  const members = (staff.data?.members ?? []).filter((m) => m.status === "ACTIVE" || !m.status);
  const current = members.find((m) => m.user_id === lead.assigned_user_id);
  return (
    <>
      <Card>
        <Text style={s.name}>{lead.full_name}</Text>
        <Text style={s.meta}>{[lead.phone_e164, lead.city, lead.product_interest, lead.source].filter(Boolean).join(" · ")}</Text>
        <Text style={s.meta}>{t("agLeadAdded", { date: shortDate(lead.created_at) })}</Text>
        <Text style={s.meta}>{current ? t("brAssignedTo", { name: current.full_name }) : lead.assigned_user_id ? t("brAssignedTo", { name: "—" }) : t("brUnassigned")}</Text>
        <StatusChip label={td(`leadStatus_${lead.status}`, lead.status)} tone={lead.status === "CONVERTED" ? "success" : lead.status === "LOST" ? "danger" : "info"} />
      </Card>

      <SectionTitle title={t("agLeadStatus")} />
      <LeadStageMover
        status={lead.status}
        nextStatuses={lead.next_statuses}
        lostReason={lead.lost_reason}
        onMove={async (to, lost) => onChange(await LeadDirectoryApi.transition(lead.id, to, lost))}
      />

      <SectionTitle title={t("brLeadAssign")} />
      <Card>
        {members.length ? (
          <ChoiceChips<string>
            label={t("brAssignTo")}
            value={assignee}
            onChange={setAssignee}
            options={members.map((m) => ({ value: m.user_id, label: m.is_me ? `${m.full_name} (${t("brMe")})` : m.full_name }))}
          />
        ) : (
          <Text style={s.meta}>{staff.loading ? t("loading") : t("brNoStaff")}</Text>
        )}
        <TextField label={t("brAssignReason")} value={reason} onChangeText={setReason} />
        <Button
          label={t("brLeadAssign")}
          variant="secondary"
          disabled={!assignee || !reason.trim() || assignee === lead.assigned_user_id}
          loading={busy}
          onPress={async () => {
            setBusy(true);
            setMsg(null);
            try {
              onChange(await LeadDirectoryApi.assign(lead.id, { partner_id: lead.partner_id, assigned_user_id: assignee, reason: reason.trim() }));
              setReason("");
              setMsg({ text: t("brAssigned"), tone: "ok" });
            } catch (e) {
              setMsg({ text: errorMessage(e), tone: "error" });
            } finally {
              setBusy(false);
            }
          }}
        />
        <Notice text={msg?.text ?? null} tone={msg?.tone ?? "ok"} />
      </Card>

      <LeadActivityLog leadId={lead.id} load={loadActivities} add={LeadDirectoryApi.addActivity} />
    </>
  );
}

const s = StyleSheet.create({
  name: { ...type.cardTitle, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
});

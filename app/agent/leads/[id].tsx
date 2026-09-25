import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, SectionTitle, StatusChip, TextField } from "@/components/ui";
import { ConsentCheckbox, errorMessage, Notice } from "@/components/portal/Workspace";
import { AgentLead, AgentWorkspaceApi, LeadStatus, shortDate } from "@/api/partner";
import { LeadActivityApi } from "@/api/crm";
import { LeadActivityLog, LeadStageMover } from "@/components/crm/LeadPipeline";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

export default function LeadDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => AgentWorkspaceApi.lead(String(id)), [id]);
  return (
    <Screen>
      <AppHeader title={t("agLead")} subtitle={t("agLeadSubtitle")} back />
      <StatePanel {...q} onRetry={q.reload} loadingLabel={t("agLoadingLead")}>
        {(lead) => <LeadBody lead={lead} onChange={(l) => q.setData(l)} />}
      </StatePanel>
    </Screen>
  );
}

function LeadBody({ lead, onChange }: { lead: AgentLead; onChange: (l: AgentLead) => void }) {
  const { t, td } = useTranslation();
  const [notes, setNotes] = useState(lead.notes ?? "");
  const [consent, setConsent] = useState(false);
  const [busy, setBusy] = useState<"save" | "convert" | null>(null);
  const [msg, setMsg] = useState<{ text: string; tone: "ok" | "error" } | null>(null);
  const converted = lead.status === "CONVERTED";
  return (
    <>
      <Card>
        <Text style={s.name}>{lead.full_name}</Text>
        <Text style={s.meta}>{[lead.phone_e164, lead.city, lead.product_interest].filter(Boolean).join(" · ")}</Text>
        <Text style={s.meta}>{t("agLeadAdded", { date: shortDate(lead.created_at) })}</Text>
        <StatusChip label={td(`leadStatus_${lead.status}`, lead.status)} tone={converted ? "success" : lead.status === "LOST" ? "danger" : "info"} />
      </Card>

      {converted ? (
        <Card>
          <Text style={s.body}>{t("agLeadConvertedNotice")}</Text>
          {lead.converted_customer_id ? (
            <Button label={t("agOpenClient")} variant="secondary" onPress={() => router.replace(`/agent/clients/${lead.converted_customer_id}`)} />
          ) : null}
        </Card>
      ) : (
        <>
          <SectionTitle title={t("agLeadStatus")} />
          <LeadStageMover
            status={lead.status}
            nextStatuses={lead.next_statuses}
            lostReason={lead.lost_reason}
            onMove={async (to, lost_reason) =>
              onChange(await AgentWorkspaceApi.updateLead(lead.id, { status: to as Exclude<LeadStatus, "CONVERTED">, lost_reason: lost_reason ?? null }))
            }
          />
          <SectionTitle title={t("agFollowUp")} />
          <Card>
            <TextField label={t("agNotes")} value={notes} onChangeText={setNotes} multiline />
            <Button
              label={t("agSaveFollowUp")}
              variant="secondary"
              loading={busy === "save"}
              onPress={async () => {
                setBusy("save");
                setMsg(null);
                try {
                  onChange(await AgentWorkspaceApi.updateLead(lead.id, { notes }));
                  setMsg({ text: t("settingsSavedShort"), tone: "ok" });
                } catch (e) {
                  setMsg({ text: errorMessage(e), tone: "error" });
                } finally {
                  setBusy(null);
                }
              }}
            />
          </Card>

          <SectionTitle title={t("agConvertToClient")} />
          <Card>
            <Text style={s.body}>
              {t("agReadPrivacyConvert")}
            </Text>
            <ConsentCheckbox
              checked={consent}
              onChange={setConsent}
              label={t("agClientConsent")}
            />
            <Button
              label={t("agConvertProtected")}
              disabled={!consent}
              loading={busy === "convert"}
              onPress={async () => {
                setBusy("convert");
                setMsg(null);
                try {
                  const r = await AgentWorkspaceApi.convertLead(lead.id, { consent_confirmed: true });
                  onChange(r.lead);
                  router.replace(`/agent/clients/${r.client.id}`);
                } catch (e) {
                  setMsg({ text: errorMessage(e), tone: "error" });
                } finally {
                  setBusy(null);
                }
              }}
            />
          </Card>
        </>
      )}
      <Notice text={msg?.text ?? null} tone={msg?.tone ?? "ok"} />
      <LeadActivityLog leadId={lead.id} load={LeadActivityApi.agentList} add={LeadActivityApi.agentAdd} />
    </>
  );
}

const s = StyleSheet.create({
  name: { ...type.cardTitle, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  body: { ...type.body, color: colors.neutral600 },
});

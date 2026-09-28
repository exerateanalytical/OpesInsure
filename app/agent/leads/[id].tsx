import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ContactRound, UserPlus } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentButton, AgentCard, AgentEmptyState, AgentSection, AgentShell, AgentSkeleton, AgentStatusChip } from "@/components/agent";
import { TextField } from "@/components/ui";
import { ConsentCheckbox, errorMessage, Notice } from "@/components/portal/Workspace";
import { AgentLead, AgentWorkspaceApi, LeadStatus, shortDate } from "@/api/partner";
import { LeadActivityApi } from "@/api/crm";
import { LeadActivityLog, LeadStageMover } from "@/components/crm/LeadPipeline";
import { leadTone } from "@/lib/crm";
import { agentColors as c, agentType as T } from "@/theme/agent";
import { useTranslation } from "@/i18n";

export default function LeadDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => AgentWorkspaceApi.lead(String(id)), [id]);
  return (
    <AgentShell variant="drilldown" title={t("agLead")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      {q.loading && !q.data ? (
        <AgentSkeleton rows={4} height={72} />
      ) : q.error && !q.data ? (
        <AgentEmptyState icon={UserPlus} title={t("agLeadsLoadError")} body={t("loadErrorBody")} actionLabel={t("agHomeRetry")} onAction={q.reload} />
      ) : q.data ? (
        <LeadBody lead={q.data} onChange={(l) => q.setData(l)} />
      ) : null}
    </AgentShell>
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
      <AgentCard style={s.hero}>
        <Text style={s.name}>{lead.full_name}</Text>
        <AgentStatusChip status="Pending" label={td(`leadStatus_${lead.status}`, lead.status)} tone={leadTone(lead.status)} />
        <Text style={s.meta}>{[lead.phone_e164, lead.city, lead.product_interest].filter(Boolean).join(" · ")}</Text>
        <Text style={s.meta}>{t("agLeadAdded", { date: shortDate(lead.created_at) })}</Text>
      </AgentCard>

      {converted ? (
        <AgentCard style={s.gap}>
          <Text style={s.body}>{t("agLeadConvertedNotice")}</Text>
          {lead.converted_customer_id ? (
            <AgentButton icon={ContactRound} label={t("agOpenClient")} variant="secondary" onPress={() => router.replace(`/agent/clients/${lead.converted_customer_id}`)} />
          ) : null}
        </AgentCard>
      ) : (
        <>
          <AgentSection title={t("agLeadStatus")}>
            <LeadStageMover
              status={lead.status}
              nextStatuses={lead.next_statuses}
              lostReason={lead.lost_reason}
              onMove={async (to, lost_reason) =>
                onChange(await AgentWorkspaceApi.updateLead(lead.id, { status: to as Exclude<LeadStatus, "CONVERTED">, lost_reason: lost_reason ?? null }))
              }
            />
          </AgentSection>

          <AgentSection title={t("agFollowUp")}>
            <AgentCard style={s.gap}>
              <TextField label={t("agNotes")} value={notes} onChangeText={setNotes} multiline />
              <AgentButton
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
            </AgentCard>
          </AgentSection>

          <AgentSection title={t("agConvertToClient")}>
            <AgentCard style={s.gap}>
              <Text style={s.body}>{t("agReadPrivacyConvert")}</Text>
              <ConsentCheckbox checked={consent} onChange={setConsent} label={t("agClientConsent")} />
              <AgentButton
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
            </AgentCard>
          </AgentSection>
        </>
      )}
      <Notice text={msg?.text ?? null} tone={msg?.tone ?? "ok"} />
      <View>
        <LeadActivityLog leadId={lead.id} load={LeadActivityApi.agentList} add={LeadActivityApi.agentAdd} />
      </View>
    </>
  );
}

const s = StyleSheet.create({
  hero: { gap: 8, alignItems: "flex-start" },
  gap: { gap: 12 },
  name: { ...T.sectionTitle, color: c.heading },
  meta: { ...T.secondary, color: c.secondary },
  body: { ...T.body, color: c.secondary },
});

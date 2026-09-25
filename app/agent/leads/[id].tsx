import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, SectionTitle, StatusChip, TextField } from "@/components/ui";
import { ChoiceChips, ConsentCheckbox, errorMessage, Notice } from "@/components/portal/Workspace";
import { AgentLead, AgentWorkspaceApi, humanize, LeadStatus, shortDate } from "@/api/partner";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

type Manual = Exclude<LeadStatus, "CONVERTED">;

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
  const { t } = useTranslation();
  const [status, setStatus] = useState<Manual | null>(lead.status === "CONVERTED" ? null : lead.status);
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
        <Text style={s.meta}>Added {shortDate(lead.created_at)}</Text>
        <StatusChip label={humanize(lead.status)} tone={converted ? "success" : lead.status === "LOST" ? "danger" : "info"} />
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
          <SectionTitle title={t("agFollowUp")} />
          <Card>
            <ChoiceChips<Manual>
              label={t("agLeadStatus")}
              value={status}
              onChange={setStatus}
              options={[
                { value: "NEW", label: t("agLeadNew") },
                { value: "CONTACTED", label: t("agLeadContacted") },
                { value: "QUALIFIED", label: t("agLeadQualified") },
                { value: "LOST", label: t("agLeadLost") },
              ]}
            />
            <TextField label={t("agNotes")} value={notes} onChangeText={setNotes} multiline />
            <Button
              label={t("agSaveFollowUp")}
              variant="secondary"
              loading={busy === "save"}
              onPress={async () => {
                setBusy("save");
                setMsg(null);
                try {
                  onChange(await AgentWorkspaceApi.updateLead(lead.id, { status: status ?? undefined, notes }));
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
    </>
  );
}

const s = StyleSheet.create({
  name: { ...type.cardTitle, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  body: { ...type.body, color: colors.neutral600 },
});

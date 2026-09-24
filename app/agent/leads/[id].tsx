import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { AppHeader, Button, Card, Screen, SectionTitle, StatusChip, TextField } from "@/components/ui";
import { ChoiceChips, ConsentCheckbox, errorMessage, Notice } from "@/components/portal/Workspace";
import { AgentLead, AgentWorkspaceApi, humanize, LeadStatus, shortDate } from "@/api/partner";
import { colors, type } from "@/theme/tokens";

type Manual = Exclude<LeadStatus, "CONVERTED">;

export default function LeadDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => AgentWorkspaceApi.lead(String(id)), [id]);
  return (
    <Screen>
      <AppHeader title="Lead" subtitle="Follow up, then convert with the client's consent" back />
      <StatePanel {...q} onRetry={q.reload} loadingLabel="Loading lead…">
        {(lead) => <LeadBody lead={lead} onChange={(l) => q.setData(l)} />}
      </StatePanel>
    </Screen>
  );
}

function LeadBody({ lead, onChange }: { lead: AgentLead; onChange: (l: AgentLead) => void }) {
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
          <Text style={s.body}>This lead is now a protected client.</Text>
          {lead.converted_customer_id ? (
            <Button label="Open client" variant="secondary" onPress={() => router.replace(`/agent/clients/${lead.converted_customer_id}`)} />
          ) : null}
        </Card>
      ) : (
        <>
          <SectionTitle title="Follow-up" />
          <Card>
            <ChoiceChips<Manual>
              label="Lead status"
              value={status}
              onChange={setStatus}
              options={[
                { value: "NEW", label: "New" },
                { value: "CONTACTED", label: "Contacted" },
                { value: "QUALIFIED", label: "Qualified" },
                { value: "LOST", label: "Lost" },
              ]}
            />
            <TextField label="Notes" value={notes} onChangeText={setNotes} multiline />
            <Button
              label="Save follow-up"
              variant="secondary"
              loading={busy === "save"}
              onPress={async () => {
                setBusy("save");
                setMsg(null);
                try {
                  onChange(await AgentWorkspaceApi.updateLead(lead.id, { status: status ?? undefined, notes }));
                  setMsg({ text: "Saved.", tone: "ok" });
                } catch (e) {
                  setMsg({ text: errorMessage(e), tone: "error" });
                } finally {
                  setBusy(null);
                }
              }}
            />
          </Card>

          <SectionTitle title="Convert to client" />
          <Card>
            <Text style={s.body}>
              Read the privacy notice to the client and ask for their agreement. The consent record and
              its reference are created by the server when you convert.
            </Text>
            <ConsentCheckbox
              checked={consent}
              onChange={setConsent}
              label="The client agreed to OpesInsure processing their data to arrange insurance."
            />
            <Button
              label="Convert to protected client"
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

import React from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { UserPlus } from "lucide-react-native";
import { AgentShell } from "@/components/agent";
import { SchemaForm } from "@/components/forms/SchemaForm";
import { AgentWorkspaceApi } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentType as T } from "@/theme/agent";

const SEED = { phone_e164: "+237" };

/** New prospect from the server form agent_lead (GET /forms/agent_lead): city and product are pickers. */
export default function NewLead() {
  const { t } = useTranslation();
  return (
    <AgentShell variant="drilldown" title={t("leadNewTitle")}>
      <Text style={s.subtitle}>{t("leadNewSubtitle")}</Text>
      <SchemaForm
        form="agent_lead"
        initialValues={SEED}
        submitLabel={t("leadSave")}
        submitIcon={UserPlus}
        onSubmit={async (payload) => {
          const lead = await AgentWorkspaceApi.createLead(payload as Parameters<typeof AgentWorkspaceApi.createLead>[0]);
          router.replace(`/agent/leads/${lead.id}`);
        }}
      />
    </AgentShell>
  );
}

const s = StyleSheet.create({
  subtitle: { ...T.secondary, color: c.secondary, textAlign: "center", marginTop: -8 },
});

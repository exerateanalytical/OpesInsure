import React from "react";
import { router } from "expo-router";
import { AppHeader, Screen } from "@/components/ui";
import { SchemaForm } from "@/components/forms/SchemaForm";
import { AgentWorkspaceApi } from "@/api/partner";
import { useTranslation } from "@/i18n";

const SEED = { phone_e164: "+237" };

/** New prospect from the server form agent_lead (GET /forms/agent_lead): city and product are pickers. */
export default function NewLead() {
  const { t } = useTranslation();
  return (
    <Screen>
      <AppHeader title={t("leadNewTitle")} subtitle={t("leadNewSubtitle")} back />
      <SchemaForm
        form="agent_lead"
        initialValues={SEED}
        submitLabel={t("leadSave")}
        onSubmit={async (payload) => {
          const lead = await AgentWorkspaceApi.createLead(payload as Parameters<typeof AgentWorkspaceApi.createLead>[0]);
          router.replace(`/agent/leads/${lead.id}`);
        }}
      />
    </Screen>
  );
}

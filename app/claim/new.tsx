import React, { useMemo } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Siren } from "lucide-react-native";
import { AppHeader, Button, Screen } from "@/components/ui";
import { SchemaForm } from "@/components/forms/SchemaForm";
import { toCameroonIso } from "@/components/DateTimeField";
import { ClaimsApi } from "@/api/client";
import { useTranslation } from "@/i18n";
import type { MasterValue } from "@/lib/masterFields";
import { colors, type } from "@/theme/tokens";

const activeOnly = { policy_id: (v: MasterValue) => !v.attributes?.status || v.attributes.status === "ACTIVE" };

/**
 * First notice of loss from the server form claim_fnol (GET /forms/claim_fnol):
 * policy picker (GET /policies), claim category -> cause, date/time up to now,
 * region -> department -> city helpers composed into incident_location with
 * the landmark, then details. Submitted to POST /mobile/claims.
 */
export default function NewClaim() {
  const { t } = useTranslation();
  const { policyId } = useLocalSearchParams<{ policyId?: string }>();
  const seed = useMemo(
    () => ({ ...(typeof policyId === "string" && policyId ? { policy_id: policyId } : {}), incident_at: toCameroonIso(Date.now() - 3_600_000) }),
    [policyId],
  );

  return (
    <Screen>
      <AppHeader title={t("reportIncident")} subtitle={t("claimNewSubtitle")} back />
      <Button label={t("emergencyAssistance")} icon={Siren} variant="danger" onPress={() => router.push("/claim/emergency")} />
      <SchemaForm
        form="claim_fnol"
        initialValues={seed}
        endpointFilter={activeOnly}
        submitLabel={t("claimSubmit")}
        footer={<Text style={styles.note}>{t("claimNewNote")}</Text>}
        onSubmit={async (payload) => {
          // Form data is kept on failure so the customer can retry.
          const claim = await ClaimsApi.create(payload as Parameters<typeof ClaimsApi.create>[0]);
          router.replace({ pathname: "/claim/[id]", params: { id: claim.id } });
        }}
      />
    </Screen>
  );
}
const styles = StyleSheet.create({
  note: { ...type.meta, color: colors.neutral600 },
});

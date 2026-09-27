import React, { useMemo, useRef } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight } from "lucide-react-native";
import { Screen } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { SchemaForm } from "@/components/forms/SchemaForm";
import { toCameroonIso } from "@/components/DateTimeField";
import { ClaimWizardSteps } from "@/components/claims/ClaimWizardSteps";
import { PolicyChoiceCard } from "@/components/claims/PolicyChoiceCard";
import { usePolicies } from "@/hooks/usePolicies";
import { useInsurerLogo } from "@/components/claims/insurerLogo";
import { ClaimsApi } from "@/api/client";
import { claimCoordinates } from "@/lib/deviceLocation";
import type { DeviceFix } from "@/lib/locationMatch";
import { useTranslation } from "@/i18n";
import type { MasterValue } from "@/lib/masterFields";
import { colors, type } from "@/theme/tokens";

const activeOnly = { policy_id: (v: MasterValue) => !v.attributes?.status || v.attributes.status === "ACTIVE" };

/**
 * New claim, step 2 of 4 (design 31): first notice of loss from the server
 * form claim_fnol (GET /forms/claim_fnol) — claim category → cause, date/time
 * up to now, region → department → city composed into incident_location with
 * the landmark, then details. The policy chosen in step 1 is prefilled and
 * hidden. Submitted to POST /mobile/claims, then on to evidence (step 3).
 */
export default function NewClaimIncident() {
  const { t } = useTranslation();
  const { policyId } = useLocalSearchParams<{ policyId?: string }>();
  const id = typeof policyId === "string" ? policyId : "";
  const { policies } = usePolicies();
  const policy = policies.find((p) => p.id === id) ?? null;
  const logoFor = useInsurerLogo();
  // Device position (town/region are prefilled by the form; coordinates go to the incident details).
  const fix = useRef<DeviceFix | null>(null);
  const seed = useMemo(() => ({ ...(id ? { policy_id: id } : {}), incident_at: toCameroonIso(Date.now() - 3_600_000) }), [id]);

  return (
    <Screen>
      <BrandHeader title={t("claimIncidentTitle")} subtitle={t("claimIncidentSubtitle")} right="help" />
      <ClaimWizardSteps current={1} />
      {policy ? <PolicyChoiceCard policy={policy} logoUrl={logoFor(policy)} /> : null}
      <SchemaForm
        form="claim_fnol"
        initialValues={seed}
        endpointFilter={activeOnly}
        // Without a policy from step 1 the server picker is shown so the form stays complete.
        hide={id ? ["policy_id"] : []}
        submitLabel={t("continue")}
        submitIcon={ArrowRight}
        flat
        onLocation={(f) => (fix.current = f)}
        footer={<Text style={styles.note}>{t("claimNewNote")}</Text>}
        onSubmit={async (payload) => {
          // Form data is kept on failure so the customer can retry.
          // POST /mobile/claims accepts latitude/longitude, so the position goes in the create call.
          const claim = await ClaimsApi.create({ ...(payload as Parameters<typeof ClaimsApi.create>[0]), ...claimCoordinates(fix.current) });
          router.replace({ pathname: "/claim/[id]/evidence", params: { id: claim.id, wizard: "1" } });
        }}
      />
    </Screen>
  );
}
const styles = StyleSheet.create({
  note: { ...type.meta, color: colors.neutral600 },
});

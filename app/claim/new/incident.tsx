import React, { useMemo, useRef, useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, Save } from "lucide-react-native";
import { Button, Screen } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { SchemaForm } from "@/components/forms/SchemaForm";
import { toCameroonIso } from "@/components/DateTimeField";
import { ClaimWizardSteps } from "@/components/claims/ClaimWizardSteps";
import { PolicyChoiceCard } from "@/components/claims/PolicyChoiceCard";
import { ErrorState, LoadingState } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { usePolicies } from "@/hooks/usePolicies";
import { useLoad } from "@/hooks/useLoad";
import { useInsurerLogo } from "@/components/claims/insurerLogo";
import { CustomerApi, type ClaimDraft } from "@/api/customer";
import { claimCoordinates } from "@/lib/deviceLocation";
import { clientStateOf, formState, incidentDraftInput } from "@/lib/claimDraft";
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
 * hidden. Continue saves the answers on the claim draft (PATCH
 * /mobile/claims/drafts/{id}); nothing is sent to the insurer until step 4.
 * "Save draft" keeps the answers as entered and returns to My claims.
 */
export default function NewClaimIncident() {
  const { t } = useTranslation();
  const { policyId, draftId, from } = useLocalSearchParams<{ policyId?: string; draftId?: string; from?: string }>();
  const draftKey = typeof draftId === "string" ? draftId : "";
  const draft = useLoad<ClaimDraft | null>(() => (draftKey ? CustomerApi.claimDraft(draftKey) : Promise.resolve(null)), [draftKey]);
  const id = (typeof policyId === "string" && policyId) || draft.data?.policy_id || "";
  const { policies } = usePolicies();
  const policy = policies.find((p) => p.id === id) ?? null;
  const logoFor = useInsurerLogo();
  // Device position (town/region are prefilled by the form; coordinates go to the incident details).
  const fix = useRef<DeviceFix | null>(null);
  const latest = useRef<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<unknown>(null);
  const saved = clientStateOf(draft.data).form;
  const seed = useMemo(
    () => ({ incident_at: toCameroonIso(Date.now() - 3_600_000), ...(saved ?? {}), ...(id ? { policy_id: id } : {}) }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [id, JSON.stringify(saved ?? {})],
  );

  /** Creates the draft when this step was opened without one (older links), else updates it. */
  const save = async (input: Record<string, unknown>) =>
    draftKey
      ? CustomerApi.updateClaimDraft(draftKey, input)
      : CustomerApi.createClaimDraft({ ...(id ? { policy_id: id } : {}), ...input });

  const saveAndLeave = async () => {
    setSaving(true);
    setSaveError(null);
    try {
      await save({ client_state: { form: formState(latest.current), step: "incident" } });
      router.replace({ pathname: "/(customer)/(tabs)/claims" as never, params: { draftSaved: "1" } });
    } catch (e) {
      setSaveError(e);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Screen>
      <BrandHeader title={t("claimIncidentTitle")} subtitle={t("claimIncidentSubtitle")} right="help" />
      <ClaimWizardSteps current={1} />
      {policy ? <PolicyChoiceCard policy={policy} logoUrl={logoFor(policy)} /> : null}
      {draftKey && draft.loading && !draft.data ? (
        <LoadingState label={t("loading")} />
      ) : draftKey && draft.error && !draft.data ? (
        <ErrorState error={draft.error} onRetry={() => void draft.reload()} />
      ) : (
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
          onValues={(v) => (latest.current = v)}
          footer={
            <>
              <Text style={styles.note}>{t("claimDraftNote")}</Text>
              {saveError ? <ErrorCard error={saveError} fallback={t("claimDraftSaveFailed")} /> : null}
              <Button label={t("claimSaveDraft")} icon={Save} variant="tertiary" loading={saving} disabled={saving} onPress={() => void saveAndLeave()} />
            </>
          }
          onSubmit={async (payload, ctx) => {
            // Form data is kept on failure so the customer can retry. Only the draft is saved here.
            const saved = await save(incidentDraftInput(payload, ctx.values, claimCoordinates(fix.current), "evidence"));
            // Opened from the review's Edit: back to that review (it re-reads the draft).
            if (from === "review" && router.canGoBack()) router.back();
            else router.push({ pathname: "/claim/new/evidence" as never, params: { draftId: saved.id } });
          }}
        />
      )}
    </Screen>
  );
}
const styles = StyleSheet.create({
  note: { ...type.meta, color: colors.neutral600 },
});

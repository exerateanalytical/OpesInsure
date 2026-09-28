import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { CircleCheck, CircleDashed, ShieldCheck } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentButton, AgentCard, AgentEmptyState, AgentSection, AgentShell, AgentSkeleton, AgentStatusChip, type AgentStatusKey } from "@/components/agent";
import { TextField } from "@/components/ui";
import { AgentApi, type AgentProfile } from "@/api/client";
import { useTranslation } from "@/i18n";
import { agentProfileStepUpPurpose } from "@/lib/stepUpFlow";
import { STEP_UP_CANCELLED, withStepUp } from "@/security/step-up";
import { agentColors as c, agentIcon, agentType as T } from "@/theme/agent";

const STATUS: Record<AgentProfile["status"], AgentStatusKey> = {
  ACTIVE: "Active",
  SUSPENDED: "Suspended",
  PENDING_REVIEW: "Pending Verification",
  DRAFT: "Action Required",
};

/** Agent information + payout details (drill-down form, sticky Save; a payout number change needs step-up). */
export default function AgentOnboarding() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentApi.profile(), []);
  const x = q.data;
  const setX = q.setData;
  // Last saved payout number: a change to it needs a PAYOUT_DESTINATION_CHANGE step-up.
  const [savedMomo, setSavedMomo] = React.useState<string | null | undefined>(undefined);
  React.useEffect(() => {
    if (x && savedMomo === undefined) setSavedMomo(x.momo_phone_e164 ?? null);
  }, [x, savedMomo]);
  const [saveError, setSaveError] = React.useState<string | null>(null);
  const [saving, setSaving] = React.useState(false);

  const save = async () => {
    if (!x) return;
    setSaveError(null);
    setSaving(true);
    try {
      const purpose = agentProfileStepUpPurpose({ momo_phone_e164: savedMomo }, x);
      const next = await withStepUp(purpose, () => AgentApi.submitProfile(x));
      if (next === STEP_UP_CANCELLED) return;
      setX(next);
      setSavedMomo(next.momo_phone_e164 ?? null);
    } catch (e) {
      setSaveError(e instanceof Error ? e.message : t("profileSaveFailed"));
    } finally {
      setSaving(false);
    }
  };

  return (
    <AgentShell
      variant="drilldown"
      title={t("agAgentVerification")}
      footer={x ? <AgentButton label={t("agSaveVerification")} loading={saving} onPress={() => void save()} /> : undefined}
    >
      {!x ? (
        q.loading ? (
          <AgentSkeleton rows={4} height={72} />
        ) : (
          <AgentEmptyState icon={ShieldCheck} title={t("agProfileLoadError")} body={t("loadErrorBody")} actionLabel={t("agHomeRetry")} onAction={q.reload} />
        )
      ) : (
        <>
          <AgentCard style={s.hero}>
            <Text style={s.name}>{x.full_name || "—"}</Text>
            <Text style={s.meta}>{x.agent_code}</Text>
            <AgentStatusChip status={STATUS[x.status] ?? "Pending Verification"} />
          </AgentCard>

          <AgentSection title={t("agentAgentInfo")}>
            <AgentCard style={s.form}>
              <TextField label={t("agFullLegalName")} value={x.full_name} onChangeText={(full_name) => setX({ ...x, full_name })} />
              <TextField label={t("agNationalId")} value={x.national_id_number} onChangeText={(national_id_number) => setX({ ...x, national_id_number })} />
            </AgentCard>
          </AgentSection>

          <AgentSection title={t("agentPayout")}>
            <AgentCard style={s.form}>
              <TextField label={t("agCommissionMomo")} value={x.momo_phone_e164} keyboardType="phone-pad" onChangeText={(momo_phone_e164) => setX({ ...x, momo_phone_e164 })} />
            </AgentCard>
          </AgentSection>

          {x.compliance_items.length ? (
            <AgentSection title={t("agOnbCompliance")}>
              <AgentCard padded={false}>
                {x.compliance_items.map((i, n) => {
                  const done = i.status === "COMPLETE";
                  const Icon = done ? CircleCheck : CircleDashed;
                  return (
                    <View key={i.label} style={[s.row, n > 0 && s.divider]} accessible accessibilityLabel={`${i.label}, ${done ? t("agentSt_Verified") : t("agentSt_Pending")}`}>
                      <Icon size={agentIcon.row} color={agentIcon.color} strokeWidth={agentIcon.stroke} />
                      <Text style={s.rowTitle}>{i.label}</Text>
                      <AgentStatusChip status={done ? "Verified" : "Pending"} />
                    </View>
                  );
                })}
              </AgentCard>
            </AgentSection>
          ) : null}

          {saveError ? (
            <AgentCard tone="danger">
              <Text accessibilityRole="alert" style={s.error}>{saveError}</Text>
            </AgentCard>
          ) : null}
        </>
      )}
    </AgentShell>
  );
}

const s = StyleSheet.create({
  hero: { gap: 6, alignItems: "flex-start" },
  name: { ...T.sectionTitle, color: c.heading },
  meta: { ...T.secondary, color: c.secondary },
  form: { gap: 4 },
  row: { minHeight: 62, flexDirection: "row", alignItems: "center", gap: 12, paddingHorizontal: 16, paddingVertical: 10 },
  divider: { borderTopWidth: 1, borderTopColor: c.border },
  rowTitle: { ...T.body, color: c.text, flex: 1 },
  error: { ...T.body, color: c.danger },
});

import React, { useState } from "react";
import { Alert, Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight } from "lucide-react-native";
import { Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { BrandHeader, CtaBar, HeroCard } from "@/components/design";
import { claimPolicy, policyLine, policyTitle, productIcon, providerName } from "@/components/claims/claimProduct";
import { useInsurerLogo } from "@/components/claims/insurerLogo";
import { usePolicies } from "@/hooks/usePolicies";
import { claimStatusKey, claimTone } from "@/lib/claimStatus";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ApiError, ClaimIncidentDetails, ClaimsApi, ClaimsCompletionApi } from "@/api/client";
import { OfflineVault } from "@/offline/vault";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { CopyKey } from "@/i18n/strings";
import { colors, radius, space, type } from "@/theme/tokens";

const kinds = ["COLLISION", "THEFT", "FIRE", "GLASS_DAMAGE", "FLOOD", "OTHER"];
type Flag = "injuries_reported" | "vehicle_drivable" | "towing_required";
const flags: [Flag, CopyKey][] = [
  ["injuries_reported", "incidentInjuries"],
  ["vehicle_drivable", "incidentDrivable"],
  ["towing_required", "incidentTowing"],
];

export default function Incident() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const { data, setData: setX, loading, error, reload } = useLoad(() => ClaimsCompletionApi.incident(id), [id]);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<unknown>(null);
  const save = async (x: ClaimIncidentDetails) => {
    setSaving(true);
    setSaveError(null);
    try {
      await ClaimsCompletionApi.saveIncident(id, x);
      router.push(`/claim/${id}/parties`);
    } catch (e) {
      if (e instanceof ApiError && e.status === 0) {
        try {
          await OfflineVault.enqueue({
            kind: "DRAFT",
            resource: "Claim incident draft",
            resource_id: id,
            method: "PUT",
            path: `/mobile/claims/${id}/incident`,
            payload: { ...x },
          });
          Alert.alert(t("incidentDraftSaved"), t("incidentDraftBody"), [
            { text: t("viewSync"), onPress: () => router.push("/sync") },
          ]);
        } catch (queueError) {
          setSaveError(queueError);
        }
        return;
      }
      setSaveError(e);
    } finally {
      setSaving(false);
    }
  };
  const claim = useLoad(() => ClaimsApi.show(id), [id]);
  const { policies } = usePolicies();
  const logoFor = useInsurerLogo();
  const c = claim.data;
  const policy = c ? policies.find((p) => p.id === c.policy_id) ?? claimPolicy(c) : null;
  const title = policyTitle(policy, t("claimPolicyLabel"));
  return (
    <Screen
      footer={
        data ? (
          <CtaBar>
            <Button label={t("incidentSave")} icon={ArrowRight} loading={saving} onPress={() => void save(data)} />
          </CtaBar>
        ) : undefined
      }
    >
      <BrandHeader title={t("incidentTitle")} subtitle={t("incidentSubtitle")} right="help" />
      {c ? (
        <HeroCard
          icon={productIcon(title, policyLine(policy))}
          title={title}
          lines={[c.claim_number, policy?.policy_number ? t("claimPolicyNo", { number: policy.policy_number }) : null]}
          provider={providerName(policy)}
          providerLogo={logoFor(c, policy)}
          chip={<StatusChip label={td(claimStatusKey(c.status), c.status)} tone={claimTone(c.status)} />}
        />
      ) : null}
      <StatePanel loading={loading} error={error} data={data} onRetry={() => void reload()} isEmpty={() => false} loadingLabel={t("incidentLoading")}>
        {(x) => (
          <Card>
            <Text style={s.label}>{t("incidentType")}</Text>
            <View style={s.wrap} accessibilityRole="radiogroup">
              {kinds.map((k) => (
                <Pressable
                  key={k}
                  accessibilityRole="radio"
                  accessibilityState={{ selected: x.incident_type === k }}
                  style={[s.option, x.incident_type === k && s.selected]}
                  onPress={() => setX({ ...x, incident_type: k })}
                >
                  <Text style={[s.optionText, x.incident_type === k && s.selectedText]}>{td(`incidentKind_${k}`, k)}</Text>
                </Pressable>
              ))}
            </View>
            <TextField
              label={t("incidentPoliceNumber")}
              value={x.police_report_number ?? ""}
              onChangeText={(v) => setX({ ...x, police_report_number: v })}
            />
            {flags.map(([k, label]) => (
              <View key={k} style={s.toggle}>
                <Text style={s.grow}>{t(label)}</Text>
                <View style={s.segment} accessibilityRole="switch" accessibilityLabel={t(label)} accessibilityState={{ checked: !!x[k] }}>
                  {[true, false].map((v) => (
                    <Pressable
                      key={String(v)}
                      accessibilityRole="button"
                      accessibilityLabel={`${t(label)}: ${v ? t("yes") : t("no")}`}
                      accessibilityState={{ selected: !!x[k] === v }}
                      onPress={() => setX({ ...x, [k]: v })}
                      style={[s.segBtn, !!x[k] === v && s.segOn]}
                    >
                      <Text style={[s.segText, !!x[k] === v && s.segTextOn]}>{v ? t("yes") : t("no")}</Text>
                    </Pressable>
                  ))}
                </View>
              </View>
            ))}
            {saveError ? <ErrorCard error={saveError} fallback={t("errGeneric")} /> : null}
          </Card>
        )}
      </StatePanel>
    </Screen>
  );
}
const s = StyleSheet.create({
  label: { ...type.label, color: colors.navy950 },
  wrap: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  option: {
    minHeight: 44,
    justifyContent: "center",
    paddingHorizontal: space.x4,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.pill,
    backgroundColor: colors.white,
  },
  optionText: { ...type.label, color: colors.navy950 },
  selected: { borderColor: colors.blue600, backgroundColor: colors.blue600 },
  selectedText: { color: colors.white },
  toggle: {
    minHeight: 56,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    borderBottomWidth: 1,
    borderBottomColor: colors.neutral100,
  },
  grow: { ...type.body, flex: 1, color: colors.navy950, fontWeight: "600" },
  segment: { flexDirection: "row", borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card, overflow: "hidden", backgroundColor: colors.white },
  segBtn: { minWidth: 56, minHeight: 44, paddingHorizontal: space.x3, alignItems: "center", justifyContent: "center" },
  segOn: { backgroundColor: colors.blue600 },
  segText: { ...type.label, color: colors.navy950 },
  segTextOn: { color: colors.white },
});

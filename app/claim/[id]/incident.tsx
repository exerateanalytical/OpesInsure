import React, { useState } from "react";
import { Alert, Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { ApiError, ClaimIncidentDetails, ClaimsCompletionApi } from "@/api/client";
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
  return (
    <Screen>
      <AppHeader title={t("incidentTitle")} subtitle={t("incidentSubtitle")} back />
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
                  <Text style={s.optionText}>{td(`incidentKind_${k}`, k)}</Text>
                </Pressable>
              ))}
            </View>
            <TextField
              label={t("incidentPoliceNumber")}
              value={x.police_report_number ?? ""}
              onChangeText={(v) => setX({ ...x, police_report_number: v })}
            />
            {flags.map(([k, label]) => (
              <Pressable
                key={k}
                accessibilityRole="switch"
                accessibilityLabel={t(label)}
                accessibilityState={{ checked: !!x[k] }}
                style={s.toggle}
                onPress={() => setX({ ...x, [k]: !x[k] })}
              >
                <Text style={s.grow}>{t(label)}</Text>
                <Text style={s.optionText}>{x[k] ? t("yes") : t("no")}</Text>
              </Pressable>
            ))}
            <Button label={t("incidentSave")} loading={saving} onPress={() => void save(x)} />
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
    paddingHorizontal: space.x3,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.pill,
  },
  optionText: { ...type.label, color: colors.navy950 },
  selected: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  toggle: {
    minHeight: 50,
    flexDirection: "row",
    alignItems: "center",
    borderBottomWidth: 1,
    borderBottomColor: colors.neutral100,
  },
  grow: { ...type.body, flex: 1, color: colors.navy950 },
});

import React, { useEffect, useState } from "react";
import { LoadingState } from '@/components/StatePanel';
import { Alert, Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { ApiError, ClaimIncidentDetails, ClaimsCompletionApi } from "@/api/client";
import { OfflineVault } from "@/offline/vault";
import { colors, radius, space, type } from "@/theme/tokens";
const kinds = ["COLLISION", "THEFT", "FIRE", "GLASS_DAMAGE", "FLOOD", "OTHER"];
export default function Incident() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [x, setX] = useState<ClaimIncidentDetails>();
  useEffect(() => {
    ClaimsCompletionApi.incident(id).then(setX);
  }, [id]);
  if (!x)
    return (
      <Screen>
        <AppHeader title="Incident details" back />
        <LoadingState label="Loading incident…" />
      </Screen>
    );
  const toggle = (
    k: "injuries_reported" | "vehicle_drivable" | "towing_required",
  ) => setX({ ...x, [k]: !x[k] });
  return (
    <Screen>
      <AppHeader
        title="Incident details"
        subtitle="Confirm facts before the declaration"
        back
      />
      <Card>
        <Text style={s.label}>Incident type</Text>
        <View style={s.wrap}>
          {kinds.map((k) => (
            <Pressable
              key={k}
              style={[s.option, x.incident_type === k && s.selected]}
              onPress={() => setX({ ...x, incident_type: k })}
            >
              <Text>{k.replaceAll("_", " ")}</Text>
            </Pressable>
          ))}
        </View>
        <TextField
          label="Police report number (if issued)"
          value={x.police_report_number ?? ""}
          onChangeText={(v) => setX({ ...x, police_report_number: v })}
        />
        {(
          [
            ["injuries_reported", "Injuries reported"],
            ["vehicle_drivable", "Vehicle can be driven safely"],
            ["towing_required", "Towing required"],
          ] as const
        ).map(([k, label]) => (
          <Pressable key={k} style={s.toggle} onPress={() => toggle(k)}>
            <Text style={s.grow}>{label}</Text>
            <Text>{x[k] ? "YES" : "NO"}</Text>
          </Pressable>
        ))}
        <Button
          label="Save and add involved people"
          onPress={async () => {
            try {
              await ClaimsCompletionApi.saveIncident(id, x);
              router.push(`/claim/${id}/parties`);
            } catch (error) {
              if (error instanceof ApiError && error.status === 0) {
                await OfflineVault.enqueue({
                  kind: "DRAFT",
                  resource: "Claim incident draft",
                  resource_id: id,
                  method: "PUT",
                  path: `/mobile/claims/${id}/incident`,
                  payload: { ...x },
                });
                Alert.alert(
                  "Draft saved securely",
                  "This incident remains a draft until the server acknowledges it.",
                  [{ text: "View sync", onPress: () => router.push("/sync") }],
                );
                return;
              }
              throw error;
            }
          }}
        />
      </Card>
    </Screen>
  );
}
const s = StyleSheet.create({
  label: { ...type.label, color: colors.navy950 },
  wrap: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  option: {
    padding: space.x3,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.pill,
  },
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

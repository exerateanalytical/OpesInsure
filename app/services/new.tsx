import React, { useState } from "react";
import { router } from "expo-router";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { PolicyServicesApi } from "@/api/client";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
const types = [
  "ADDRESS_CHANGE",
  "VEHICLE_CHANGE",
  "DRIVER_CHANGE",
  "CANCELLATION_REVIEW",
  "NO_CLAIMS_CERTIFICATE",
];
export default function NewService() {
  const { t, td } = useTranslation();
  const [typeValue, setType] = useState(types[0]!);
  const [reason, setReason] = useState("");
  return (
    <Screen>
      <AppHeader
        title={t("svcNewTitle")}
        subtitle={t("svcNewSubtitle")}
        back
      />
      <Card>
        <Text style={s.label}>{t("svcType")}</Text>
        <View style={s.wrap}>
          {types.map((x) => (
            <Pressable
              key={x}
              accessibilityRole="radio"
              accessibilityState={{ selected: x === typeValue }}
              onPress={() => setType(x)}
              style={[s.option, x === typeValue && s.selected]}
            >
              <Text>{td(`svcType_${x}`, x)}</Text>
            </Pressable>
          ))}
        </View>
        <TextField
          label={t("svcReason")}
          multiline
          value={reason}
          onChangeText={setReason}
        />
        <Button
          label={t("svcSubmit")}
          disabled={reason.trim().length < 10}
          onPress={async () => {
            const x = await PolicyServicesApi.create({
              policy_id: "policy-active",
              type: typeValue,
              reason,
            });
            router.replace(`/services/${x.id}`);
          }}
        />
      </Card>
    </Screen>
  );
}
const s = StyleSheet.create({
  label: { ...type.label, color: colors.navy950 },
  wrap: { gap: space.x2 },
  option: {
    minHeight: 44,
    padding: space.x3,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.control,
    justifyContent: "center",
  },
  selected: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
});

import React, { useState } from "react";
import { Linking, Pressable, StyleSheet, Text } from "react-native";
import { Ambulance, Phone, ShieldAlert, Truck } from "lucide-react-native";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { ClaimsCompletionApi } from "@/api/client";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
const options = [
  ["MEDICAL", Ambulance, "emMedical"],
  ["POLICE", ShieldAlert, "emPolice"],
  ["TOWING", Truck, "emTowing"],
] as const;
export default function Emergency() {
  const { t } = useTranslation();
  const [service, setService] = useState<"MEDICAL" | "POLICE" | "TOWING">(
    "TOWING",
  );
  const [location, setLocation] = useState("");
  const [phone, setPhone] = useState("+237");
  const [reference, setReference] = useState("");
  return (
    <Screen>
      <AppHeader
        title={t("emTitle")}
        subtitle={t("emSubtitle")}
        back
      />
      <Card feature>
        <StatusChip label={t("emChip")} tone="danger" />
        <Text style={s.body}>{t("emBody")}</Text>
        <Button
          label={t("emCall")}
          icon={Phone}
          variant="danger"
          onPress={() => Linking.openURL("tel:112")}
        />
      </Card>
      <Card>
        {options.map(([key, Icon, label]) => (
          <Pressable
            key={key}
            accessibilityRole="radio"
            accessibilityState={{ selected: service === key }}
            style={[s.option, service === key && s.selected]}
            onPress={() => setService(key)}
          >
            <Icon size={22} color={colors.blue600} />
            <Text>{t(label)}</Text>
          </Pressable>
        ))}
        <TextField
          label={t("emLocation")}
          value={location}
          onChangeText={setLocation}
        />
        <TextField
          label={t("emCallback")}
          keyboardType="phone-pad"
          value={phone}
          onChangeText={setPhone}
        />
        <Button
          label={t("emRequest")}
          disabled={location.trim().length < 4 || phone.length < 8}
          onPress={async () =>
            setReference(
              (
                await ClaimsCompletionApi.requestEmergencyAssistance({
                  policy_id: "policy-active",
                  service,
                  location,
                  callback_phone: phone,
                })
              ).reference,
            )
          }
        />
        {reference ? <Text>{t("emReference", { reference })}</Text> : null}
      </Card>
    </Screen>
  );
}
const s = StyleSheet.create({
  body: { ...type.body, color: colors.neutral700 },
  option: {
    minHeight: 52,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    padding: space.x3,
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.control,
  },
  selected: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
});

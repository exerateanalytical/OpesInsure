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
const options = [
  ["MEDICAL", Ambulance, "Medical emergency"],
  ["POLICE", ShieldAlert, "Police assistance"],
  ["TOWING", Truck, "Vehicle towing"],
] as const;
export default function Emergency() {
  const [service, setService] = useState<"MEDICAL" | "POLICE" | "TOWING">(
    "TOWING",
  );
  const [location, setLocation] = useState("");
  const [phone, setPhone] = useState("+237");
  const [reference, setReference] = useState("");
  return (
    <Screen>
      <AppHeader
        title="Emergency assistance"
        subtitle="Protect people first; report the claim when safe"
        back
      />
      <Card feature>
        <StatusChip label="EMERGENCY" tone="danger" />
        <Text style={s.body}>
          For immediate danger, serious injury or fire, call the competent
          emergency service directly. OpesInsure does not replace emergency
          authorities.
        </Text>
        <Button
          label="Call emergency services"
          icon={Phone}
          variant="danger"
          onPress={() => Linking.openURL("tel:112")}
        />
      </Card>
      <Card>
        {options.map(([key, Icon, label]) => (
          <Pressable
            key={key}
            style={[s.option, service === key && s.selected]}
            onPress={() => setService(key)}
          >
            <Icon size={22} color={colors.blue600} />
            <Text>{label}</Text>
          </Pressable>
        ))}
        <TextField
          label="Current location or landmark"
          value={location}
          onChangeText={setLocation}
        />
        <TextField
          label="Callback phone"
          keyboardType="phone-pad"
          value={phone}
          onChangeText={setPhone}
        />
        <Button
          label="Request insured assistance"
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
        {reference ? <Text>Assistance reference: {reference}</Text> : null}
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

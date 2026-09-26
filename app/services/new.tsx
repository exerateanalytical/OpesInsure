import React, { useState } from "react";
import { router } from "expo-router";
import { StyleSheet, View } from "react-native";
import { CarFront, FileCheck, FileX, LucideIcon, MapPin, UserRound } from "lucide-react-native";
import { Button, Card, Screen, TextField } from "@/components/ui";
import { BrandHeader, CtaBar, RadioCard, SectionHeading } from "@/components/design";
import { PolicyServicesApi } from "@/api/client";
import { radius, space } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
const types = [
  "ADDRESS_CHANGE",
  "VEHICLE_CHANGE",
  "DRIVER_CHANGE",
  "CANCELLATION_REVIEW",
  "NO_CLAIMS_CERTIFICATE",
];
const TYPE_ICON: Record<string, LucideIcon> = {
  ADDRESS_CHANGE: MapPin,
  VEHICLE_CHANGE: CarFront,
  DRIVER_CHANGE: UserRound,
  CANCELLATION_REVIEW: FileX,
  NO_CLAIMS_CERTIFICATE: FileCheck,
};
export default function NewService() {
  const { t, td } = useTranslation();
  const [typeValue, setType] = useState(types[0]!);
  const [reason, setReason] = useState("");
  return (
    <Screen
      footer={
        <CtaBar>
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
        </CtaBar>
      }
    >
      <BrandHeader title={t("svcNewTitle")} subtitle={t("svcNewSubtitle")} back right="help" />
      <Card style={s.card}>
        <SectionHeading title={t("svcType")} />
        <View style={s.wrap} accessibilityRole="radiogroup">
          {types.map((x) => (
            <RadioCard key={x} selected={x === typeValue} onPress={() => setType(x)} icon={TYPE_ICON[x]} tint="blue" title={td(`svcType_${x}`, x)} style={s.option} />
          ))}
        </View>
      </Card>
      <Card style={s.card}>
        <TextField
          label={t("svcReason")}
          multiline
          value={reason}
          onChangeText={setReason}
          style={s.area}
          hint={t("minChars", { count: 10 })}
        />
      </Card>
    </Screen>
  );
}
const s = StyleSheet.create({
  card: { borderRadius: radius.feature },
  wrap: { gap: space.x2 },
  option: { padding: space.x3 },
  area: { minHeight: 120, textAlignVertical: "top", paddingTop: 12 },
});

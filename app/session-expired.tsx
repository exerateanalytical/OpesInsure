import React from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { TimerReset } from "lucide-react-native";
import { Button, Card, Screen } from "@/components/ui";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

export default function SessionExpired() {
  const { t } = useTranslation();
  return (
    <Screen>
      <Card feature>
        <TimerReset size={34} color={colors.warningText} />
        <Text accessibilityRole="header" style={styles.title}>{t("seTitle")}</Text>
        <Text style={styles.body}>{t("seBody")}</Text>
        <Text style={styles.body}>{t("seIdleNote")}</Text>
        <Button label={t("seSignIn")} onPress={() => router.replace("/(auth)/sign-in")} />
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.pageTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
});

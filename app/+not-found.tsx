import React from "react";
import { StyleSheet, Text } from "react-native";
import { router, Stack } from "expo-router";
import { Compass, Home } from "lucide-react-native";
import { Button, Card, Screen } from "@/components/ui";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

export default function NotFound() {
  const { t } = useTranslation();
  return (
    <Screen>
      <Stack.Screen options={{ headerShown: false }} />
      <Card feature>
        <Compass size={32} color={colors.blue600} />
        <Text accessibilityRole="header" style={styles.title}>{t("nfTitle")}</Text>
        <Text style={styles.body}>{t("nfBody")}</Text>
        <Button label={t("goHome")} icon={Home} onPress={() => router.replace("/")} />
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.sectionTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
});

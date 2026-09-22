import React from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { TimerReset } from "lucide-react-native";
import { Button, Card, Screen } from "@/components/ui";
import { colors, type } from "@/theme/tokens";

export default function SessionExpired() {
  return (
    <Screen>
      <Card feature>
        <TimerReset size={34} color={colors.warningText} />
        <Text accessibilityRole="header" style={styles.title}>Session expired</Text>
        <Text style={styles.body}>Sign in again to protect your information. No payment or insurance action was completed from the expired session.</Text>
        <Button label="Sign in securely" onPress={() => router.replace("/(auth)/sign-in")} />
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.pageTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
});

import React from "react";
import { router } from "expo-router";
import { Clock3 } from "lucide-react-native";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
export default function Referral() {
  return (
    <Screen>
      <AppHeader title="Underwriting review" back />
      <Card feature>
        <Clock3 size={32} />
        <StatusChip label="REFERRED" tone="warning" />
        <Text>
          Your answers need a human underwriter’s review. Your saved proposal
          remains valid; no payment will be requested until terms are approved.
        </Text>
        <Text>Expected response: within one business day.</Text>
      </Card>
      <Button
        label="Return to home"
        onPress={() => router.replace("/(customer)/(tabs)")}
      />
    </Screen>
  );
}

import React from "react";
import { StyleSheet, Text } from "react-native";
import { router, Stack } from "expo-router";
import { Compass, Home } from "lucide-react-native";
import { Button, Card, Screen } from "@/components/ui";
import { colors, type } from "@/theme/tokens";

export default function NotFound() {
  return (
    <Screen>
      <Stack.Screen options={{ headerShown: false }} />
      <Card feature>
        <Compass size={32} color={colors.blue600} />
        <Text style={styles.title}>Page not found</Text>
        <Text style={styles.body}>
          This link does not point to anything in the OpesInsure app. It may be
          out of date, or meant for the website.
        </Text>
        <Button label="Go home" icon={Home} onPress={() => router.replace("/")} />
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.sectionTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
});

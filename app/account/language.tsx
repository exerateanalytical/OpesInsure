import React, { useState } from "react";
import { Pressable, StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { AccountApi } from "@/api/client";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { useSession } from "@/store/session";
import { colors, type } from "@/theme/tokens";
export default function Language() {
  const current = useSession((s) => s.language);
  const setLanguage = useSession((s) => s.setLanguage);
  const [value, setValue] = useState<"en" | "fr">(current);
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true);
    try {
      await AccountApi.setLocale(value);
      setLanguage(value);
      router.back();
    } finally {
      setBusy(false);
    }
  };
  return (
    <Screen>
      <AppHeader title="Language" back />
      {(
        [
          ["en", "English"],
          ["fr", "Français"],
        ] as const
      ).map(([code, label]) => (
        <Pressable
          accessibilityRole="radio"
          accessibilityState={{ selected: value === code }}
          key={code}
          onPress={() => setValue(code)}
        >
          <Card style={value === code && styles.selected}>
            <Text style={styles.label}>{label}</Text>
          </Card>
        </Pressable>
      ))}
      <Button
        label="Save language"
        loading={busy}
        onPress={() => void save()}
      />
    </Screen>
  );
}
const styles = StyleSheet.create({
  selected: { borderColor: colors.blue600, backgroundColor: colors.blue50 },
  label: { ...type.label, color: colors.navy950 },
});

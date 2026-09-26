import React, { useState } from "react";
import { StyleSheet, View } from "react-native";
import { router } from "expo-router";
import { Languages } from "lucide-react-native";
import { AccountApi } from "@/api/client";
import { Button, Screen } from "@/components/ui";
import { BrandHeader, RadioCard } from "@/components/design";
import { useSession } from "@/store/session";
import { space } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
export default function Language() {
  const { t } = useTranslation();
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
      <BrandHeader title={t("language")} back right={null} />
      <View style={styles.options} accessibilityRole="radiogroup">
        {(
          [
            ["en", "English"],
            ["fr", "Français"],
          ] as const
        ).map(([code, label]) => (
          <RadioCard key={code} selected={value === code} onPress={() => setValue(code)} icon={Languages} tint={value === code ? "blue" : "neutral"} title={label} />
        ))}
      </View>
      <Button
        label={t("saveLanguage")}
        loading={busy}
        onPress={() => void save()}
      />
    </Screen>
  );
}
const styles = StyleSheet.create({
  options: { gap: space.x3 },
});

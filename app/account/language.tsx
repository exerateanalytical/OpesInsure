import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Bell, CheckCircle2, Circle, Languages } from "lucide-react-native";
import { AccountApi } from "@/api/client";
import { Button, Card, Screen } from "@/components/ui";
import { Banner, BrandHeader, CtaBar, SectionHeading } from "@/components/design";
import { useSession } from "@/store/session";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/**
 * Language & communication. The choice is saved to the server
 * (PUT /mobile/account/locale) and then applied to the whole app at once.
 * Channels and topics live on the notification-preferences screen.
 */
export default function Language() {
  const { t } = useTranslation();
  const current = useSession((s) => s.language);
  const setLanguage = useSession((s) => s.setLanguage);
  const [value, setValue] = useState<"en" | "fr">(current);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const save = async () => {
    setBusy(true);
    setError(null);
    setSaved(false);
    try {
      await AccountApi.setLocale(value);
      setLanguage(value);
      setSaved(true);
    } catch (e) {
      setError(e instanceof Error ? e.message : t("actionFailed"));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Screen
      footer={
        <CtaBar>
          <Button label={t("saveLanguage")} loading={busy} disabled={value === current} onPress={() => void save()} />
        </CtaBar>
      }
    >
      <BrandHeader title={t("langPageTitle")} subtitle={t("langPageSubtitle")} back />
      <SectionHeading title={t("language")} icon={Languages} />
      <Card style={styles.card}>
        <View style={styles.segment} accessibilityRole="radiogroup">
          {(
            [
              ["en", "English"],
              ["fr", "Français"],
            ] as const
          ).map(([code, label]) => {
            const on = value === code;
            const Icon = on ? CheckCircle2 : Circle;
            return (
              <Pressable
                key={code}
                accessibilityRole="radio"
                accessibilityState={{ checked: on }}
                accessibilityLabel={label}
                onPress={() => setValue(code)}
                style={[styles.option, on && styles.optionOn]}
              >
                <Text style={[styles.optionText, on && styles.optionTextOn]}>{label}</Text>
                <Icon size={22} color={on ? colors.white : colors.neutral500} />
              </Pressable>
            );
          })}
        </View>
      </Card>
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
      {saved ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{t("savedToAccount")}</Text> : null}
      <Banner icon={Bell} tint="blue" title={t("langNotifPrefs")} body={t("langChannelsBody")} onPress={() => router.push("/account/notifications")} />
    </Screen>
  );
}
const styles = StyleSheet.create({
  card: { borderRadius: radius.feature, padding: space.x2 },
  segment: { flexDirection: "row", gap: space.x2 },
  option: { flex: 1, minHeight: 48, borderRadius: radius.control, backgroundColor: colors.neutral100, flexDirection: "row", alignItems: "center", justifyContent: "space-between", paddingHorizontal: space.x4 },
  optionOn: { backgroundColor: colors.blue600 },
  optionText: { ...type.label, color: colors.navy950 },
  optionTextOn: { color: colors.white },
  error: { ...type.meta, color: colors.dangerText },
  notice: { ...type.meta, color: colors.successText },
});

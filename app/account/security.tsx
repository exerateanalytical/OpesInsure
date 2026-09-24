import React, { useEffect, useState } from "react";
import { Alert, StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import * as LocalAuthentication from "expo-local-authentication";
import * as SecureStore from "expo-secure-store";
import { Fingerprint, LogOut, Smartphone } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { useSession } from "@/store/session";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

const BIOMETRIC_KEY = "opesinsure.biometric_enabled";

export default function Security() {
  const { t } = useTranslation();
  const signOutEverywhere = useSession((s) => s.signOutEverywhere);
  const [enabled, setEnabled] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState<"bio" | "all" | null>(null);
  useEffect(() => {
    SecureStore.getItemAsync(BIOMETRIC_KEY)
      .then((v) => setEnabled(v === "true"))
      .catch(() => setEnabled(false));
  }, []);
  const change = async () => {
    setBusy("bio");
    setMessage(null);
    try {
      if (enabled) {
        await SecureStore.deleteItemAsync(BIOMETRIC_KEY);
        setEnabled(false);
        return;
      }
      const supported = await LocalAuthentication.hasHardwareAsync();
      const enrolled = await LocalAuthentication.isEnrolledAsync();
      if (!supported || !enrolled) {
        setMessage(t("biometricSetupFirst"));
        return;
      }
      const result = await LocalAuthentication.authenticateAsync({ promptMessage: t("biometricEnablePrompt") });
      if (result.success) {
        await SecureStore.setItemAsync(BIOMETRIC_KEY, "true");
        setEnabled(true);
      }
    } catch (e) {
      setMessage(e instanceof Error ? e.message : t("actionFailed"));
    } finally {
      setBusy(null);
    }
  };
  const everywhere = () =>
    Alert.alert(t("signOutAllTitle"), t("signOutAllConfirm"), [
      { text: t("cancel"), style: "cancel" },
      {
        text: t("signOutAll"),
        style: "destructive",
        onPress: async () => {
          setBusy("all");
          setMessage(null);
          try {
            await signOutEverywhere();
            router.replace("/(auth)/sign-in");
          } catch (e) {
            // Nothing was cleared locally: the user stays signed in and can retry.
            setMessage(e instanceof Error ? e.message : t("signOutAllFailed"));
          } finally {
            setBusy(null);
          }
        },
      },
    ]);
  return (
    <Screen>
      <AppHeader title={t("securityDevices")} back />
      <Card>
        <Fingerprint size={30} color={colors.blue600} />
        <StatusChip label={enabled ? t("biometricOn") : t("biometricOff")} tone={enabled ? "success" : "warning"} />
        <Text style={styles.body}>{t("biometricBody")}</Text>
        <Button label={enabled ? t("biometricDisable") : t("biometricEnable")} loading={busy === "bio"} onPress={() => void change()} />
      </Card>
      <Button label={t("reviewDevices")} icon={Smartphone} variant="secondary" onPress={() => router.push("/account/devices")} />
      <Card>
        <Text style={styles.title}>{t("signOutAllTitle")}</Text>
        <Text style={styles.body}>{t("signOutAllBody")}</Text>
        <Button label={t("signOutAll")} icon={LogOut} variant="danger" loading={busy === "all"} onPress={everywhere} />
      </Card>
      {message ? <Text accessibilityRole="alert" style={styles.error}>{message}</Text> : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
